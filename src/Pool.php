<?php

declare(strict_types=1);

namespace GuzzleHttp;

use GuzzleHttp\Cookie\CookieJarInterface;
use GuzzleHttp\Promise\EachPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\PromisorInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\UriInterface;

/**
 * Sends an iterator of requests concurrently using a capped pool size.
 *
 * The pool will read from an iterator until it is cancelled or until the
 * iterator is consumed. When a request is yielded, the request is sent after
 * applying the "options" request options (if provided in the ctor).
 *
 * When a function is yielded by the iterator, the function is provided the
 * "options" array that should be merged on top of any existing options, and
 * the function MUST then return a response or a wait-able response promise.
 *
 * @final
 *
 * @implements PromisorInterface<mixed, mixed>
 */
class Pool implements PromisorInterface
{
    /**
     * @var EachPromise<array-key, ResponseInterface, mixed>
     */
    private EachPromise $each;

    /**
     * @param ClientInterface                                                                                                                         $client   Client used to send the requests.
     * @param iterable<array-key, RequestInterface|callable(array<array-key, mixed>): (ResponseInterface|PromiseInterface<ResponseInterface, mixed>)> $requests Requests or functions that return responses or response promises.
     * @param array{
     *     concurrency?: int|(callable(int): int),
     *     options?: array{
     *         handler?: callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed>,
     *         base_uri?: string|UriInterface,
     *         allow_redirects?: bool|array{
     *             max?: int,
     *             strict?: bool,
     *             referer?: bool,
     *             protocols?: non-empty-array<array-key, string>,
     *             on_redirect?: callable(RequestInterface, ResponseInterface, UriInterface): mixed,
     *             track_redirects?: bool
     *         },
     *         auth?: array{
     *             0: string,
     *             1: string,
     *             2?: string|null
     *         }|string|false|null,
     *         body?: resource|string|null|int|float|bool|StreamInterface|(callable&object)|\Iterator|\Stringable,
     *         cert?: string|array{
     *             0: string,
     *             1?: string|null
     *         },
     *         cert_type?: string,
     *         connect_timeout?: int|float,
     *         cookies?: false|CookieJarInterface,
     *         crypto_method?: int,
     *         debug?: bool|resource,
     *         decode_content?: bool|string,
     *         delay?: int|float,
     *         expect?: bool|int,
     *         form_params?: array<array-key, string|int|float|bool|null|array>,
     *         force_ip_resolve?: string,
     *         headers?: array<array-key, string|non-empty-array<array-key, string>>|null,
     *         http_errors?: bool,
     *         idn_conversion?: bool|int|null,
     *         json?: mixed,
     *         multipart?: array<array-key, array{
     *             name: string|int,
     *             contents: mixed,
     *             headers?: array<array-key, string>,
     *             filename?: string
     *         }>,
     *         on_headers?: callable(ResponseInterface, RequestInterface): mixed,
     *         on_stats?: callable(TransferStats): mixed,
     *         progress?: callable(int, int, int, int): mixed,
     *         protocols?: non-empty-array<array-key, string>,
     *         proxy?: string|array{
     *             http?: string|null,
     *             https?: string|null,
     *             no?: string|array<array-key, string>|null
     *         },
     *         query?: array<array-key, mixed>|string,
     *         read_timeout?: int|float,
     *         retries?: int,
     *         request_factory?: RequestFactoryInterface,
     *         sink?: resource|string|StreamInterface,
     *         ssl_key?: string|array{
     *             0: string,
     *             1?: string|null
     *         },
     *         ssl_key_type?: string,
     *         stream?: bool,
     *         stream_factory?: StreamFactoryInterface,
     *         stream_context?: array<array-key, mixed>,
     *         synchronous?: bool,
     *         timeout?: int|float,
     *         uri_factory?: UriFactoryInterface,
     *         verify?: bool|string,
     *         version?: string|int|float,
     *         curl?: array<int|string, mixed>,
     *         ...
     *     },
     *     fulfilled?: callable(ResponseInterface, array-key, PromiseInterface<mixed, mixed>): mixed,
     *     rejected?: callable(mixed, array-key, PromiseInterface<mixed, mixed>): mixed
     * } $config Pool configuration.
     */
    public function __construct(ClientInterface $client, iterable $requests, array $config = [])
    {
        if (!isset($config['concurrency'])) {
            $config['concurrency'] = 25;
        }

        if (isset($config['options'])) {
            $opts = $config['options'];
            unset($config['options']);
        } else {
            $opts = [];
        }

        $requestGenerator = static function () use ($requests, $client, $opts): \Generator {
            foreach ($requests as $key => $rfn) {
                if ($rfn instanceof RequestInterface) {
                    yield $key => $client->sendAsync($rfn, $opts);
                } elseif (\is_callable($rfn)) {
                    yield $key => $rfn($opts);
                } else {
                    throw new \InvalidArgumentException('Each value yielded by the iterator must be a Psr7\Http\Message\RequestInterface or a callable that returns a promise that fulfills with a Psr7\Message\Http\ResponseInterface object.');
                }
            }
        };

        $this->each = new EachPromise($requestGenerator(), $config);
    }

