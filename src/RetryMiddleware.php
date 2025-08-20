<?php

declare(strict_types=1);

namespace GuzzleHttp;

use GuzzleHttp\Promise as P;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Exception\ConnectException;
use InvalidArgumentException;

/**
 * Enhanced RetryMiddleware with:
 * - configurable max retries
 * - automatic retry on connection failures, 5xx, 429
 * - honors Retry-After header when present
 * - exponential backoff with customizable base delay
 */
final class RetryMiddleware
{
    /** @var callable */
    private $decider;

    /** @var callable */
    private $nextHandler;

    /** @var callable */
    private $delay;

    /** Maximum retries */
    private int $maxRetries;

    /** Base delay in milliseconds */
    private int $baseDelay;

    public function __construct(
        callable $decider,
        callable $nextHandler,
        ?callable $delay = null,
        int $maxRetries = 3,
        int $baseDelay = 100
    ) {
        if ($maxRetries < 0) {
            throw new InvalidArgumentException('maxRetries must be >= 0');
        }

        $this->maxRetries = $maxRetries;
        $this->baseDelay = $baseDelay;
        $this->nextHandler = $nextHandler;
        $this->decider = $decider;
        $this->delay = $delay ?: [$this, 'exponentialDelay'];
    }

    /**
     * Default exponential backoff delay.
     */
    public function exponentialDelay(int $retries, ?ResponseInterface $response = null): int
    {
        return (int) ($this->baseDelay * (2 ** ($retries - 1)));
    }

    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        if (!isset($options['retries'])) {
            $options['retries'] = 0;
        }

        $next = $this->nextHandler;

        return $next($request, $options)->then(
            $this->onFulfilled($request, $options),
            $this->onRejected($request, $options)
        );
    }

    private function onFulfilled(RequestInterface $request, array $options): callable
    {
        return function (ResponseInterface $response) use ($request, $options) {
            $retries = $options['retries'];
            if ($retries >= $this->maxRetries) {
                return $response;
            }

            // Check Retry-After header before deciding backoff
            if ($response->hasHeader('Retry-After')) {
                return $this->retryRequest($request, $options, $response);
            }

            if (($this->decider)($retries, $request, $response, null)) {
                return $this->retryRequest($request, $options, $response);
            }

            return $response;
        };
    }

    private function onRejected(RequestInterface $request, array $options): callable
    {
        return function ($reason) use ($request, $options) {
            $retries = $options['retries'];
            if ($retries >= $this->maxRetries) {
                return P\Create::rejectionFor($reason);
            }

            if (($this->decider)($retries, $request, null, $reason)) {
                return $this->retryRequest($request, $options);
            }

            return P\Create::rejectionFor($reason);
        };
    }

    private function retryRequest(
        RequestInterface $request,
        array $options,
        ?ResponseInterface $response = null
    ): PromiseInterface {
        $options['retries'] = ($options['retries'] ?? 0) + 1;

        // Compute delay
        if ($response && $response->hasHeader('Retry-After')) {
            $ra = $response->getHeaderLine('Retry-After');
            if (is_numeric($ra)) {
                $options['delay'] = (int)$ra * 1000;
            } else {
                $ts = strtotime($ra);
                $delta = $ts !== false ? max(0, $ts - time()) * 1000 : 0;
                $options['delay'] = $delta;
            }
        } else {
            $delayFn = $this->delay;
            $options['delay'] = $delayFn($options['retries'], $response);
        }

        return ($this)($request, $options);
    }
}
