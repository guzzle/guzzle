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
 * current execution context.
 *
 * When an execution context finishes, its unfinished transfers are cancelled
 * and its idle handler returns to a bounded pool, so later contexts can reuse
 * its connections. Requests made outside managed contexts share one handler.
 *
 * Handlers return to the pool only when their context is finished. With a
 * provider that does not register finalizers, such as the fiber provider, call
 * ExecutionContext::finish() at the end of each context, or every context
 * creates a new handler with its own connections. The default handler keeps up
 * to 8 idle handlers.
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

        $handler = array_pop($this->idle) ?? ($this->factory)();
        $handler->assignTo($context);
        $this->leases[$context] = $handler;
        $reference = \WeakReference::create($context);

        P\ExecutionContext::onFinish(function () use ($reference): void {
            $this->release($reference->get());
        });

        return $handler;
    }

    /**
     * Cancels a finished context's transfers and returns its handler to the
     * pool when it is idle.
     */
    private function release(?object $context): void
    {
        if ($context === null || $this->leases === null) {
            return;
        }

        $handler = $this->leases[$context] ?? null;

        if ($handler === null) {
            return;
        }

        unset($this->leases[$context]);
        $handler->cancelPendingTransfers();

        if ($handler->isIdle() && \count($this->idle) < $this->maxIdleHandlers) {
            $this->idle[] = $handler;
        }
    }
}
