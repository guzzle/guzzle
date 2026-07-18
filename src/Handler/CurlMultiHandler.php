<?php

declare(strict_types=1);

namespace GuzzleHttp\Handler;

use Closure;
use GuzzleHttp\Exception\HandlerClosedException;
use GuzzleHttp\Exception\InvalidArgumentException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\Multiplexing;
use GuzzleHttp\NonSerializableTrait;
use GuzzleHttp\Promise as P;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7;
use GuzzleHttp\TransportSharing;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Returns an asynchronous response using curl_multi_* functions.
 *
 * When using the CurlMultiHandler, custom curl options can be specified as an
 * associative array of curl option constants mapping to values in the
 * **curl** key of the provided request options.
 */
final class CurlMultiHandler
{
    use NonSerializableTrait;

    private const KNOWN_CONSTRUCTOR_OPTIONS = [
        'handle_factory' => true,
        'max_host_connections' => true,
        'max_total_connections' => true,
        'multiplex' => true,
        'options' => true,
        'select_timeout' => true,
        'transport_sharing' => true,
    ];

    private const CONNECTION_CAP_OPTIONS = [
        'max_host_connections' => 'CURLMOPT_MAX_HOST_CONNECTIONS',
        'max_total_connections' => 'CURLMOPT_MAX_TOTAL_CONNECTIONS',
    ];

    /**
     * cURL options that isolate a transfer from foreign proxy tunnel
     * connections. Failing to apply either one would fall open into
     * credential-bearing connection reuse.
     */
    private const PROXY_TUNNEL_ISOLATION_OPTIONS = [
        'CURLOPT_FRESH_CONNECT',
        'CURLOPT_FORBID_REUSE',
    ];

    private CurlFactoryInterface $factory;

    private bool $ownsFactory;

    private ?CurlShareHandleState $shareHandleState;

    private float $selectTimeout;

    /**
     * @var int Will be higher than 0 when `curl_multi_exec` is still running.
     */
    private int $active = 0;

    /**
     * @var array Request entry handles, indexed by handle id in `addRequest`.
     *
     * @see CurlMultiHandler::addRequest
     */
    private array $handles = [];

    /**
     * @var array<int, float> An array of delay times, indexed by handle id in `addRequest`.
     *
     * @see CurlMultiHandler::addRequest
     */
    private array $delays = [];

    /**
     * @var array<mixed> An associative array of CURLMOPT_* options and corresponding values for curl_multi_setopt()
     */
    private array $options = [];

    /**
     * @var bool Whether the "multiplex" constructor option disabled
     *           multiplexing on this handler's multi handle
     */
    private bool $multiplexDisabled = false;

    /**
     * @var resource|\CurlMultiHandle|null
     */
    private $multiHandle;

    private bool $closed = false;

    private bool $closing = false;

    /**
     * @var int Depth of nested guarded native operations (execution and
     *          handle removal, both of which can run user callbacks). A
     *          callback can re-enter tick(), and the nested frame must not
     *          clear the outer frame's guard; deferred work stays parked
     *          until the outermost frame unwinds.
     */
    private int $multiExecDepth = 0;

    /**
     * @var bool Guards finishDeferredWork() against re-entry from the
     *           guarded native removals it performs while flushing.
     */
    private bool $finishingDeferredWork = false;

    /**
     * @var array<int, array{easy: EasyHandle, attached: bool}>
     */
    private array $deferredCancels = [];

    /**
     * @var array<int, object|null> Wait tokens of requests created from inside
     *                              a cURL callback, keyed by handle id; native
     *                              attachment is deferred until the outermost
     *                              native execution unwinds.
     */
    private array $deferredAdds = [];

    private bool $deferredClose = false;

    private bool $deferredCloseExplicit = false;

    /**
     * @var string|null Owner signature of the proxy tunnels the multi handle's
     *                  connection cache may hold
     */
    private ?string $proxyTunnelOwner = null;

    /** @var array<string, int> Count of attached transfers per proxy tunnel signature. */
    private array $activeProxyTunnelSignatures = [];

    /** @var array<int, string> Maps an attached handle id to its proxy tunnel signature. */
    private array $activeProxyTunnelHandles = [];

    /**
     * @var int Depth of nested processMessages() calls. Guards against
     *          multi-handle recreation re-entrancy from processMessages (a
     *          retried transfer re-invokes the handler) and keeps deferred
     *          work parked until the outermost message loop unwinds.
     */
    private int $messageProcessingDepth = 0;