    /**
     * Get promise
     *
     * @return PromiseInterface<mixed, mixed>
     */
    public function promise(): PromiseInterface
    {
        return $this->each->promise();
    }

    /**
     * Sends multiple requests concurrently and returns an array of responses
     * and exceptions that uses the same ordering as the provided requests.
     *
     * IMPORTANT: This method keeps every request and response in memory, and
     * as such, is NOT recommended when sending a large number or an
     * indeterminate number of requests concurrently.
     *
     * @param ClientInterface                                                                                                                         $client   Client used to send the requests
     * @param iterable<array-key, RequestInterface|callable(array<array-key, mixed>): (ResponseInterface|PromiseInterface<ResponseInterface, mixed>)> $requests Requests or functions that return responses or response promises.
     * @param array{
     *     concurrency?: int|(callable(int): int),
     *     options?: array{
     *         handler?: callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed>,
     *         base_uri?: string|UriInterface,
     *         allow_redirects?: bool|array{
     *             max?: int,
     *             strict?: bool,
     *             referer?: bool,
     *             protocols?: non-empty-array<array-key, string>,
     *             on_redirect?: callable(RequestInterface, ResponseInterface, UriInterface): mixed,
     *             track_redirects?: bool
     *         },
     *         auth?: array{
     *             0: string,
     *             1: string,
     *             2?: string|null
     *         }|string|false|null,
     *         body?: resource|string|null|int|float|bool|StreamInterface|(callable&object)|\Iterator|\Stringable,
     *         cert?: string|array{
     *             0: string,
     *             1?: string|null
     *         },
     *         cert_type?: string,
     *         connect_timeout?: int|float,
     *         cookies?: false|CookieJarInterface,
     *         crypto_method?: int,
     *         debug?: bool|resource,
     *         decode_content?: bool|string,
     *         delay?: int|float,
     *         expect?: bool|int,
     *         form_params?: array<array-key, string|int|float|bool|null|array>,
     *         force_ip_resolve?: string,
     *         headers?: array<array-key, string|non-empty-array<array-key, string>>|null,
     *         http_errors?: bool,
     *         idn_conversion?: bool|int|null,
     *         json?: mixed,
     *         multipart?: array<array-key, array{
     *             name: string|int,
     *             contents: mixed,
     *             headers?: array<array-key, string>,
     *             filename?: string
     *         }>,
     *         on_headers?: callable(ResponseInterface, RequestInterface): mixed,
     *         on_stats?: callable(TransferStats): mixed,
     *         progress?: callable(int, int, int, int): mixed,
     *         protocols?: non-empty-array<array-key, string>,
     *         proxy?: string|array{
     *             http?: string|null,
     *             https?: string|null,
     *             no?: string|array<array-key, string>|null
     *         },
     *         query?: array<array-key, mixed>|string,
     *         read_timeout?: int|float,
     *         retries?: int,
     *         request_factory?: RequestFactoryInterface,
     *         sink?: resource|string|StreamInterface,
     *         ssl_key?: string|array{
     *             0: string,
     *             1?: string|null
     *         },
     *         ssl_key_type?: string,
     *         stream?: bool,
     *         stream_factory?: StreamFactoryInterface,
     *         stream_context?: array<array-key, mixed>,
     *         synchronous?: bool,
     *         timeout?: int|float,
     *         uri_factory?: UriFactoryInterface,
     *         verify?: bool|string,
     *         version?: string|int|float,
     *         curl?: array<int|string, mixed>,
     *         ...
     *     },
     *     fulfilled?: callable(ResponseInterface, array-key): mixed,
     *     rejected?: callable(mixed, array-key): mixed
     * } $options Passes through the options available in {@see Pool::__construct}.
     *
     * @return array<array-key, mixed> Returns an array containing the response or rejection reason in the same order that the requests were sent.
     *
     * @throws \InvalidArgumentException if the event format is incorrect.
     */
    public static function batch(ClientInterface $client, iterable $requests, array $options = []): array
    {
        $res = [];
        self::cmpCallback($options, 'fulfilled', $res);
        self::cmpCallback($options, 'rejected', $res);
        $pool = new static($client, $requests, $options);
        $pool->promise()->wait();
        \ksort($res);

        return $res;
    }

    /**
     * Execute callback(s)
     */
    private static function cmpCallback(array &$options, string $name, array &$results): void
    {
        if (!isset($options[$name])) {
            $options[$name] = static function ($v, $k) use (&$results): void {
                $results[$k] = $v;
            };
        } else {
            $currentFn = $options[$name];
            $options[$name] = static function ($v, $k) use (&$results, $currentFn): void {
                $currentFn($v, $k);
                $results[$k] = $v;
            };
        }
    }
}
