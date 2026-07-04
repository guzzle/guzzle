<?php

declare(strict_types=1);

namespace GuzzleHttp\Handler;

use Closure;
use GuzzleHttp\Exception\HandlerClosedException;
use GuzzleHttp\Exception\InvalidArgumentException;
use GuzzleHttp\Multiplexing;
use GuzzleHttp\NonSerializableTrait;
use GuzzleHttp\Promise as P;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\TransportSharing;
use GuzzleHttp\Utils;
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
        'options' => true,
        'select_timeout' => true,
        'transport_sharing' => true,
    ];

    private const CONNECTION_CAP_OPTIONS = [
        'max_host_connections' => 'CURLMOPT_MAX_HOST_CONNECTIONS',
        'max_total_connections' => 'CURLMOPT_MAX_TOTAL_CONNECTIONS',
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
     * @var resource|\CurlMultiHandle|null
     */
    private $multiHandle;

    private bool $closed = false;

    private bool $closing = false;

    private bool $executingMulti = false;

    /**
     * @var array<int, array{easy: EasyHandle, attached: bool}>
     */
    private array $deferredCancels = [];

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
     * @var bool Guards against multi-handle recreation re-entrancy from
     *           processMessages (a retried transfer re-invokes the handler)
     */
    private bool $processingMessages = false;

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
                throw new InvalidArgumentException(\sprintf('Invalid CurlMultiHandler constructor option "%s".', (string) $name));
            }
        }

        CurlShareHandleState::assertNoRequiredSharingCustomFactoryConflict($options, 'CurlMultiHandler');
        $transportSharing = $options['transport_sharing'] ?? null;
        $sharingMode = CurlShareHandleState::normalizeMode($transportSharing, 'transport_sharing');

        $connectionCapOption = self::firstConnectionCapOption($options);
        if ($connectionCapOption !== null) {
            $persistentShareState = $transportSharing instanceof CurlShareHandleState
                && \in_array($sharingMode, [TransportSharing::PERSISTENT_PREFER, TransportSharing::PERSISTENT_REQUIRE], true);

            if ($persistentShareState || $sharingMode === TransportSharing::PERSISTENT_REQUIRE) {
                throw new InvalidArgumentException(\sprintf('%s cannot be combined with persistent transport sharing because libcurl does not apply connection caps to shared connection pools.', $connectionCapOption));
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
                ? new CurlFactory(50, $this->shareHandleState->mode, $this->shareHandleState->handle)
                : new CurlFactory(50);

            $this->ownsFactory = true;
        }

        $selectTimeout = $options['select_timeout'] ?? 1.0;
        Utils::timeoutToMilliseconds($selectTimeout, 'select_timeout');
        $this->selectTimeout = (float) $selectTimeout;

        $multiOptions = $options['options'] ?? [];
        if (!\is_array($multiOptions)) {
            throw new InvalidArgumentException('options must be an array of cURL multi options');
        }

        $this->options = $multiOptions;
        self::rejectConflictingCurlMultiOptions($this->options);
        $this->addConnectionCapOptions($options);
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

        throw new \LogicException(self::class.' should never be unserialized');
    }

    /**
     * @return PromiseInterface<ResponseInterface, mixed>
     */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $this->assertOpen();
        $easy = $this->factory->create($request, $options);

        try {
            $this->rejectMultiplexPipeliningConflict($easy, $options);
        } catch (\Throwable $e) {
            $this->factory->release($easy);

            throw $e;
        }

        $this->applyProxyTunnelOwnership($easy);

        $id = (int) $easy->handle;

        /** @var Promise<ResponseInterface, mixed> $promise */
        $promise = new Promise(
            [$this, 'execute'],
            function () use ($id): void {
                $this->cancel($id);
            }
        );

        $entry = ['easy' => $easy, 'deferred' => $promise];
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
     * not wait.
     */
    private function rejectMultiplexPipeliningConflict(EasyHandle $easy, array $options): void
    {
        $multiplex = $options['multiplex'] ?? null;

        if (Multiplexing::WAIT === $multiplex && !$easy->usesPipewait) {
            // Explicit wait only conflicts when the transfer would actually
            // wait; an HTTP/1.1 wait request never sets the marker.
            return;
        }

        if (!\in_array($multiplex, [Multiplexing::WAIT, Multiplexing::REQUIRE_EAGER, Multiplexing::REQUIRE_WAIT], true)) {
            return;
        }

        if (!\array_key_exists(\CURLMOPT_PIPELINING, $this->options)) {
            return;
        }

        $pipelining = $this->options[\CURLMOPT_PIPELINING];
        if (!\is_scalar($pipelining)) {
            return;
        }

        $multiplexBit = \defined('CURLPIPE_MULTIPLEX') ? \CURLPIPE_MULTIPLEX : 2;
        if (((int) $pipelining & $multiplexBit) !== 0) {
            return;
        }

        throw new InvalidArgumentException('The "multiplex" request option cannot be combined with a CurlMultiHandler CURLMOPT_PIPELINING option that disables multiplexing; set CURLMOPT_PIPELINING to CURLPIPE_MULTIPLEX, remove the option, or set the "multiplex" option to "eager".');
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
            if (!\is_int($option)) {
                throw new InvalidArgumentException(\sprintf('The cURL constant %s must resolve to an integer.', $constant));
            }

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
            && !$this->processingMessages
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

    private function addHandleToMulti(int $id, EasyHandle $easy): void
    {
        $this->isolateFromForeignActiveProxyTunnel($easy);
        \curl_multi_add_handle($this->getMultiHandle(), $easy->handle);
        $this->markProxyTunnelActive($id, $easy);
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
        // Unqualified curl_setopt so the test bootstrap shadow records it.
        curl_setopt($easy->handle, \CURLOPT_FRESH_CONNECT, true);
        curl_setopt($easy->handle, \CURLOPT_FORBID_REUSE, true);
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
        $this->assertOpen();

        // Add any delayed handles if needed.
        if ($this->delays) {
            $currentTime = Utils::currentTime();
            foreach ($this->delays as $id => $delay) {
                if ($currentTime >= $delay) {
                    $entry = $this->handles[$id];
                    unset($this->delays[$id]);

                    try {
                        $this->addHandleToMulti($id, $entry['easy']);
                    } catch (\Throwable $e) {
                        $entry['deferred']->reject($this->discardPendingRequest($id, $entry, $e));
                    }
                }
            }
        }

        // Run curl_multi_exec in the queue to enable other async tasks to run
        P\Utils::queue()->add(Closure::fromCallable([$this, 'tickInQueue']));

        // Step through the task queue which may add additional requests.
        P\Utils::queue()->run();

        if ($this->closed || $this->closing || !$this->hasMultiHandle()) {
            return;
        }

        if ($this->active && \curl_multi_select($this->getMultiHandle(), $this->selectTimeout) === -1) {
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
                \curl_multi_select($this->getMultiHandle(), $this->selectTimeout);
            }
        } while ($exec === \CURLM_CALL_MULTI_PERFORM);

        $this->processMessages();
    }

    /**
     * Runs \curl_multi_exec() inside the event loop, to prevent busy looping
     */
    private function tickInQueue(): void
    {
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

        $queue = P\Utils::queue();

        while (!$this->closed && !$this->closing && ($this->handles || !$queue->isEmpty())) {
            // If there are no transfers, then sleep for the next delay
            if (!$this->active && $this->delays) {
                \usleep($this->timeToNext());
            }
            $this->tick();
        }
    }

    /**
     * Closes native cURL resources owned by this handler.
     *
     * Pending transfers are rejected with HandlerClosedException. After closing,
     * the handler is terminal and must not be reused.
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

        if ($this->executingMulti) {
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
        $delays = $this->delays;

        $this->handles = [];
        $this->delays = [];

        foreach ($entries as $id => $entry) {
            $this->deferredCancels[$id] = [
                'easy' => $entry['easy'],
                'attached' => !isset($delays[$id]),
            ];

            if ($explicit) {
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
     * @param array{easy: EasyHandle, deferred: Promise<ResponseInterface, mixed>} $entry
     */
    private function discardPendingRequest(int $id, array $entry, \Throwable $failure): \Throwable
    {
        unset($this->handles[$id], $this->delays[$id]);

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
        $delays = $this->delays;

        $this->handles = [];
        $this->delays = [];

        foreach ($entries as $id => $entry) {
            $easy = $entry['easy'];
            $attached = !isset($delays[$id]);

            if ($attached && $this->hasMultiHandle() && self::hasEasyHandle($easy)) {
                $this->captureFailure($failure, function () use ($id, $easy): void {
                    $this->removeHandleFromMulti($id, $easy->handle);
                });
            }

            if ($reject) {
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
        $this->executingMulti = true;
        $failure = null;

        try {
            return \curl_multi_exec($this->getMultiHandle(), $this->active);
        } finally {
            $this->executingMulti = false;
            $this->cleanupDeferredCancels($failure);

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
            } elseif ($failure !== null) {
                throw $failure;
            }
        }
    }

    private function disposeEasyHandle(EasyHandle $easy): void
    {
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
        try {
            \curl_multi_remove_handle($this->getMultiHandle(), $handle);
        } finally {
            $this->unmarkProxyTunnelActiveById($id);
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

    private function addRequest(array $entry): void
    {
        $easy = $entry['easy'];
        $id = (int) $easy->handle;
        $this->handles[$id] = $entry;
        if (empty($easy->options['delay'])) {
            $this->addHandleToMulti($id, $easy);
        } else {
            $this->delays[$id] = Utils::currentTime() + ($easy->options['delay'] / 1000);
        }
    }

    /**
     * Cancels a handle from sending and removes references to it.
     *
     * @param int $id Handle ID to cancel and remove.
     *
     * @return bool True on success, false on failure.
     */
    private function cancel(int $id): bool
    {
        // Cannot cancel if it has been processed.
        if (!isset($this->handles[$id])) {
            return false;
        }

        $easy = $this->handles[$id]['easy'];
        $delayed = isset($this->delays[$id]);
        unset($this->delays[$id], $this->handles[$id]);

        if ($this->executingMulti) {
            $this->deferredCancels[$id] = ['easy' => $easy, 'attached' => !$delayed];

            return true;
        }

        if (!$delayed && $this->hasMultiHandle() && self::hasEasyHandle($easy)) {
            $this->removeHandleFromMulti($id, $easy->handle);
        }

        $this->disposeEasyHandle($easy);

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
        // the multi handle mid-iteration (see applyProxyTunnelOwnership).
        $this->processingMessages = true;

        try {
            while ($done = \curl_multi_info_read($this->getMultiHandle())) {
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

                try {
                    $result = CurlFactory::finish($this, $entry['easy'], $this->factory);
                } catch (\Throwable $e) {
                    $entry['deferred']->reject($e);

                    continue;
                }

                $entry['deferred']->resolve($result);
            }
        } finally {
            $this->processingMessages = false;
        }
    }

    private function timeToNext(): int
    {
        $currentTime = Utils::currentTime();
        $nextTime = \PHP_INT_MAX;
        foreach ($this->delays as $time) {
            if ($time < $nextTime) {
                $nextTime = $time;
            }
        }

        return ((int) \max(0, $nextTime - $currentTime)) * 1000000;
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
                    throw new InvalidArgumentException(\sprintf('Invalid cURL multi option "%s".', $option));
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