    /**
     * This handler accepts the following options:
     *
     * - handle_factory: An optional factory  used to create curl handles
     * - transport_sharing: Optional transport sharing mode.
     * - select_timeout: Optional timeout (in seconds) to block before timing
     *   out while selecting curl handles. Defaults to 1 second.
     * - max_host_connections: Optional maximum concurrent connections per host.
     * - max_total_connections: Optional maximum concurrent connections overall.
     * - multiplex: Optional Multiplexing::NONE to disallow multiplexing on
     *   this handler's multi handle. The eager, wait, and required modes are
     *   request options, not handler options; Multiplexing::NONE is also
     *   conditionally accepted as a request option value.
     * - options: An associative array of CURLMOPT_* options and
     *   corresponding values for curl_multi_setopt()
     */
    public function __construct(array $options = [])
    {
        foreach ($options as $name => $_) {
            if (!isset(self::KNOWN_CONSTRUCTOR_OPTIONS[$name])) {
                throw new InvalidArgumentException(\sprintf('Invalid CurlMultiHandler constructor option "%s".', Psr7\DiagnosticValue::escape((string) $name)));
            }
        }

        $handlerMultiplex = $options['multiplex'] ?? null;
        if (null !== $handlerMultiplex && Multiplexing::NONE !== $handlerMultiplex) {
            if (\in_array($handlerMultiplex, [Multiplexing::EAGER, Multiplexing::WAIT, Multiplexing::REQUIRE_EAGER, Multiplexing::REQUIRE_WAIT], true)) {
                throw new InvalidArgumentException('The "multiplex" CurlMultiHandler option only accepts Multiplexing::NONE; the eager, wait, and required modes are request options.');
            }

            throw new InvalidArgumentException(\sprintf('The "multiplex" CurlMultiHandler option must be null or Multiplexing::NONE; received %s.', \get_debug_type($handlerMultiplex)));
        }
        $this->multiplexDisabled = null !== $handlerMultiplex;

        if ($this->multiplexDisabled && !\defined('CURLMOPT_PIPELINING')) {
            // ext-curl only defines the constant when built against libcurl
            // 7.16 or newer headers, and such builds compile out the matching
            // curl_multi_setopt() case, so the guarantee cannot be applied.
            throw new InvalidArgumentException('The "multiplex" CurlMultiHandler option requires CURLMOPT_PIPELINING, but it is not available in the installed PHP cURL extension.');
        }

        CurlShareHandleState::assertNoRequiredSharingCustomFactoryConflict($options, 'CurlMultiHandler');
        $transportSharing = $options['transport_sharing'] ?? null;
        $sharingMode = CurlShareHandleState::normalizeMode($transportSharing, 'transport_sharing');

        $selectTimeout = $options['select_timeout'] ?? 1.0;
        Timeout::toMilliseconds($selectTimeout, 'select_timeout');
        $this->selectTimeout = (float) $selectTimeout;

        $multiOptions = $options['options'] ?? [];
        if (!\is_array($multiOptions)) {
            throw new InvalidArgumentException('options must be an array of cURL multi options');
        }

        $this->options = $multiOptions;
        self::rejectConflictingCurlMultiOptions($this->options);
        $this->addConnectionCapOptions($options);

        if ($this->multiplexDisabled) {
            // CURLPIPE_NOTHING; the constant itself needs libcurl 7.43
            // headers, newer than the oldest supported runtimes.
            $this->options[\CURLMOPT_PIPELINING] = 0;
        }

        $connectionCapOption = self::firstConnectionCapOption($options);
        if ($connectionCapOption !== null) {
            $persistentShareState = $transportSharing instanceof CurlShareHandleState
                && \in_array($sharingMode, [TransportSharing::PERSISTENT_PREFER, TransportSharing::PERSISTENT_REQUIRE], true);

            if ($persistentShareState || $sharingMode === TransportSharing::PERSISTENT_REQUIRE) {
                throw new InvalidArgumentException(\sprintf('%s cannot be combined with persistent transport sharing because libcurl does not reliably apply connection caps to shared connection pools.', $connectionCapOption));
            }

            if ($sharingMode === TransportSharing::PERSISTENT_PREFER) {
                // libcurl does not apply cURL multi connection caps to
                // transfers using a shared connection pool, so the best
                // honorable offer for preferred persistent sharing is a
                // handler-lifetime share.
                $transportSharing = TransportSharing::HANDLER_PREFER;
                $sharingMode = TransportSharing::HANDLER_PREFER;
            }
        }

        if (\array_key_exists('handle_factory', $options) && $options['handle_factory'] !== null) {
            $this->shareHandleState = null;
            $this->factory = $options['handle_factory'];
            $this->ownsFactory = false;
        } else {
            $this->shareHandleState = $sharingMode !== TransportSharing::NONE
                ? CurlShareHandleState::fromOption($transportSharing)
                : null;

            $this->factory = $this->shareHandleState !== null
                ? new CurlFactory(50, $this->shareHandleState->mode, $this->shareHandleState)
                : new CurlFactory(50);

            $this->ownsFactory = true;
        }
    }

    public function __destruct()
    {
        try {
            $this->doClose(false);
        } catch (\Throwable $e) {
            // Destructors must not throw.
        }
    }

    public function __unserialize(array $data): void
    {
        $this->closed = true;

        throw new \LogicException(static::class.' should never be unserialized');
    }

    /**
     * @return PromiseInterface<ResponseInterface, mixed>
     */
    public function __invoke(
        #[\SensitiveParameter]
        RequestInterface $request,
        #[\SensitiveParameter]
        array $options
    ): PromiseInterface {
        $this->assertOpen();

        $easy = $this->factory->create($request, $options);

        try {
            $this->rejectDisabledMultiplexConflict($easy, $options);
            $this->applyMultiplexNone($easy, $options);
            $this->applyProxyTunnelOwnership($easy);
        } catch (\Throwable $e) {
            try {
                $this->factory->release($easy);
            } catch (\Throwable $releaseFailure) {
                // Preserve the original failure.
            }

            throw $e;
        }

        $id = (int) $easy->handle;

        $waitToken = new \stdClass();

        /** @var Promise<ResponseInterface, mixed> $promise */
        $promise = new Promise(
            function () use ($id, $waitToken): void {
                if ($this->multiExecDepth > 0) {
                    // Waiting cannot drive native cURL while a callback has
                    // the multi handle busy; fail the wait promptly instead
                    // of self-deadlocking.
                    $this->failNestedWait($id, $waitToken);

                    return;
                }

                $this->executeUntil($id, $waitToken);
            },
            function () use ($id, $waitToken): void {
                $this->cancel($id, $waitToken);
            }
        );

        $entry = ['easy' => $easy, 'deferred' => $promise, 'wait_token' => $waitToken];
        try {
            $this->addRequest($entry);
        } catch (\Throwable $e) {
            throw $this->discardPendingRequest($id, $entry, $e);
        }

        return $promise;
    }

    /**
     * The "multiplex" request option sets CURLOPT_PIPEWAIT, which libcurl
     * ignores entirely when the multi handle disallows multiplexing, so an
     * explicit request for multiplexing on a handler configured with
     * Multiplexing::NONE is a configuration error. The required family
     * conflicts marker-independently: a required guarantee on such a handler
     * is contradictory even when the transfer would not wait. The default
     * WAIT mode adapts instead of conflicting: the handler option wins and
     * nothing waits.
     */
    private function rejectDisabledMultiplexConflict(
        #[\SensitiveParameter]
        EasyHandle $easy,
        #[\SensitiveParameter]
        array $options
    ): void {
        if (!$this->multiplexDisabled) {
            return;
        }

        $multiplex = $options['multiplex'] ?? null;

        if (Multiplexing::WAIT === $multiplex && !$easy->usesPipewait) {
            // Explicit wait only conflicts when the transfer would actually
            // wait; an HTTP/1.1 wait request never sets the marker.
            return;
        }

        if (!\in_array($multiplex, [Multiplexing::WAIT, Multiplexing::REQUIRE_EAGER, Multiplexing::REQUIRE_WAIT], true)) {
            return;
        }

        throw new InvalidArgumentException('The "multiplex" request option cannot be combined with a CurlMultiHandler whose "multiplex" option is Multiplexing::NONE; remove the handler option or set the request option to "eager".');
    }

