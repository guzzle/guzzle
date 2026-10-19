<?php

declare(strict_types=1);

namespace GuzzleHttp\Handler;

use GuzzleHttp\Exception\InvalidArgumentException;
use GuzzleHttp\NonSerializableTrait;
use GuzzleHttp\Promise as P;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Sends asynchronous requests through a cURL multi handler leased to the
 * current execution context while it has work.
 *
 * Idle handlers return to a bounded pool so other contexts can reuse their
 * connections before the original context finishes. Requests made outside
 * managed contexts share one handler. The default handler keeps up to 8 idle
 * handlers.
 *
 * With a pool limit of 0, each context keeps its handler until it finishes,
 * reusing connections within that context without pooling it for later ones.
 *
 * When an execution context finishes, its unfinished transfers are cancelled.
 * With a provider that does not register finalizers, such as the fiber
 * provider, call ExecutionContext::finish() at the end of each context to
 * cancel unfinished transfers and drain its task queue.
 *
 * @see https://github.com/guzzle/guzzle/blob/8.2/docs/handlers.md#concurrent-execution-contexts
 */
final class ContextualCurlMultiHandler
{
    use NonSerializableTrait;

    /** @var callable(): CurlMultiHandler */
    private $factory;

    private int $maxIdleHandlers;

    private ?CurlMultiHandler $unmanaged = null;

    /** @var \WeakMap<object, CurlMultiHandler>|null */
    private $leases;

    /** @var \WeakMap<object, true>|null */
    private $finalizingContexts;

    /** @var list<CurlMultiHandler> */
    private array $idle = [];

    /**
     * @param callable(): CurlMultiHandler $factory         Creates a handler for a context.
     * @param int                          $maxIdleHandlers Idle handlers kept for reuse.
     */
    public function __construct(callable $factory, int $maxIdleHandlers = 8)
    {
        if ($maxIdleHandlers < 0) {
            throw new InvalidArgumentException('maxIdleHandlers must be zero or greater.');
        }

        $this->factory = $factory;
        $this->maxIdleHandlers = $maxIdleHandlers;
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
        return $this->handlerForCurrentContext()($request, $options);
    }

    private function handlerForCurrentContext(): CurlMultiHandler
    {
        $context = P\ExecutionContext::current();

        if ($context === null) {
            if ($this->unmanaged === null) {
                $this->unmanaged = ($this->factory)();
            }

            return $this->unmanaged;
        }

        if ($this->leases === null) {
            $this->leases = new \WeakMap();
        }

        $handler = $this->leases[$context] ?? null;

        if ($handler !== null) {
            return $handler;
        }

        do {
            $handler = \array_pop($this->idle);
        } while ($handler !== null && $handler->isClosed());

        if ($handler === null) {
            $handler = ($this->factory)();
            $reference = \WeakReference::create($this);
            $handler->onIdle(static function (CurlMultiHandler $handler) use ($reference): void {
                $pool = $reference->get();

                if ($pool !== null) {
                    $pool->release($handler);
                }
            });
        }

        $handler->assignToCurrentContext();
        $this->leases[$context] = $handler;

        if ($this->finalizingContexts === null) {
            $this->finalizingContexts = new \WeakMap();
        }

        if (!isset($this->finalizingContexts[$context])) {
            $this->finalizingContexts[$context] = true;
            $reference = \WeakReference::create($context);

            P\ExecutionContext::onFinish(function () use ($reference): void {
                $context = $reference->get();

                if ($context === null) {
                    return;
                }

                unset($this->finalizingContexts[$context]);
                $handler = $this->leases[$context] ?? null;

                if ($handler !== null) {
                    try {
                        $handler->finishContext($context);
                    } finally {
                        unset($this->leases[$context]);
                    }
                }
            });
        }

        return $handler;
    }

    /**
     * Returns the current context's idle handler to the pool.
     */
    private function release(CurlMultiHandler $handler): void
    {
        if ($this->maxIdleHandlers === 0 && !$handler->isClosed()) {
            return;
        }

        $context = P\ExecutionContext::current();

        if ($context === null || ($this->leases[$context] ?? null) !== $handler) {
            return;
        }

        unset($this->leases[$context]);

        if (!$handler->isClosed() && \count($this->idle) < $this->maxIdleHandlers) {
            $this->idle[] = $handler;
        }
    }
}
