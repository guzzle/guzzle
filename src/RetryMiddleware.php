<?php

declare(strict_types=1);

namespace GuzzleHttp;

use GuzzleHttp\Promise as P;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Middleware that retries requests based on the boolean result of
 * invoking the provided "decider" function.
 *
 * @final
 */
class RetryMiddleware
{
    /**
     * @var callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed>
     */
    private $nextHandler;

    /**
     * @var callable(int, RequestInterface, ResponseInterface|null, mixed): bool
     */
    private $decider;

    /**
     * @var (callable(int): int)|(callable(int, ResponseInterface|null, RequestInterface): int)
     */
    private $delay;

    /**
     * @param callable(int, RequestInterface, ResponseInterface|null, mixed): bool                            $decider     Function that accepts the number of retries,
     *                                                                                                                     a request, [response], and [rejection reason]
     *                                                                                                                     and returns true if the request is to be retried.
     * @param callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed> $nextHandler Next handler to invoke.
     * @param (callable(int): int)|(callable(int, ResponseInterface|null, RequestInterface): int)|null        $delay       Function that returns the number of milliseconds to delay.
     */
    public function __construct(callable $decider, callable $nextHandler, ?callable $delay = null)
    {
        $this->decider = $decider;
        $this->nextHandler = $nextHandler;
        $this->delay = $delay ?: static function (int $retries): int {
            return (int) ((2 ** ($retries - 1)) * 1000);
        };
    }

    /**
     * @return PromiseInterface<ResponseInterface, mixed>
     */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        if (!isset($options['retries'])) {
            $options['retries'] = 0;
        } elseif (!\is_int($options['retries'])) {
            throw new \InvalidArgumentException('retries must be an integer');
        }

        /** @var PromiseInterface<ResponseInterface, mixed> */
        return ($this->nextHandler)($request, $options)
            ->then(
                $this->onFulfilled($request, $options),
                $this->onRejected($request, $options)
            );
    }

    /**
     * Execute fulfilled closure
     */
    private function onFulfilled(RequestInterface $request, array $options): callable
    {
        return function ($value) use ($request, $options) {
            if (!($this->decider)(
                $options['retries'],
                $request,
                $value,
                null
            )) {
                return $value;
            }

            return $this->doRetry($request, $options, $value);
        };
    }

    /**
     * Execute rejected closure
     */
    private function onRejected(RequestInterface $req, array $options): callable
    {
        return function ($reason) use ($req, $options): PromiseInterface {
            if (!($this->decider)(
                $options['retries'],
                $req,
                null,
                $reason
            )) {
                return P\Create::rejectionFor($reason);
            }

            /** @var PromiseInterface<mixed, mixed> */
            return $this->doRetry($req, $options);
        };
    }

    /**
     * @return PromiseInterface<ResponseInterface, mixed>
     */
    private function doRetry(RequestInterface $request, array $options, ?ResponseInterface $response = null): PromiseInterface
    {
        ++$options['retries'];
        $options['delay'] = $this->getDelay($options['retries'], $response, $request);

        return $this($request, $options);
    }

    private function getDelay(int $retries, ?ResponseInterface $response, RequestInterface $request): int
    {
        $delay = $this->delay;

        if (self::acceptsRetryContext($delay)) {
            return $delay($retries, $response, $request);
        }

        return $delay($retries);
    }

    private static function acceptsRetryContext(callable $callback): bool
    {
        $reflection = new \ReflectionFunction(\Closure::fromCallable($callback));

        return $reflection->isVariadic() || $reflection->getNumberOfParameters() >= 3;
    }
}