    /**
     * A Multiplexing::NONE request option is a sole-use guarantee: the
     * transfer must not share its connection with any concurrent transfer.
     * It holds structurally on a handler whose "multiplex" option is
     * Multiplexing::NONE, and for HTTP/1.x transfers, which never join a
     * multiplexed connection and open connections nothing can join. An
     * HTTP/2 or HTTP/3 request on a handler that multiplexes is rejected,
     * as is any configuration under which the guarantee cannot be verified
     * (custom handle factories control the native handle), cannot be
     * hardened (challenge-response authentication retries and Expect
     * 417 retries re-enter connection selection as internal follows, which
     * disarm CURLOPT_FRESH_CONNECT), or must not be hardened (required
     * persistent sharing forbids fresh connections). On runtimes whose
     * matcher can hand an HTTP/1.x transfer an idle multiplexed connection
     * (below libcurl 7.77.0, and 8.11.0-8.12.1), accepted transfers force a
     * fresh connection.
     */
    private function applyMultiplexNone(
        #[\SensitiveParameter]
        EasyHandle $easy,
        #[\SensitiveParameter]
        array $options
    ): void {
        if (Multiplexing::NONE !== ($options['multiplex'] ?? null) || $this->multiplexDisabled) {
            return;
        }

        if (!$this->ownsFactory) {
            throw new InvalidArgumentException('The "multiplex" request option can only be Multiplexing::NONE on a CurlMultiHandler with a custom "handle_factory" when the handler\'s own "multiplex" option is Multiplexing::NONE, because the guarantee is enforced against the native easy handle the factory controls.');
        }

        $version = $easy->request->getProtocolVersion();
        if ('1.0' !== $version && '1.1' !== $version) {
            throw new InvalidArgumentException('The "multiplex" request option can only be Multiplexing::NONE for an HTTP/1.x request on a CurlMultiHandler that permits multiplexing; set the "multiplex" client or CurlMultiHandler constructor option to Multiplexing::NONE to disable multiplexing for every transfer, or send the request with its "version" option set to "1.1".');
        }

        if (null !== $this->shareHandleState && TransportSharing::PERSISTENT_REQUIRE === $this->shareHandleState->mode) {
            // The matcher-window hardening below may force a fresh
            // connection, which required persistent sharing forbids because
            // it disables connection reuse (CurlFactory applies the same
            // rule to the raw option); rejected on every runtime so
            // acceptance stays version-independent.
            throw new InvalidArgumentException('The "multiplex" request option cannot be Multiplexing::NONE on a CurlMultiHandler that permits multiplexing and requires persistent transport sharing; set the "multiplex" client or CurlMultiHandler constructor option to Multiplexing::NONE, or use TransportSharing::PERSISTENT_PREFER.');
        }

        if (\defined('CURLOPT_HTTPAUTH')
            && isset($options['curl'])
            && \is_array($options['curl'])
            && \array_key_exists((int) \constant('CURLOPT_HTTPAUTH'), $options['curl'])
        ) {
            // Key presence alone conflicts: challenge-response retries are
            // libcurl-internal follows, which disarm CURLOPT_FRESH_CONNECT
            // (reuse_fresh && !this_is_a_follow), so the hardening below
            // cannot cover them.
            throw new InvalidArgumentException('The "multiplex" request option cannot be Multiplexing::NONE combined with the raw CURLOPT_HTTPAUTH cURL option on a CurlMultiHandler that permits multiplexing; remove the raw option, or set the "multiplex" client or CurlMultiHandler constructor option to Multiplexing::NONE.');
        }

        if (Psr7\Utils::caselessContains($easy->request->getHeaderLine('Expect'), '100-continue')) {
            // libcurl arms its Expect handling by a caseless substring scan
            // of the header value (Curl_compareheader), so any value
            // containing 100-continue can make a 417 response retry as an
            // internal follow, which disarms CURLOPT_FRESH_CONNECT; requests
            // without the header are safe because the factory suppresses
            // libcurl's automatic Expect.
            throw new InvalidArgumentException('The "multiplex" request option cannot be Multiplexing::NONE for a request carrying an "Expect: 100-continue" header on a CurlMultiHandler that permits multiplexing; remove the explicitly supplied "Expect" header, set the "expect" request option to false to prevent it being added automatically, or set the "multiplex" client or CurlMultiHandler constructor option to Multiplexing::NONE.');
        }

        if (CurlVersion::supportsHttpVersionReuseMatching()) {
            return;
        }

        // Unqualified curl_setopt so the test bootstrap shadow records it.
        if (true !== curl_setopt($easy->handle, \CURLOPT_FRESH_CONNECT, true)) {
            // The hardening is the guarantee on these runtimes; failing to
            // apply it must fail closed, mirroring applyCurlOptions().
            throw new InvalidArgumentException('Unable to set cURL option CURLOPT_FRESH_CONNECT.');
        }
    }

