<?php

namespace GuzzleHttp\Handler;

use Closure;
use GuzzleHttp\Exception\HandlerClosedException;
use GuzzleHttp\Promise as P;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Utils;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

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
     * @var bool
     */
    private $ownsFactory;

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
    private $closed = false;

    /**
     * @var bool
     */
    private $closing = false;

    /**
     * This handler accepts the following options:
     *
     * - handle_factory: An optional factory  used to create curl handles
     * - select_timeout: Optional timeout (in seconds) to block before timing
     *   out while selecting curl handles. Defaults to 1 second.
     * - options: An associative array of CURLMOPT_* options and
     *   corresponding values for curl_multi_setopt()
     */
    public function __construct(array $options = [])
    {
        if (isset($options['handle_factory'])) {
            $this->factory = $options['handle_factory'];
            $this->ownsFactory = false;
        } else {
            $this->factory = new CurlFactory(50);
            $this->ownsFactory = true;
        }

        $this->selectTimeout = $options['select_timeout'] ?? 1;

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

        $this->assertOpen();

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
        try {
            $this->doClose(false);
        } catch (\Throwable $e) {
            // Destructors must not throw.
        }
    }

    /**
     * @return PromiseInterface<ResponseInterface, mixed>
     */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $this->assertOpen();

        $easy = $this->factory->create($request, $options);
        $id = (int) $easy->handle;

        /** @var Promise<ResponseInterface, mixed> $promise */
        $promise = new Promise(
            [$this, 'execute'],
            function () use ($id): void {
                $this->cancel($id);
            }
        );

        $this->addRequest(['easy' => $easy, 'deferred' => $promise]);

        return $promise;
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
                    unset($this->delays[$id]);
                    \curl_multi_add_handle(
                        $this->_mh,
                        $this->handles[$id]['easy']->handle
                    );
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

        if ($this->active && \curl_multi_select($this->_mh, $this->selectTimeout) === -1) {
            // Perform a usleep if a select returns -1.
            // See: https://bugs.php.net/bug.php?id=61141
            \usleep(250);
        }

        while (\curl_multi_exec($this->_mh, $this->active) === \CURLM_CALL_MULTI_PERFORM) {
            // Prevent busy looping for slow HTTP requests.
            \curl_multi_select($this->_mh, $this->selectTimeout);
        }

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

        if (\curl_multi_exec($this->_mh, $this->active) === \CURLM_CALL_MULTI_PERFORM) {
            \curl_multi_select($this->_mh, 0);
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

        try {
            $this->cleanupPendingTransfers($explicit, $failure);
            $this->closeMultiHandle($failure);
            $this->closeOwnedFactory($failure);
        } finally {
            $this->handles = [];
            $this->delays = [];
            $this->active = 0;
            $this->closed = true;
            $this->closing = false;
        }

        if ($explicit && $failure !== null) {
            throw $failure;
        }
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
                $this->captureFailure($failure, function () use ($easy): void {
                    $this->removeHandleFromMulti($easy->handle);
                });
            }

            if ($reject) {
                $this->captureFailure($failure, function () use ($entry): void {
                    $entry['deferred']->reject(new HandlerClosedException('The cURL multi handler was closed before the transfer completed.'));
                });
            }

            $this->captureFailure($failure, function () use ($easy): void {
                $this->disposeEasyHandle($easy);
            });
        }
    }

    private function closeMultiHandle(?\Throwable &$failure): void
    {
        if (!$this->hasMultiHandle()) {
            return;
        }

        $this->captureFailure($failure, function (): void {
            try {
                \curl_multi_close($this->_mh);
            } finally {
                unset($this->_mh);
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
    }

    /**
     * @param resource|\CurlHandle $handle
     */
    private function removeHandleFromMulti($handle): void
    {
        \curl_multi_remove_handle($this->_mh, $handle);
    }

    private function hasMultiHandle(): bool
    {
        return isset($this->_mh);
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
            \curl_multi_add_handle($this->_mh, $easy->handle);
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
        if (!$delayed && $this->hasMultiHandle() && self::hasEasyHandle($easy)) {
            $this->removeHandleFromMulti($easy->handle);
        }

        if (self::hasEasyHandle($easy)) {
            $handle = $easy->handle;
            unset($easy->handle);

            if (PHP_VERSION_ID < 80000 && \is_resource($handle)) {
                \curl_close($handle);
            }
        }

        return true;
    }

    private function processMessages(): void
    {
        while ($done = \curl_multi_info_read($this->_mh)) {
            if ($done['msg'] !== \CURLMSG_DONE) {
                // if it's not done, then it would be premature to remove the handle. ref https://github.com/guzzle/guzzle/pull/2892#issuecomment-945150216
                continue;
            }
            $id = (int) $done['handle'];
            $this->removeHandleFromMulti($done['handle']);

            if (!isset($this->handles[$id])) {
                // Probably was cancelled.
                continue;
            }

            $entry = $this->handles[$id];
            unset($this->handles[$id], $this->delays[$id]);
            $entry['easy']->errno = $done['result'];
            $entry['deferred']->resolve(
                CurlFactory::finish($this, $entry['easy'], $this->factory)
            );
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
}
