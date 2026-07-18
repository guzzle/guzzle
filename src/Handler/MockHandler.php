<?php

declare(strict_types=1);

namespace GuzzleHttp\Handler;

use GuzzleHttp\Exception\InvalidArgumentException;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\NonSerializableTrait;
use GuzzleHttp\Promise as P;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\TransferStats;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Handler that returns responses or rejection reasons from a queue.
 */
final class MockHandler implements \Countable
{
    use NonSerializableTrait;

    /**
     * @var list<ResponseInterface|\Throwable|PromiseInterface<ResponseInterface, mixed>|callable(RequestInterface, array<array-key, mixed>): (ResponseInterface|\Throwable|PromiseInterface<ResponseInterface, mixed>)>
     */
    private array $queue = [];

    private ?RequestInterface $lastRequest = null;

    /**
     * @var array<array-key, mixed>
     */
    private array $lastOptions = [];

    /**
     * @var (callable(ResponseInterface|null): mixed)|null
     */
    private $onFulfilled;

    /**
     * @var (callable(mixed): mixed)|null
     */
    private $onRejected;

    /**
     * Creates a new MockHandler that uses the default handler stack list of
     * middlewares.
     *
     * @param array<array-key, ResponseInterface|\Throwable|PromiseInterface<ResponseInterface, mixed>|callable(RequestInterface, array<array-key, mixed>): (ResponseInterface|\Throwable|PromiseInterface<ResponseInterface, mixed>)>|null $queue       Array of responses, promises, callables, or throwables.
     * @param (callable(ResponseInterface|null): mixed)|null                                                                                                                                                                                $onFulfilled Callback to invoke when the return value is fulfilled.
     * @param (callable(mixed): mixed)|null                                                                                                                                                                                                 $onRejected  Callback to invoke when the return value is rejected.
     *
     * @return HandlerStack<callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed>>
     */
    public static function createWithMiddleware(
        #[\SensitiveParameter]
        ?array $queue = null,
        ?callable $onFulfilled = null,
        ?callable $onRejected = null
    ): HandlerStack {
        return HandlerStack::create(new self($queue, $onFulfilled, $onRejected));
    }