    /**
     * @param array<mixed> $options
     */
    private static function rejectConflictingCurlMultiOptions(array $options): void
    {
        if ($options === []) {
            return;
        }

        $conflictingOptions = self::conflictingCurlMultiOptions();
        foreach ($options as $option => $_) {
            if (\array_key_exists($option, $conflictingOptions)) {
                throw new InvalidArgumentException(\sprintf('Passing %s in the cURL multi handler "options" is not supported. Use %s instead.', self::formatCurlMultiOption($option), $conflictingOptions[$option]));
            }
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function firstConnectionCapOption(array $options): ?string
    {
        foreach (self::CONNECTION_CAP_OPTIONS as $name => $_) {
            if (($options[$name] ?? null) !== null) {
                return $name;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function addConnectionCapOptions(array $options): void
    {
        foreach (self::CONNECTION_CAP_OPTIONS as $name => $constant) {
            $value = $options[$name] ?? null;
            if ($value === null) {
                continue;
            }

            if (!\is_int($value) || $value < 1) {
                throw new InvalidArgumentException(\sprintf('%s must be a positive integer.', $name));
            }

            if (!\defined($constant)) {
                throw new InvalidArgumentException(\sprintf('%s requires %s, but it is not available in the installed PHP cURL extension.', $name, $constant));
            }

            $option = \constant($constant);
            if (\array_key_exists($option, $this->options)) {
                throw new InvalidArgumentException(\sprintf('%s conflicts with a %s entry in the "options" array.', $name, $constant));
            }

            $this->options[$option] = $value;
        }
    }

    /**
     * @param int|string $option
     */
    private static function formatCurlMultiOption($option): string
    {
        if (!\is_int($option)) {
            return \sprintf('"%s"', Psr7\DiagnosticValue::escape((string) $option));
        }

        static $names = null;

        if (null === $names) {
            $names = [];
            foreach (\get_defined_constants(true)['curl'] ?? [] as $name => $value) {
                if (\is_int($value) && \strpos($name, 'CURLMOPT_') === 0 && !isset($names[$value])) {
                    $names[$value] = $name;
                }
            }
        }

        if (isset($names[$option])) {
            return \sprintf('%s (%d)', $names[$option], $option);
        }

        return (string) $option;
    }

    /**
     * @return array<int, string>
     */
    private static function conflictingCurlMultiOptions(): array
    {
        static $options = null;

        if ($options !== null) {
            return $options;
        }

        $options = [];

        self::addConflictingCurlMultiOption($options, 'CURLMOPT_MAX_HOST_CONNECTIONS', 'the "max_host_connections" client option or cURL multi handler option');
        self::addConflictingCurlMultiOption($options, 'CURLMOPT_MAX_TOTAL_CONNECTIONS', 'the "max_total_connections" client option or cURL multi handler option');
        self::addConflictingCurlMultiOption($options, 'CURLMOPT_PIPELINING', 'Multiplexing::NONE via the "multiplex" cURL multi handler or client option to disable multiplexing, or remove the raw option for the runtime default (multiplexing defaults on from libcurl 7.62, except 7.65.0 and 7.65.1)');

        return $options;
    }

    /**
     * @param array<int, string> $options
     */
    private static function addConflictingCurlMultiOption(array &$options, string $constant, string $replacement): void
    {
        if (!\defined($constant)) {
            return;
        }

        $value = \constant($constant);
        if (\is_int($value)) {
            $options[$value] = $replacement;
        }
    }

    /**
     * Isolates the connection cache when the request's proxy tunnel section
     * differs from the one the multi handle's cache may already hold.
     */
    private function applyProxyTunnelOwnership(
        #[\SensitiveParameter]
        EasyHandle $easy
    ): void {
        $signature = $easy->proxyTunnelSignature;
        if ($signature === null || $signature === $this->proxyTunnelOwner) {
            return;
        }

        if ($this->proxyTunnelOwner === null) {
            // No in-domain transfer has ever run on this multi handle: latch
            // the owner without destroying pooled direct connections.
            $this->proxyTunnelOwner = $signature;

            return;
        }

        if (
            $this->handles === []
            && 0 === $this->multiExecDepth
            && 0 === $this->messageProcessingDepth
            && $this->deferredCancels === []
            && !$this->deferredClose
        ) {
            // Idle: hand the connection cache over by recreating the multi
            // handle. getMultiHandle() lazily re-initializes it (re-applying
            // the CURLMOPT_* options) on the next access.
            if ($this->multiHandle !== null) {
                \curl_multi_close($this->multiHandle);
                $this->multiHandle = null;
            }
            $this->proxyTunnelOwner = $signature;

            return;
        }

        // Busy: isolate this transfer from the owner's pooled tunnels.
        $this->isolateProxyTunnelTransfer($easy);
    }

    private function addHandleToMulti(
        int $id,
        #[\SensitiveParameter]
        EasyHandle $easy
    ): void {
        $this->isolateFromForeignActiveProxyTunnel($easy);

        $multiHandle = $this->getMultiHandle();

        // Unqualified curl_multi_add_handle so the test bootstrap shadow can
        // override the result.
        $result = curl_multi_add_handle($multiHandle, $easy->handle);

        if (\CURLM_OK !== $result) {
            if (\PHP_VERSION_ID < 80226 || (\PHP_VERSION_ID >= 80300 && \PHP_VERSION_ID < 80314)) {
                // Before PHP 8.2.26 and 8.3.14, ext-curl kept the easy handle
                // in its multi bookkeeping even when the native add failed
                // (https://github.com/php/php-src/pull/16302); remove it so
                // the handle can be disposed safely.
                \curl_multi_remove_handle($multiHandle, $easy->handle);
            }

            throw new RequestException(\sprintf('Unable to add the cURL handle to the cURL multi handler: %s (%d).', (string) \curl_multi_strerror($result), $result), $easy->request);
        }

        $this->markProxyTunnelActive($id, $easy);

        if (isset($this->handles[$id])) {
            $this->handles[$id]['attached'] = true;
        }
    }

    private function isolateFromForeignActiveProxyTunnel(
        #[\SensitiveParameter]
        EasyHandle $easy
    ): void {
        $signature = $easy->proxyTunnelSignature;

        if ($signature === null || $this->activeProxyTunnelSignatures === []) {
            return;
        }

        if (\count($this->activeProxyTunnelSignatures) === 1 && isset($this->activeProxyTunnelSignatures[$signature])) {
            return;
        }

        $this->isolateProxyTunnelTransfer($easy);
    }

    private function isolateProxyTunnelTransfer(
        #[\SensitiveParameter]
        EasyHandle $easy
    ): void {
        foreach (self::PROXY_TUNNEL_ISOLATION_OPTIONS as $name) {
            try {
                // Unqualified curl_setopt so the test bootstrap shadow records it.
                $applied = curl_setopt($easy->handle, (int) \constant($name), true);
            } catch (\Throwable $e) {
                throw new RequestException(self::proxyTunnelIsolationFailureMessage($name), $easy->request, 0, $e);
            }

            if (true !== $applied) {
                throw new RequestException(self::proxyTunnelIsolationFailureMessage($name), $easy->request);
            }
        }
    }

    private static function proxyTunnelIsolationFailureMessage(string $name): string
    {
        return \sprintf('Unable to apply the %s cURL option required to isolate the transfer from foreign proxy tunnel connections.', $name);
    }

    private function markProxyTunnelActive(int $id, EasyHandle $easy): void
    {
        $signature = $easy->proxyTunnelSignature;
        if ($signature === null) {
            return;
        }

        if (isset($this->activeProxyTunnelHandles[$id])) {
            if ($this->activeProxyTunnelHandles[$id] === $signature) {
                return;
            }

            $this->unmarkProxyTunnelActiveById($id);
        }

        $this->activeProxyTunnelHandles[$id] = $signature;
        $this->activeProxyTunnelSignatures[$signature] = ($this->activeProxyTunnelSignatures[$signature] ?? 0) + 1;
    }

    private function unmarkProxyTunnelActiveById(int $id): void
    {
        if (!isset($this->activeProxyTunnelHandles[$id])) {
            return;
        }

        $signature = $this->activeProxyTunnelHandles[$id];
        unset($this->activeProxyTunnelHandles[$id]);

        if (!isset($this->activeProxyTunnelSignatures[$signature])) {
            return;
        }

        --$this->activeProxyTunnelSignatures[$signature];

        if ($this->activeProxyTunnelSignatures[$signature] <= 0) {
            unset($this->activeProxyTunnelSignatures[$signature]);
        }
    }

    /**
     * Ticks the curl event loop.
     */
    public function tick(): void
    {
        $this->tickFor(null, null);
    }

    /**
     * Ticks the curl event loop, returning before the blocking select if the
     * targeted transfer has settled, been canceled, or been replaced by a
     * request that reused its native handle ID.
     */
    private function tickFor(?int $targetId, ?object $waitToken): void
    {
        $this->assertOpen();

        // Add any delayed handles if needed. Attachment is skipped while a
        // callback has native execution busy; the outer frame attaches due
        // transfers once it unwinds.
        if ($this->delays && 0 === $this->multiExecDepth) {
            $currentTime = Clock::now();
            foreach ($this->delays as $id => $delay) {
                if ($currentTime >= $delay) {
                    $entry = $this->handles[$id];
                    unset($this->delays[$id]);

                    try {
                        $this->addHandleToMulti($id, $entry['easy']);
                    } catch (\Throwable $e) {
                        $rejection = $this->discardPendingRequest($id, $entry, $e);
                        if (P\Is::pending($entry['deferred'])) {
                            $entry['deferred']->reject($rejection);
                        }
                    }
                }
            }
        }

        // Run curl_multi_exec in the queue to enable other async tasks to
        // run, surface completions, and drain any work they queued so a
        // ready cancellation or new transfer is not held behind the select.
        do {
            P\Utils::queue()->add(Closure::fromCallable([$this, 'tickInQueue']));

            // Step through the task queue which may add additional requests.
            P\Utils::queue()->run();

            if ($this->multiExecDepth > 0) {
                // A cURL callback re-entered the handler while native
                // execution is running; the outer frame drives native cURL
                // once it unwinds.
                return;
            }

            if ($this->closed || $this->closing || !$this->hasMultiHandle()) {
                return;
            }

            $this->processMessages();

            if ($this->closed || $this->closing || !$this->hasMultiHandle()) {
                return;
            }
        } while (!P\Utils::queue()->isEmpty());

        if ($targetId !== null && !$this->hasRequest($targetId, $waitToken)) {
            return;
        }

        if ($this->active && \curl_multi_select($this->getMultiHandle(), $this->effectiveSelectTimeout()) === -1) {
            // Perform a usleep if a select returns -1.
            // See: https://bugs.php.net/bug.php?id=61141
            \usleep(250);
        }

        do {
            $exec = $this->executeMulti();

            if ($this->closed || $this->closing || !$this->hasMultiHandle()) {
                return;
            }

            // Prevent busy looping for slow HTTP requests.
            if ($exec === \CURLM_CALL_MULTI_PERFORM) {
                \curl_multi_select($this->getMultiHandle(), $this->effectiveSelectTimeout());
            }
        } while ($exec === \CURLM_CALL_MULTI_PERFORM);

        $this->processMessages();
    }

    /**
     * Runs \curl_multi_exec() inside the event loop, to prevent busy looping
     */
    private function tickInQueue(): void
    {
        if ($this->multiExecDepth > 0) {
            // A cURL callback re-entered the handler while native execution
            // is running; the outer frame drives native cURL once it unwinds.
            return;
        }

        if ($this->closed || $this->closing || !$this->hasMultiHandle()) {
            return;
        }

        $exec = $this->executeMulti();

        if ($this->closed || $this->closing || !$this->hasMultiHandle()) {
            return;
        }

        if ($exec === \CURLM_CALL_MULTI_PERFORM) {
            \curl_multi_select($this->getMultiHandle(), 0);
            P\Utils::queue()->add(Closure::fromCallable([$this, 'tickInQueue']));
        }
    }

    /**
     * Runs until all outstanding connections have completed.
     */
    public function execute(): void
    {
        $this->assertOpen();

        if ($this->multiExecDepth > 0) {
            // Native cURL cannot be driven while a callback has it busy, so
            // the loop would spin without ever progressing.
            throw new \LogicException('Cannot run the cURL multi event loop from inside a cURL callback; the callback must return before transfers can progress.');
        }

        $queue = P\Utils::queue();

        while (!$this->closed && !$this->closing && ($this->handles || !$queue->isEmpty())) {
            // If there are no transfers, then sleep for the next delay,
            // unless ready queue work could change what is pending.
            if (!$this->active && $this->delays && $queue->isEmpty()) {
                \usleep($this->timeToNext());
            }
            $this->tick();
        }
    }

    /**
     * Runs the event loop until the given transfer has finished, so waiting
     * on a promise does not wait for every other transfer on the handler
     * like execute() does.
     *
     * The native cURL handle ID can be reused by a request created from a
     * completion callback, so the wait token guards against waiting on an
     * unrelated transfer that inherited the ID.
     */
    private function executeUntil(int $id, object $waitToken): void
    {
        $this->assertOpen();

        $queue = P\Utils::queue();

        while (
            !$this->closed
            && !$this->closing
            && $this->hasRequest($id, $waitToken)
        ) {
            // If the transfer is delayed, then sleep until it is due, unless
            // ready queue work could cancel or replace it first.
            if (!$this->active && isset($this->delays[$id]) && $queue->isEmpty()) {
                \usleep($this->timeToNext());
            }
            $this->tickFor($id, $waitToken);
        }

        if (!$this->closed && !$this->closing && !$queue->isEmpty()) {
            $queue->run();
        }
    }

    /**
     * Checks that the request with the given handle ID is still pending and,
     * when a wait token is given, has not been replaced by a request that
     * reused the ID.
     */
    private function hasRequest(int $id, ?object $waitToken = null): bool
    {
        if (!isset($this->handles[$id])) {
            return false;
        }

        return $waitToken === null || ($this->handles[$id]['wait_token'] ?? null) === $waitToken;
    }

    /**
     * Closes native cURL resources owned by this handler.
     *
     * Pending transfers are rejected with HandlerClosedException. After
     * closing, the handler is terminal and must not be reused.
     */
    public function close(): void
    {
        $this->doClose(true);
    }

    private function assertOpen(): void
    {
        if ($this->closed || $this->closing) {
            // Programmer misuse (reusing a closed handler), not a transfer failure;
            // intentionally a LogicException outside the GuzzleException hierarchy.
            throw new \BadMethodCallException('Cannot use the cURL multi handler after it has been closed.');
        }
    }

    private function doClose(bool $explicit): void
    {
        if ($this->closed || $this->closing) {
            return;
        }

        $this->closing = true;
        $failure = null;

        if ($this->multiExecDepth > 0 || $this->messageProcessingDepth > 0) {
            $this->deferClose($explicit, $failure);

            if ($explicit && $failure !== null) {
                throw $failure;
            }

            return;
        }

        try {
            $this->cleanupPendingTransfers($explicit, $failure);
            $this->closeMultiHandle($failure);
            $this->closeOwnedFactory($failure);
        } finally {
            $this->finishClose();
        }

        if ($explicit && $failure !== null) {
            throw $failure;
        }
    }

    private function deferClose(bool $explicit, ?\Throwable &$failure): void
    {
        $this->deferredClose = true;
        $this->deferredCloseExplicit = $this->deferredCloseExplicit || $explicit;

        $entries = $this->handles;

        $this->handles = [];
        $this->delays = [];
        $this->deferredAdds = [];

        foreach ($entries as $id => $entry) {
            $this->deferredCancels[$id] = [
                'easy' => $entry['easy'],
                'attached' => !empty($entry['attached']),
            ];

            if ($explicit && P\Is::pending($entry['deferred'])) {
                $this->captureFailure($failure, function () use ($entry): void {
                    $entry['deferred']->reject(new HandlerClosedException('The cURL multi handler was closed before the transfer completed.', $entry['easy']->request));
                });
            }
        }
    }

    private function finishClose(): void
    {
        $this->handles = [];
        $this->delays = [];
        $this->deferredCancels = [];
        $this->deferredAdds = [];
        $this->activeProxyTunnelSignatures = [];
        $this->activeProxyTunnelHandles = [];
        $this->active = 0;
        $this->shareHandleState = null;
        $this->deferredClose = false;
        $this->deferredCloseExplicit = false;
        $this->closed = true;
        $this->closing = false;
    }

    private function captureFailure(?\Throwable &$failure, callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            if ($failure === null) {
                $failure = $e;
            }
        }
    }

    /**
     * @param array{easy: EasyHandle, deferred: Promise<ResponseInterface, mixed>, wait_token: object} $entry
     */
    private function discardPendingRequest(int $id, array $entry, \Throwable $failure): \Throwable
    {
        unset($this->handles[$id], $this->delays[$id], $this->deferredAdds[$id]);

        try {
            $this->disposeEasyHandle($entry['easy']);
        } catch (\Throwable $e) {
            // Preserve the original attach failure.
        }

        return $failure;
    }

    private function cleanupPendingTransfers(bool $reject, ?\Throwable &$failure): void
    {
        $entries = $this->handles;

        $this->handles = [];
        $this->delays = [];
        $this->deferredAdds = [];

        foreach ($entries as $id => $entry) {
            $easy = $entry['easy'];
            $attached = !empty($entry['attached']);

            if ($attached && $this->hasMultiHandle() && self::hasEasyHandle($easy)) {
                $this->captureFailure($failure, function () use ($id, $easy): void {
                    $this->removeHandleFromMulti($id, $easy->handle);
                });
            }

            if ($reject && P\Is::pending($entry['deferred'])) {
                $this->captureFailure($failure, function () use ($entry): void {
                    $entry['deferred']->reject(new HandlerClosedException('The cURL multi handler was closed before the transfer completed.', $entry['easy']->request));
                });
            }

            $this->captureFailure($failure, function () use ($easy): void {
                $this->disposeEasyHandle($easy);
            });
        }
    }

    private function closeMultiHandle(?\Throwable &$failure): void
    {
        if ($this->multiHandle === null) {
            return;
        }

        $multiHandle = $this->multiHandle;

        $this->captureFailure($failure, function () use ($multiHandle): void {
            try {
                \curl_multi_close($multiHandle);
            } finally {
                $this->multiHandle = null;
            }
        });
    }

    private function closeOwnedFactory(?\Throwable &$failure): void
    {
        $factory = $this->factory;
        if (!$this->ownsFactory || !$factory instanceof CurlFactory) {
            return;
        }

        $this->captureFailure($failure, static function () use ($factory): void {
            $factory->close();
        });
    }

    /**
     * @phpstan-impure
     */
    private function executeMulti(): int
    {
        ++$this->multiExecDepth;

        try {
            return \curl_multi_exec($this->getMultiHandle(), $this->active);
        } finally {
            --$this->multiExecDepth;
            $this->finishDeferredWork();
        }
    }

    /**
     * Flushes attachments deferred while the multi handle was busy executing
     * transfers or removing a handle, and cancels and a close deferred while
     * it was executing transfers or processing completion messages.
     */
    private function finishDeferredWork(): void
    {
        if ($this->multiExecDepth > 0 || $this->finishingDeferredWork) {
            // A nested frame (a completion callback re-entered the handler)
            // must not flush while an outer frame is still using the multi
            // handle; the outermost frame flushes once it unwinds.
            return;
        }

        $this->finishingDeferredWork = true;

        try {
            if (!$this->closed && !$this->closing) {
                // Deferred adds only need native execution to be idle; a
                // completion callback may drive and wait on them while the
                // outermost message loop is still active.
                $this->flushDeferredAdds();
            }

            if ($this->messageProcessingDepth > 0) {
                // Cancels and a deferred close must wait for the outermost
                // message loop to unwind.
                return;
            }

            $failure = null;

            // Removing a cancelled transfer runs its final progress update,
            // whose callback can cancel other transfers, create requests, or
            // close the handler; drain until no deferred work remains.
            do {
                $this->cleanupDeferredCancels($failure);

                if (!$this->closed && !$this->closing) {
                    $this->flushDeferredAdds();
                }
            } while ($this->deferredCancels !== [] || (!$this->closed && !$this->closing && $this->deferredAdds !== []));

            if ($this->deferredClose) {
                $explicit = $this->deferredCloseExplicit;

                try {
                    $this->closeMultiHandle($failure);
                    $this->closeOwnedFactory($failure);
                } finally {
                    $this->finishClose();
                }

                if ($explicit && $failure !== null) {
                    throw $failure;
                }

                return;
            }

            if ($failure !== null) {
                throw $failure;
            }
        } finally {
            $this->finishingDeferredWork = false;
        }
    }

    /**
     * Attaches requests whose native attachment was deferred because they
     * were created from inside a cURL callback.
     */
    private function flushDeferredAdds(): void
    {
        if ($this->deferredAdds === []) {
            return;
        }

        $adds = $this->deferredAdds;
        $this->deferredAdds = [];

        foreach ($adds as $id => $token) {
            if (!$this->hasRequest($id, $token)) {
                // Cancelled or replaced while the attachment was deferred.
                continue;
            }

            $entry = $this->handles[$id];

            try {
                $this->addHandleToMulti($id, $entry['easy']);
            } catch (\Throwable $e) {
                // The promise has already escaped, so reject it rather than
                // throw. User code may have settled it directly; a settled
                // promise must not abort the rest of the snapshot.
                $rejection = $this->discardPendingRequest($id, $entry, $e);
                if (P\Is::pending($entry['deferred'])) {
                    $entry['deferred']->reject($rejection);
                }
            }
        }
    }

    /**
     * Fails a synchronous wait attempted from inside a cURL callback, where
     * native execution cannot progress until the callback returns.
     */
    private function failNestedWait(int $id, object $token): void
    {
        if (!$this->hasRequest($id, $token)) {
            return;
        }

        $entry = $this->handles[$id];
        $message = 'Cannot synchronously wait for a transfer from inside a cURL callback on the same cURL multi handler; the callback must return before the transfer can progress.';
        $response = $entry['easy']->response;
        $failure = $response !== null
            ? new ResponseException($message, $entry['easy']->request, $response)
            : new RequestException($message, $entry['easy']->request);

        if (!empty($entry['attached'])) {
            // Native removal must wait until the outermost execution unwinds.
            unset($this->handles[$id], $this->delays[$id], $this->deferredAdds[$id]);
            $this->deferredCancels[$id] = ['easy' => $entry['easy'], 'attached' => true];
        } else {
            $this->discardPendingRequest($id, $entry, $failure);
        }

        $entry['deferred']->reject($failure);
    }

    private function disposeEasyHandle(
        #[\SensitiveParameter]
        EasyHandle $easy
    ): void {
        if (!self::hasEasyHandle($easy)) {
            return;
        }

        $handle = $easy->handle;
        unset($easy->handle);

        $failure = null;

        try {
            $this->clearEasyHandleCallbacks($handle);
        } catch (\Throwable $e) {
            $failure = $e;
        }

        try {
            if (PHP_VERSION_ID < 80000 && \is_resource($handle)) {
                \curl_close($handle);
            }
        } catch (\Throwable $e) {
            if ($failure === null) {
                $failure = $e;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * @param resource|\CurlHandle $handle
     */
    private function clearEasyHandleCallbacks($handle): void
    {
        curl_setopt($handle, \CURLOPT_HEADERFUNCTION, null);
        curl_setopt($handle, \CURLOPT_READFUNCTION, null);
        curl_setopt($handle, \CURLOPT_WRITEFUNCTION, null);
        curl_setopt($handle, \CURLOPT_PROGRESSFUNCTION, null);

        if (\defined('CURLOPT_PREREQFUNCTION')) {
            curl_setopt($handle, (int) \constant('CURLOPT_PREREQFUNCTION'), null);
        }

        if (\defined('CURLOPT_XFERINFOFUNCTION')) {
            curl_setopt($handle, (int) \constant('CURLOPT_XFERINFOFUNCTION'), null);
        }
    }

    /**
     * @param resource|\CurlHandle $handle
     */
    private function removeHandleFromMulti(int $id, $handle): void
    {
        // Removing a still-running transfer performs a final progress update
        // that can run a user progress callback, so removal is guarded like
        // native execution.
        ++$this->multiExecDepth;

        try {
            \curl_multi_remove_handle($this->getMultiHandle(), $handle);
        } finally {
            --$this->multiExecDepth;
            $this->unmarkProxyTunnelActiveById($id);
            $this->finishDeferredWork();
        }
    }

    private function hasMultiHandle(): bool
    {
        return $this->multiHandle !== null;
    }

    private static function hasEasyHandle(EasyHandle $easy): bool
    {
        return \array_key_exists('handle', \get_object_vars($easy));
    }

    private function addRequest(
        #[\SensitiveParameter]
        array $entry
    ): void {
        $easy = $entry['easy'];
        $id = (int) $easy->handle;
        $entry['attached'] = false;
        $this->handles[$id] = $entry;

        if (!empty($easy->options['delay'])) {
            $this->delays[$id] = Clock::now() + ($easy->options['delay'] / 1000);
        } elseif ($this->multiExecDepth > 0) {
            // A request created from inside a cURL callback cannot be added
            // natively while curl_multi_exec() is running; libcurl 7.59+
            // rejects the recursive call. Attach it once the outermost
            // native execution unwinds.
            $this->deferredAdds[$id] = $entry['wait_token'] ?? null;
        } else {
            $this->addHandleToMulti($id, $easy);
        }
    }

    /**
     * Cancels a handle from sending and removes references to it.
     *
     * @param int         $id        Handle ID to cancel and remove.
     * @param object|null $waitToken Identity token that must still match the
     *                               entry when given.
     *
     * @return bool True on success, false on failure.
     */
    private function cancel(int $id, ?object $waitToken = null): bool
    {
        // Cannot cancel if it has been processed or replaced by a request
        // that reused the native handle ID.
        if (!$this->hasRequest($id, $waitToken)) {
            return false;
        }

        $entry = $this->handles[$id];
        $easy = $entry['easy'];
        $attached = !empty($entry['attached']);
        unset($this->delays[$id], $this->deferredAdds[$id], $this->handles[$id]);

        if ($this->multiExecDepth > 0) {
            $this->deferredCancels[$id] = ['easy' => $easy, 'attached' => $attached];

            return true;
        }

        $failure = null;

        if ($attached && $this->hasMultiHandle() && self::hasEasyHandle($easy)) {
            $this->captureFailure($failure, function () use ($id, $easy): void {
                $this->removeHandleFromMulti($id, $easy->handle);
            });
        }

        $this->captureFailure($failure, function () use ($easy): void {
            $this->disposeEasyHandle($easy);
        });

        if ($failure !== null) {
            throw $failure;
        }

        return true;
    }

    private function cleanupDeferredCancels(?\Throwable &$failure): void
    {
        if ($this->deferredCancels === []) {
            return;
        }

        $entries = $this->deferredCancels;
        $this->deferredCancels = [];

        foreach ($entries as $id => $entry) {
            $easy = $entry['easy'];

            if ($entry['attached'] && $this->hasMultiHandle() && self::hasEasyHandle($easy)) {
                $this->captureFailure($failure, function () use ($id, $easy): void {
                    $this->removeHandleFromMulti($id, $easy->handle);
                });
            }

            $this->captureFailure($failure, function () use ($easy): void {
                $this->disposeEasyHandle($easy);
            });
        }
    }

    private function processMessages(): void
    {
        // CurlFactory::finish can retry a transfer by re-invoking this handler
        // from inside this loop; the guard keeps that re-entry from recreating
        // the multi handle mid-iteration (see applyProxyTunnelOwnership). A
        // depth is tracked because a completion callback can re-enter tick(),
        // and the nested frame must not clear the outer loop's guard.
        ++$this->messageProcessingDepth;

        try {
            // A completion callback may close the handler mid-loop; the close
            // is deferred (closing is set first), and the loop must stop
            // before touching the multi handle again. Remaining in-flight
            // transfers were moved to the deferred cancels by deferClose().
            while (!$this->closed && !$this->closing) {
                $done = \curl_multi_info_read($this->getMultiHandle());
                if (false === $done) {
                    break;
                }

                if ($done['msg'] !== \CURLMSG_DONE) {
                    // If it is not done, removing the handle would be premature.
                    // See https://github.com/guzzle/guzzle/pull/2892#issuecomment-945150216.
                    continue;
                }
                if (!isset($done['handle'])) {
                    // Work around a PHP issue where cancelled transfers may omit the handle.
                    // Remove this once we no longer support PHP versions before the fix in
                    // https://github.com/php/php-src/pull/16302.
                    continue;
                }
                $id = (int) $done['handle'];
                $this->removeHandleFromMulti($id, $done['handle']);

                if (!isset($this->handles[$id])) {
                    // Probably was cancelled.
                    continue;
                }

                $entry = $this->handles[$id];
                unset($this->handles[$id], $this->delays[$id]);
                $entry['easy']->errno = $done['result'];

                // finish() can run completion callbacks that cancel this
                // promise; a settled promise must not be settled again.
                try {
                    $result = CurlFactory::finish($this, $entry['easy'], $this->factory);
                } catch (\Throwable $e) {
                    if (P\Is::pending($entry['deferred'])) {
                        $entry['deferred']->reject($e);
                    }

                    continue;
                }

                if (P\Is::pending($entry['deferred'])) {
                    $entry['deferred']->resolve($result);
                }
            }
        } finally {
            --$this->messageProcessingDepth;
            $this->finishDeferredWork();
        }
    }

    /**
     * Bounds a blocking select by the earliest pending request delay so a
     * delayed transfer becoming due does not wait out an unrelated
     * transfer's full select timeout.
     */
    private function effectiveSelectTimeout(): float
    {
        if ($this->delays === []) {
            return $this->selectTimeout;
        }

        return \min($this->selectTimeout, $this->secondsToNext());
    }

    /**
     * @return float Seconds until the earliest pending delay is due
     */
    private function secondsToNext(): float
    {
        $currentTime = Clock::now();
        $nextTime = \PHP_FLOAT_MAX;
        foreach ($this->delays as $time) {
            if ($time < $nextTime) {
                $nextTime = $time;
            }
        }

        return \max(0.0, $nextTime - $currentTime);
    }

    private function timeToNext(): int
    {
        // PHP_INT_MAX first: min() then returns the int operand whenever the
        // microseconds exceed it, so the cast never sees an oversized float.
        return (int) \min(\PHP_INT_MAX, $this->secondsToNext() * 1000000);
    }

    /**
     * @return resource|\CurlMultiHandle
     */
    private function getMultiHandle()
    {
        if ($this->multiHandle !== null) {
            return $this->multiHandle;
        }

        $this->assertOpen();

        $multiHandle = \curl_multi_init();
        if (false === $multiHandle) {
            throw new \RuntimeException('Can not initialize curl multi handle.');
        }

        try {
            foreach ($this->options as $option => $value) {
                if (!\is_int($option)) {
                    throw new InvalidArgumentException(\sprintf('Invalid cURL multi option "%s".', Psr7\DiagnosticValue::escape((string) $option)));
                }

                try {
                    $applied = @curl_multi_setopt($multiHandle, $option, $value);
                } catch (\Throwable $e) {
                    throw new InvalidArgumentException(
                        \sprintf('Unable to apply the cURL multi option %s; it was rejected by the runtime libcurl.', self::formatCurlMultiOption($option)),
                        0,
                        $e
                    );
                }

                if (true !== $applied) {
                    throw new InvalidArgumentException(\sprintf('Unable to apply the cURL multi option %s; it was rejected by the runtime libcurl.', self::formatCurlMultiOption($option)));
                }
            }
        } catch (\Throwable $e) {
            \curl_multi_close($multiHandle);

            throw $e;
        }

        $this->multiHandle = $multiHandle;

        return $this->multiHandle;
    }
}
