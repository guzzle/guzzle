<?php

namespace GuzzleHttp\Handler;

use Closure;
use GuzzleHttp\Promise as P;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
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
     * @var bool Guards against multi-handle recreation re-entrancy from
     *           processMessages (a retried transfer re-invokes the handler)
     */
    private $processingMessages = false;

    /**
     * This handler accepts the following options:
     *
     * - handle_factory: An optional factory  used to create curl handles
     * - transport_sharing: Optional transport sharing mode.
     * - select_timeout: Optional timeout (in seconds) to block before timing
     *   out while selecting curl handles. Defaults to 1 second.
     * - options: An associative array of CURLMOPT_* options and
     *   corresponding values for curl_multi_setopt()
     */
    public function __construct(array $options = [])
    {
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
            $this->selectTimeout = $options['select_timeout'];
        } elseif ($selectTimeout = Utils::getenv('GUZZLE_CURL_SELECT_TIMEOUT')) {
            \trigger_deprecation('guzzlehttp/guzzle', '7.2', 'The GUZZLE_CURL_SELECT_TIMEOUT environment variable is deprecated; use the "select_timeout" option instead.');
            $this->selectTimeout = (int) $selectTimeout;
        } else {
            $this->selectTimeout = 1;
        }

        $this->options = $options['options'] ?? [];

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
            // A warning is raised in case of a wrong option.
            curl_multi_setopt($this->_mh, $option, $value);
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
        $this->applyProxyTunnelOwnership($easy);
        $id = (int) $easy->handle;

        $promise = new Promise(
            [$this, 'execute'],
            function () use ($id) {
                return $this->cancel($id);
            }
        );

        $this->addRequest(['easy' => $easy, 'deferred' => $promise]);

        return $promise;
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
        \curl_multi_add_handle($this->_mh, $easy->handle);
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
        // Unqualified curl_setopt so the test bootstrap shadow records it.
        curl_setopt($easy->handle, \CURLOPT_FRESH_CONNECT, true);
        curl_setopt($easy->handle, \CURLOPT_FORBID_REUSE, true);
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
        // Add any delayed handles if needed.
        if ($this->delays) {
            $currentTime = Utils::currentTime();
            foreach ($this->delays as $id => $delay) {
                if ($currentTime >= $delay) {
                    unset($this->delays[$id]);
                    $this->addCurlHandle($this->handles[$id]['easy']);
                }
            }
        }

        // Run curl_multi_exec in the queue to enable other async tasks to run
        P\Utils::queue()->add(Closure::fromCallable([$this, 'tickInQueue']));

        // Step through the task queue which may add additional requests.
        P\Utils::queue()->run();

        if ($this->active && \curl_multi_select($this->_mh, $this->selectTimeout) === -1) {
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
                \curl_multi_select($this->_mh, $this->selectTimeout);
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
            // If there are no transfers, then sleep for the next delay
            if (!$this->active && $this->delays) {
                \usleep($this->timeToNext());
            }
            $this->tick();
        }
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
     * Cancels a handle from sending and removes references to it.
     *
     * @param int $id Handle ID to cancel and remove.
     *
     * @return bool True on success, false on failure.
     */
    private function cancel($id): bool
    {
        if (!is_int($id)) {
            \trigger_deprecation('guzzlehttp/guzzle', '7.4', 'Not passing an int to %s::%s() is deprecated and will cause an error in 8.0.', __CLASS__, __FUNCTION__);
        }

        // Cannot cancel if it has been processed.
        if (!isset($this->handles[$id])) {
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
        // the multi handle mid-iteration (see applyProxyTunnelOwnership).
        $this->processingMessages = true;

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

        return (int) \max(0, ($nextTime - $currentTime) * 1000000);
    }
}
