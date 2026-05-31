<?php

declare(strict_types=1);

namespace GuzzleHttp\Handler;

use Closure;
use GuzzleHttp\Exception\HandlerClosedException;
use GuzzleHttp\Exception\InvalidArgumentException;
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
        if (!\is_int($selectTimeout) && !\is_float($selectTimeout) && (!\is_string($selectTimeout) || !\is_numeric($selectTimeout))) {
            throw new InvalidArgumentException('select_timeout must be a number of seconds');
        }

        $this->selectTimeout = (float) $selectTimeout;

        $multiOptions = $options['options'] ?? [];
        if (!\is_array($multiOptions)) {
            throw new InvalidArgumentException('options must be an array of cURL multi options');
        }

        $this->options = $multiOptions;
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
                        $this->getMultiHandle(),
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

        if (\defined('CURLOPT_XFERINFOFUNCTION')) {
            curl_setopt($handle, (int) \constant('CURLOPT_XFERINFOFUNCTION'), null);
        }
    }

    /**
     * @param resource|\CurlHandle $handle
     */
    private function removeHandleFromMulti($handle): void
    {
        \curl_multi_remove_handle($this->getMultiHandle(), $handle);
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
            \curl_multi_add_handle($this->getMultiHandle(), $easy->handle);
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
            $this->removeHandleFromMulti($easy->handle);
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

        foreach ($entries as $entry) {
            $easy = $entry['easy'];

            if ($entry['attached'] && $this->hasMultiHandle() && self::hasEasyHandle($easy)) {
                $this->captureFailure($failure, function () use ($easy): void {
                    $this->removeHandleFromMulti($easy->handle);
                });
            }

            $this->captureFailure($failure, function () use ($easy): void {
                $this->disposeEasyHandle($easy);
            });
        }
    }

    private function processMessages(): void
    {
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
            $this->removeHandleFromMulti($done['handle']);

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

        $this->multiHandle = $multiHandle;

        foreach ($this->options as $option => $value) {
            if (!\is_int($option)) {
                throw new InvalidArgumentException(\sprintf('Invalid cURL multi option "%s".', $option));
            }

            // A warning is raised in case of a wrong option.
            curl_multi_setopt($multiHandle, $option, $value);
        }

        return $multiHandle;
    }
}