    /**
     * The passed in value must be an array of
     * {@see ResponseInterface} objects, throwables, callables, or promises.
     *
     * @param array<array-key, ResponseInterface|\Throwable|PromiseInterface<ResponseInterface, mixed>|callable(RequestInterface, array<array-key, mixed>): (ResponseInterface|\Throwable|PromiseInterface<ResponseInterface, mixed>)>|null $queue       The parameters to be passed to the append function, as an indexed array.
     * @param (callable(ResponseInterface|null): mixed)|null                                                                                                                                                                                $onFulfilled Callback to invoke when the return value is fulfilled.
     * @param (callable(mixed): mixed)|null                                                                                                                                                                                                 $onRejected  Callback to invoke when the return value is rejected.
     */
    public function __construct(
        #[\SensitiveParameter]
        ?array $queue = null,
        ?callable $onFulfilled = null,
        ?callable $onRejected = null
    ) {
        $this->onFulfilled = $onFulfilled;
        $this->onRejected = $onRejected;

        if ($queue) {
            // array_values included for BC
            $this->append(...array_values($queue));
        }
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
        if (!$this->queue) {
            // Test-setup error (more requests made than responses queued);
            // intentionally a bare SPL exception, not a GuzzleException.
            throw new \OutOfBoundsException('Mock queue is empty');
        }

        if (isset($options['delay']) && \is_numeric($options['delay'])) {
            \usleep((int) ($options['delay'] * 1000));
        }

        if (isset($options['on_stats']) && !\is_callable($options['on_stats'])) {
            throw new InvalidArgumentException('on_stats must be callable');
        }

        $this->lastRequest = $request;
        $this->lastOptions = $options;
        $response = \array_shift($this->queue);
        $onHeaders = null;
        $onHeadersResponse = null;

        if (isset($options['on_headers'])) {
            if (!\is_callable($options['on_headers'])) {
                throw new InvalidArgumentException('on_headers must be callable');
            }

            $onHeaders = $options['on_headers'];
        }

        if (\is_callable($response)) {
            $response = $response($request, $options);
        }

        $response = $response instanceof \Throwable
            ? P\Create::rejectionFor($response)
            : P\Create::promiseFor($response);

        if (\is_callable($onHeaders)) {
            $response = $response->then(
                static function (
                    #[\SensitiveParameter]
                    $value
                ) use ($onHeaders, $request, &$onHeadersResponse) {
                    if (!$value instanceof ResponseInterface) {
                        return $value;
                    }

                    try {
                        $onHeaders($value, $request);
                    } catch (\Throwable $e) {
                        $msg = 'An error was encountered during the on_headers event';
                        $onHeadersResponse = $value;

                        throw new ResponseException($msg, $request, $value, $e);
                    }

                    return $value;
                }
            );
        }

        $promise = $response->then(
            function (
                #[\SensitiveParameter]
                $value
            ) use ($request, $options): ?ResponseInterface {
                /** @var ResponseInterface|null $value */
                $this->invokeStats($request, $options, $value);
                if ($this->onFulfilled) {
                    ($this->onFulfilled)($value);
                }

                if ($value !== null && isset($options['sink'])) {
                    $contents = (string) $value->getBody();
                    $sink = $options['sink'];

                    if (\is_resource($sink)) {
                        \fwrite($sink, $contents);
                    } elseif (\is_string($sink)) {
                        \file_put_contents($sink, $contents);
                    } elseif ($sink instanceof StreamInterface) {
                        $sink->write($contents);
                    }
                }

                return $value;
            },
            function (
                #[\SensitiveParameter]
                $reason
            ) use ($request, $options, &$onHeadersResponse): PromiseInterface {
                $this->invokeStats($request, $options, $onHeadersResponse, $reason);
                if ($this->onRejected) {
                    ($this->onRejected)($reason);
                }

                return P\Create::rejectionFor($reason);
            }
        );

        /** @var PromiseInterface<ResponseInterface, mixed> $promise */
        return $promise;
    }

    /**
     * Adds one or more variadic requests, exceptions, callables, or promises
     * to the queue.
     *
     * @param mixed ...$values Responses, promises, throwables, or request-aware callables.
     */
    public function append(
        #[\SensitiveParameter]
        ...$values
    ): void {
        foreach ($values as $value) {
            if ($value instanceof ResponseInterface
                || $value instanceof \Throwable
                || $value instanceof PromiseInterface
                || \is_callable($value)
            ) {
                $this->queue[] = $value;
            } else {
                throw new \TypeError('Expected a Response, Promise, Throwable or callable. Found '.\get_debug_type($value));
            }
        }
    }

    /**
     * Get the last received request.
     */
    public function getLastRequest(): ?RequestInterface
    {
        return $this->lastRequest;
    }

    /**
     * Get the last received request options.
     */
    public function getLastOptions(): array
    {
        return $this->lastOptions;
    }

    /**
     * Returns the number of remaining items in the queue.
     */
    public function count(): int
    {
        return \count($this->queue);
    }

    public function reset(): void
    {
        $this->queue = [];
    }

    /**
     * @param mixed $reason Promise or reason.
     */
    private function invokeStats(
        #[\SensitiveParameter]
        RequestInterface $request,
        #[\SensitiveParameter]
        array $options,
        #[\SensitiveParameter]
        ?ResponseInterface $response = null,
        #[\SensitiveParameter]
        $reason = null
    ): void {
        if (isset($options['on_stats'])) {
            $transferTime = $options['transfer_time'] ?? 0.0;
            if (!\is_int($transferTime) && !\is_float($transferTime) && (!\is_string($transferTime) || !\is_numeric($transferTime))) {
                throw new InvalidArgumentException('transfer_time must be a number of seconds');
            }

            $stats = new TransferStats($request, $response, (float) $transferTime, $reason);
            ($options['on_stats'])($stats);
        }
    }
}
