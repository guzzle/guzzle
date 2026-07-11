<?php

namespace GuzzleHttp\Handler;

use Closure;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Multiplexing;
use GuzzleHttp\Promise as P;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\TransportSharing;
use GuzzleHttp\Utils;
use Psr\Http\Message\RequestInterface;

/**
 * Returns an asynchronous response using curl_multi_* functions.
 *
 * When using the CurlMultiHandler, custom curl options can be specified as an
 * associative array of curl option constants mapping to values in the
 * **curl** key of the provided request options.
 *
 * @final
 */
class CurlMultiHandler
{
    private const KNOWN_CONSTRUCTOR_OPTIONS = [
        'handle_factory' => true,
        'max_host_connections' => true,
        'max_total_connections' => true,
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

    /**
     * @var CurlFactoryInterface
     */
    private $factory;

    /**
     * @var CurlShareHandleState|null
     */
    private $shareHandleState;

    /**
     * @var int
     */
    private $selectTimeout;

    /**
     * @var int Will be higher than 0 when `curl_multi_exec` is still running.
     */
    private $active = 0;

    /**
     * @var array Request entry handles, indexed by handle id in `addRequest`.
     *
     * @see CurlMultiHandler::addRequest
     */
    private $handles = [];

    /**
     * @var array<int, float> An array of delay times, indexed by handle id in `addRequest`.
     *
     * @see CurlMultiHandler::addRequest
     */
    private $delays = [];

    /**
     * @var array<mixed> An associative array of CURLMOPT_* options and corresponding values for curl_multi_setopt()
     */
    private $options = [];

    /** @var resource|\CurlMultiHandle */
    private $_mh;

    /**
     * @var bool
     */
    private $executingMulti = false;

    /**
     * @var array<int, EasyHandle>
     */
    private $deferredCancels = [];

    /**
     * @var string|null Owner signature of the proxy tunnels the multi handle's
     *                  connection cache may hold
     */
    private $proxyTunnelOwner;

    /** @var array<string, int> Count of attached transfers per proxy tunnel signature. */
    private $activeProxyTunnelSignatures = [];

    /** @var array<int, string> Maps an attached handle id to its proxy tunnel signature. */
    private $activeProxyTunnelHandles = [];

    /**
     * @var int Depth of nested processMessages() calls. Guards against
     *          multi-handle recreation re-entrancy from processMessages (a
     *          retried transfer re-invokes the handler); a depth is tracked
     *          because a completion callback can re-enter tick().
     */
    private $messageProcessingDepth = 0;

    /**
     * This handler accepts the following options:
     *
     * - handle_factory: An optional factory  used to create curl handles
     * - transport_sharing: Optional transport sharing mode.
     * - select_timeout: Optional timeout (in seconds) to block before timing
     *   out while selecting curl handles. Defaults to 1 second.
     * - max_host_connections: Optional maximum concurrent connections per host.
     * - max_total_connections: Optional maximum concurrent connections overall.
     * - options: An associative array of CURLMOPT_* options and
     *   corresponding values for curl_multi_setopt()
     */
    public function __construct(array $options = [])
    {
        foreach ($options as $name => $_) {
            if (!isset(self::KNOWN_CONSTRUCTOR_OPTIONS[$name])) {
                \trigger_deprecation('guzzlehttp/guzzle', '7.14', \sprintf('The "%s" CurlMultiHandler constructor option is unknown; guzzlehttp/guzzle 8.0 will reject unknown constructor options.', (string) $name));
            }
        }

        CurlShareHandleState::assertNoRequiredSharingCustomFactoryConflict($options, 'CurlMultiHandler');
        $transportSharing = $options['transport_sharing'] ?? null;
        $sharingMode = CurlShareHandleState::normalizeMode($transportSharing, 'transport_sharing');

        if (\array_key_exists('handle_factory', $options) && $options['handle_factory'] !== null) {
            $this->shareHandleState = null;
            $this->factory = $options['handle_factory'];
        } else {
            $this->shareHandleState = $sharingMode !== TransportSharing::NONE
                ? CurlShareHandleState::fromOption($transportSharing)
                : null;

            $this->factory = $this->shareHandleState !== null
                ? new CurlFactory(50, $this->shareHandleState->mode, $this->shareHandleState->handle)
                : new CurlFactory(50);
        }

        if (isset($options['select_timeout'])) {
            $selectTimeout = $options['select_timeout'];
            if (!\is_int($selectTimeout) && !\is_float($selectTimeout) && (!\is_string($selectTimeout) || !\is_numeric($selectTimeout))) {
                \trigger_deprecation('guzzlehttp/guzzle', '7.14', 'Passing a non-numeric "select_timeout" CurlMultiHandler option is deprecated; guzzlehttp/guzzle 8.0 will reject it.');
            } else {
                $seconds = (float) $selectTimeout;
                if (!\is_finite($seconds) || $seconds < 0 || ($seconds > 0 && (int) ($seconds * 1000) === 0)) {
                    \trigger_deprecation('guzzlehttp/guzzle', '7.14', 'Passing a "select_timeout" CurlMultiHandler option that is not 0 or greater than or equal to 0.001 seconds is deprecated; guzzlehttp/guzzle 8.0 will reject it.');
                }
            }

            $this->selectTimeout = $selectTimeout;
        } elseif ($selectTimeout = Utils::getenv('GUZZLE_CURL_SELECT_TIMEOUT')) {
            \trigger_deprecation('guzzlehttp/guzzle', '7.2', 'The GUZZLE_CURL_SELECT_TIMEOUT environment variable is deprecated; use the "select_timeout" option instead.');
            $this->selectTimeout = (int) $selectTimeout;
        } else {
            $this->selectTimeout = 1;
        }

        $multiOptions = $options['options'] ?? [];
        if (\is_array($multiOptions)) {
            self::rejectConnectionCapOptionConflicts($options, $multiOptions);
            self::triggerConflictingCurlMultiOptionDeprecations($multiOptions);
        } elseif (self::hasConnectionCapOption($options)) {
            throw new \InvalidArgumentException('options must be an array of cURL multi options when using connection cap options.');
        }

        $this->options = $multiOptions;

        if (\is_array($multiOptions)) {
            $this->addConnectionCapOptions($options);
        }

        // unsetting the property forces the first access to go through
        // __get().
        unset($this->_mh);
    }

    /**
     * @param string $name
     *
     * @return resource|\CurlMultiHandle
     *
     * @throws \BadMethodCallException when another field as `_mh` will be gotten
     * @throws \RuntimeException       when curl can not initialize a multi handle
     */
    public function __get($name)
    {
        if ($name !== '_mh') {
            throw new \BadMethodCallException("Can not get other property as '_mh'.");
        }

        $multiHandle = \curl_multi_init();

        if (false === $multiHandle) {
            throw new \RuntimeException('Can not initialize curl multi handle.');
        }

        $this->_mh = $multiHandle;

        foreach ($this->options as $option => $value) {
            if (true !== @curl_multi_setopt($this->_mh, $option, $value)) {
                \trigger_error(\sprintf('Unable to apply the cURL multi option %s; it was ignored by the runtime libcurl.', self::formatCurlMultiOption($option)), \E_USER_WARNING);
            }
        }

        return $this->_mh;
    }

    public function __destruct()
    {
        if (isset($this->_mh)) {
            try {
                \curl_multi_close($this->_mh);
            } catch (\Throwable $e) {
                // Destructors must not throw.
            } finally {
                unset($this->_mh);
            }
        }
    }

    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $easy = $this->factory->create($request, $options);

        try {
            $this->rejectMultiplexPipeliningConflict($easy, $options);
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

        $sync = !empty($options[RequestOptions::SYNCHRONOUS]);
        $waitToken = new \stdClass();

        $promise = new Promise(
            function () use ($id, $sync, $waitToken): void {
                if ($sync) {
                    $this->executeUntil($id, $waitToken);
                } else {
                    $this->execute();
                }
            },
            function () use ($id, $waitToken) {
                return $this->cancel($id, $waitToken);
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
     * ignores entirely when the multi handle's CURLMOPT_PIPELINING option
     * disables multiplexing, so an explicit request for multiplexing on a
     * handler configured against it is a configuration error. The required
     * family conflicts marker-independently: a required guarantee on a handler
     * that disables multiplexing is contradictory even when the transfer would
     * not wait. A raw CURLOPT_PIPEWAIT cURL option conflicts with every
     * explicit mode on this handler, where waiting is operationally
     * meaningful: whatever its value, it is a second wait/eager authority
     * applied after the mode's own decision.
     */
    private function rejectMultiplexPipeliningConflict(EasyHandle $easy, array $options): void
    {
        $multiplex = $options['multiplex'] ?? null;

        if (null === $multiplex) {
            return;
        }

        if (\defined('CURLOPT_PIPEWAIT')
            && isset($options['curl'])
            && \is_array($options['curl'])
            && \array_key_exists((int) \constant('CURLOPT_PIPEWAIT'), $options['curl'])
        ) {
            // Key presence alone conflicts, and it must be rejected before
            // the marker below is consulted: the marker reflects the final
            // merged configuration, which the raw value has falsified.
            throw new \InvalidArgumentException('The "multiplex" request option cannot be combined with the raw CURLOPT_PIPEWAIT cURL option on the cURL multi handler; remove the raw option.');
        }

        if (Multiplexing::WAIT === $multiplex && !$easy->usesPipewait) {
            // Explicit wait only conflicts when the transfer would actually
            // wait; an HTTP/1.1 wait request never sets the marker.
            return;
        }

        if (!\in_array($multiplex, [Multiplexing::WAIT, Multiplexing::REQUIRE_EAGER, Multiplexing::REQUIRE_WAIT], true)) {
            return;
        }

        if (!\is_array($this->options) || !\array_key_exists(\CURLMOPT_PIPELINING, $this->options)) {
            // A legacy non-array "options" value is tolerated by the
            // constructor and cannot contain the option.
            return;
        }

        $pipelining = $this->options[\CURLMOPT_PIPELINING];
        if (!\is_scalar($pipelining)) {
            // ext-curl derives the integer mask from non-scalar values with
            // type-dependent zval semantics, so the effective mask cannot be
            // predicted here; require an explicit integer instead.
            throw new \InvalidArgumentException('The CurlMultiHandler CURLMOPT_PIPELINING option must be an integer when combined with the "multiplex" request option.');
        }

        $multiplexBit = \defined('CURLPIPE_MULTIPLEX') ? \CURLPIPE_MULTIPLEX : 2;
        if (((int) $pipelining & $multiplexBit) !== 0) {
            return;
        }

        throw new \InvalidArgumentException('The "multiplex" request option cannot be combined with a CurlMultiHandler CURLMOPT_PIPELINING option that disables multiplexing; set CURLMOPT_PIPELINING to CURLPIPE_MULTIPLEX, remove the option, or set the "multiplex" option to "eager".');
    }

    /**
     * @param array<mixed> $options
     */
    private static function triggerConflictingCurlMultiOptionDeprecations(array $options): void
    {
        if ($options === []) {
            return;
        }

        $conflictingOptions = self::conflictingCurlMultiOptions();
        foreach ($options as $option => $_) {
            if (\array_key_exists($option, $conflictingOptions)) {
                \trigger_deprecation('guzzlehttp/guzzle', '7.14', \sprintf('Passing %s in the cURL multi handler "options" is deprecated; guzzlehttp/guzzle 8.0 will reject this option. Use %s instead.', self::formatCurlMultiOption($option), $conflictingOptions[$option]));
            }
        }
    }

    /**
     * @param array<mixed> $options
     */
    private static function hasConnectionCapOption(array $options): bool
    {
        foreach (self::CONNECTION_CAP_OPTIONS as $name => $_) {
            if (($options[$name] ?? null) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $constructorOptions
     * @param array<mixed> $multiOptions
     */
    private static function rejectConnectionCapOptionConflicts(array $constructorOptions, array $multiOptions): void
    {
        foreach (self::CONNECTION_CAP_OPTIONS as $name => $constant) {
            if (($constructorOptions[$name] ?? null) === null || !\defined($constant)) {
                continue;
            }

            $option = \constant($constant);
            if (\array_key_exists($option, $multiOptions)) {
                throw new \InvalidArgumentException(\sprintf('%s conflicts with a %s entry in the "options" array.', $name, $constant));
            }
        }
    }

    /**
     * @param array<mixed> $options
     */
    private function addConnectionCapOptions(array $options): void
    {
        foreach (self::CONNECTION_CAP_OPTIONS as $name => $constant) {
            $value = $options[$name] ?? null;
            if ($value === null) {
                continue;
            }

            if (!\is_int($value) || $value < 1) {
                throw new \InvalidArgumentException(\sprintf('%s must be a positive integer.', $name));
            }

            CurlVersion::ensureConnectionCapsSupported($name);

            $option = \constant($constant);
            if (\array_key_exists($option, $this->options)) {
                throw new \InvalidArgumentException(\sprintf('%s conflicts with a %s entry in the "options" array.', $name, $constant));
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
            return \sprintf('"%s"', $option);
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
    private function applyProxyTunnelOwnership(EasyHandle $easy): void
    {
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
            && !$this->executingMulti
            && 0 === $this->messageProcessingDepth
            && $this->deferredCancels === []
        ) {
            // Idle: hand the connection cache over by recreating the multi
            // handle (unsetting re-arms the lazy __get initializer, which
            // re-applies the CURLMOPT_* options).
            if (isset($this->_mh)) {
                \curl_multi_close($this->_mh);
                unset($this->_mh);
            }
            $this->proxyTunnelOwner = $signature;

            return;
        }

        // Busy: isolate this transfer from the owner's pooled tunnels.
        $this->isolateProxyTunnelTransfer($easy);
    }

    private function addCurlHandle(EasyHandle $easy): void
    {
        $this->isolateFromForeignActiveProxyTunnel($easy);

        // Unqualified curl_multi_add_handle so the test bootstrap shadow can
        // override the result.
        $result = curl_multi_add_handle($this->_mh, $easy->handle);

        if (\CURLM_OK !== $result) {
            if (\PHP_VERSION_ID < 80226 || (\PHP_VERSION_ID >= 80300 && \PHP_VERSION_ID < 80314)) {
                // Before PHP 8.2.26 and 8.3.14, ext-curl kept the easy handle
                // in its multi bookkeeping even when the native add failed
                // (https://github.com/php/php-src/pull/16302); remove it so
                // the handle can be pooled or closed safely.
                \curl_multi_remove_handle($this->_mh, $easy->handle);
            }

            throw new RequestException(\sprintf('Unable to add the cURL handle to the cURL multi handler: %s (%d).', (string) \curl_multi_strerror($result), $result), $easy->request);
        }

        $this->markProxyTunnelActive($easy);
    }

    /**
     * @param resource|\CurlHandle $handle
     */
    private function removeCompletedHandleFromMulti(int $id, $handle): void
    {
        \curl_multi_remove_handle($this->_mh, $handle);
        $this->unmarkProxyTunnelActiveById($id);
    }

    private function isolateFromForeignActiveProxyTunnel(EasyHandle $easy): void
    {
        $signature = $easy->proxyTunnelSignature;

        if ($signature === null || $this->activeProxyTunnelSignatures === []) {
            return;
        }

        if (\count($this->activeProxyTunnelSignatures) === 1 && isset($this->activeProxyTunnelSignatures[$signature])) {
            return;
        }

        $this->isolateProxyTunnelTransfer($easy);
    }

    private function isolateProxyTunnelTransfer(EasyHandle $easy): void
    {
        foreach (self::PROXY_TUNNEL_ISOLATION_OPTIONS as $name) {
            try {
                // Unqualified curl_setopt so the test bootstrap shadow records it.
                $applied = curl_setopt($easy->handle, (int) \constant($name), true);
            } catch (\Throwable $e) {
                throw new RequestException(self::proxyTunnelIsolationFailureMessage($name), $easy->request, null, $e);
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

    private function markProxyTunnelActive(EasyHandle $easy): void
    {
        $signature = $easy->proxyTunnelSignature;
        if ($signature === null) {
            return;
        }

        $id = (int) $easy->handle;
        if (isset($this->activeProxyTunnelHandles[$id])) {
            if ($this->activeProxyTunnelHandles[$id] === $signature) {
                return;
            }

            $this->unmarkProxyTunnelActiveById($id);
        }

        $this->activeProxyTunnelHandles[$id] = $signature;
        $this->activeProxyTunnelSignatures[$signature] = ($this->activeProxyTunnelSignatures[$signature] ?? 0) + 1;
    }

    private function unmarkProxyTunnelActive(EasyHandle $easy): void
    {
        $this->unmarkProxyTunnelActiveById((int) $easy->handle);
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
        // Add any delayed handles if needed.
        if ($this->delays) {
            $currentTime = Utils::currentTime();
            foreach ($this->delays as $id => $delay) {
                if ($currentTime >= $delay) {
                    $entry = $this->handles[$id];
                    unset($this->delays[$id]);

                    try {
                        $this->addCurlHandle($entry['easy']);
                    } catch (\Throwable $e) {
                        // The promise has already escaped, so reject it
                        // rather than throw.
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

            $this->processMessages();
        } while (!P\Utils::queue()->isEmpty());

        if ($targetId !== null && !$this->hasRequest($targetId, $waitToken)) {
            return;
        }

        if ($this->active && \curl_multi_select($this->_mh, $this->effectiveSelectTimeout()) === -1) {
            // Perform a usleep if a select returns -1.
            // See: https://bugs.php.net/bug.php?id=61141
            \usleep(250);
        }

        do {
            $this->executingMulti = true;

            try {
                $exec = \curl_multi_exec($this->_mh, $this->active);
            } finally {
                $this->executingMulti = false;
                $this->cleanupDeferredCancels();
            }

            // Prevent busy looping for slow HTTP requests.
            if ($exec === \CURLM_CALL_MULTI_PERFORM) {
                \curl_multi_select($this->_mh, $this->effectiveSelectTimeout());
            }
        } while ($exec === \CURLM_CALL_MULTI_PERFORM);

        $this->processMessages();
    }

    /**
     * Runs \curl_multi_exec() inside the event loop, to prevent busy looping
     */
    private function tickInQueue(): void
    {
        $this->executingMulti = true;

        try {
            $exec = \curl_multi_exec($this->_mh, $this->active);
        } finally {
            $this->executingMulti = false;
            $this->cleanupDeferredCancels();
        }

        if ($exec === \CURLM_CALL_MULTI_PERFORM) {
            \curl_multi_select($this->_mh, 0);
            P\Utils::queue()->add(Closure::fromCallable([$this, 'tickInQueue']));
        }
    }

    /**
     * Runs until all outstanding connections have completed.
     */
    public function execute(): void
    {
        $queue = P\Utils::queue();

        while ($this->handles || !$queue->isEmpty()) {
            // If there are no transfers, then sleep for the next delay,
            // unless ready queue work could change what is pending.
            if (!$this->active && $this->delays && $queue->isEmpty()) {
                \usleep($this->timeToNext());
            }
            $this->tick();
        }
    }

    /**
     * Runs the event loop until the given transfer has finished, so a
     * synchronous transfer does not wait for every other transfer on the
     * handler like execute() does.
     *
     * The native cURL handle ID can be reused by a request created from a
     * completion callback, so the wait token guards against waiting on an
     * unrelated transfer that inherited the ID.
     */
    private function executeUntil(int $id, object $waitToken): void
    {
        $queue = P\Utils::queue();

        while ($this->hasRequest($id, $waitToken)) {
            // If the transfer is delayed, then sleep until it is due, unless
            // ready queue work could cancel or replace it first.
            if (!$this->active && isset($this->delays[$id]) && $queue->isEmpty()) {
                \usleep($this->timeToNext());
            }
            $this->tickFor($id, $waitToken);
        }

        if (!$queue->isEmpty()) {
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

    private function addRequest(array $entry): void
    {
        $easy = $entry['easy'];
        $id = (int) $easy->handle;
        $this->handles[$id] = $entry;
        if (empty($easy->options['delay'])) {
            $this->addCurlHandle($easy);
        } else {
            $this->delays[$id] = Utils::currentTime() + ($easy->options['delay'] / 1000);
        }
    }

    /**
     * Rolls back a request that can no longer be attached, releasing the
     * easy handle exactly once and preserving the original failure.
     *
     * @param array{easy: EasyHandle, deferred: Promise, wait_token?: object|null} $entry
     */
    private function discardPendingRequest(int $id, array $entry, \Throwable $failure): \Throwable
    {
        unset($this->handles[$id], $this->delays[$id]);

        try {
            $this->factory->release($entry['easy']);
        } catch (\Throwable $e) {
            // Preserve the original failure.
        }

        return $failure;
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
    private function cancel($id, ?object $waitToken = null): bool
    {
        if (!is_int($id)) {
            \trigger_deprecation('guzzlehttp/guzzle', '7.4', 'Not passing an int to %s::%s() is deprecated and will cause an error in 8.0.', __CLASS__, __FUNCTION__);
        }

        // Cannot cancel if it has been processed or replaced by a request
        // that reused the native handle ID.
        if (!isset($this->handles[$id]) || ($waitToken !== null && ($this->handles[$id]['wait_token'] ?? null) !== $waitToken)) {
            return false;
        }

        $easy = $this->handles[$id]['easy'];
        unset($this->delays[$id], $this->handles[$id]);

        if ($this->executingMulti) {
            $this->deferredCancels[$id] = $easy;

            return true;
        }

        $this->cleanupCancelledHandle($easy);

        return true;
    }

    private function cleanupDeferredCancels(): void
    {
        if ($this->deferredCancels === []) {
            return;
        }

        $entries = $this->deferredCancels;
        $this->deferredCancels = [];

        foreach ($entries as $easy) {
            $this->cleanupCancelledHandle($easy);
        }
    }

    private function cleanupCancelledHandle(EasyHandle $easy): void
    {
        $handle = $easy->handle;
        \curl_multi_remove_handle($this->_mh, $handle);
        $this->unmarkProxyTunnelActive($easy);

        if (PHP_VERSION_ID < 80000) {
            \curl_close($handle);
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
            while ($done = \curl_multi_info_read($this->_mh)) {
                if ($done['msg'] !== \CURLMSG_DONE) {
                    // if it's not done, then it would be premature to remove the handle. ref https://github.com/guzzle/guzzle/pull/2892#issuecomment-945150216
                    continue;
                }
                if (!isset($done['handle'])) {
                    // Work around a PHP issue where cancelled transfers may omit the handle.
                    // Remove this once we no longer support PHP versions before the fix in
                    // https://github.com/php/php-src/pull/16302.
                    continue;
                }
                $id = (int) $done['handle'];
                $this->removeCompletedHandleFromMulti($id, $done['handle']);

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
        }
    }

    /**
     * Bounds a blocking select by the earliest pending request delay so a
     * delayed transfer becoming due does not wait out an unrelated
     * transfer's full select timeout.
     *
     * @return float|int
     */
    private function effectiveSelectTimeout()
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
        $currentTime = Utils::currentTime();
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
}
