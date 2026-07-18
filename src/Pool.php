<?php

declare(strict_types=1);

namespace GuzzleHttp;

use GuzzleHttp\Cookie\CookieJarInterface;
use GuzzleHttp\Promise\EachPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\PromisorInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
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
 * applying the "options" request options (if provided in the ctor). Any
 * observer callbacks in "options" (on_headers, on_trailers, on_stats,
 * progress, and allow_redirects.on_redirect) also receive the request's
 * iterable key as a trailing argument.
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
    use NonSerializableTrait;

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
     *         base_uri?: string|UriInterface,
     *         allow_redirects?: bool|array{
     *             max?: int,
     *             strict?: bool,
     *             referer?: bool,
     *             protocols?: non-empty-array<array-key, string>,
     *             on_redirect?: callable(RequestInterface, ResponseInterface, UriInterface, int|string): mixed,
     *             track_redirects?: bool
     *         },
     *         auth?: array{
     *             0: string,
     *             1: string,
     *             2?: string|null
     *         }|string|false|null,
     *         body?: resource|string|null|StreamInterface|(callable&object)|\Iterator|\Stringable,
     *         cert?: string|array{
     *             0: string,
     *             1?: string|null
     *         },
     *         cert_type?: string,
     *         connect_timeout?: int|float,
     *         cookies?: false|CookieJarInterface,
     *         crypto_method?: int,
     *         crypto_method_max?: int,
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
     *         multiplex?: string,
     *         on_headers?: callable(ResponseInterface, RequestInterface, int|string): mixed,
     *         on_stats?: callable(TransferStats, int|string): mixed,
     *         on_trailers?: callable(array<string, list<string>>, ResponseInterface, RequestInterface, int|string): mixed,
     *         progress?: callable(int, int, int, int, int|string): mixed,
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
     *         response_factory?: ResponseFactoryInterface,
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
     *     fulfilled?: callable(ResponseInterface, int|string, PromiseInterface<mixed, mixed>): mixed,
     *     rejected?: callable(mixed, int|string, PromiseInterface<mixed, mixed>): mixed
     * } $config Pool configuration.
     */
    public function __construct(
        ClientInterface $client,
        iterable $requests,
        #[\SensitiveParameter]
        array $config = []
    ) {
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
                $keyedOpts = self::keyedRequestOptions($opts, $key);

                if ($rfn instanceof RequestInterface) {
                    yield $key => $client->sendAsync($rfn, $keyedOpts);
                } elseif (\is_callable($rfn)) {
                    yield $key => $rfn($keyedOpts);
                } else {
                    throw new \InvalidArgumentException('Each value yielded by the iterator must be a Psr\Http\Message\RequestInterface or a callable that returns a promise that fulfills with a Psr\Http\Message\ResponseInterface object.');
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
     *         base_uri?: string|UriInterface,
     *         allow_redirects?: bool|array{
     *             max?: int,
     *             strict?: bool,
     *             referer?: bool,
     *             protocols?: non-empty-array<array-key, string>,
     *             on_redirect?: callable(RequestInterface, ResponseInterface, UriInterface, int|string): mixed,
     *             track_redirects?: bool
     *         },
     *         auth?: array{
     *             0: string,
     *             1: string,
     *             2?: string|null
     *         }|string|false|null,
     *         body?: resource|string|null|StreamInterface|(callable&object)|\Iterator|\Stringable,
     *         cert?: string|array{
     *             0: string,
     *             1?: string|null
     *         },
     *         cert_type?: string,
     *         connect_timeout?: int|float,
     *         cookies?: false|CookieJarInterface,
     *         crypto_method?: int,
     *         crypto_method_max?: int,
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
     *         multiplex?: string,
     *         on_headers?: callable(ResponseInterface, RequestInterface, int|string): mixed,
     *         on_stats?: callable(TransferStats, int|string): mixed,
     *         on_trailers?: callable(array<string, list<string>>, ResponseInterface, RequestInterface, int|string): mixed,
     *         progress?: callable(int, int, int, int, int|string): mixed,
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
     *         response_factory?: ResponseFactoryInterface,
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
     *     fulfilled?: callable(ResponseInterface, int|string): mixed,
     *     rejected?: callable(mixed, int|string): mixed
     * } $options Passes through the options available in {@see Pool::__construct}.
     *
     * @return array<array-key, mixed> Returns an array containing the response or rejection reason in the same order that the requests were sent.
     *
     * @throws \InvalidArgumentException if the event format is incorrect.
     */
    public static function batch(
        ClientInterface $client,
        iterable $requests,
        #[\SensitiveParameter]
        array $options = []
    ): array {
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
            $options[$name] = static function (
                #[\SensitiveParameter]
                $v,
                $k
            ) use (&$results): void {
                $results[$k] = $v;
            };
        } else {
            $currentFn = $options[$name];
            $options[$name] = static function (
                #[\SensitiveParameter]
                $v,
                $k
            ) use (&$results, $currentFn): void {
                $currentFn($v, $k);
                $results[$k] = $v;
            };
        }
    }

    /**
     * Returns the request options with any observer callbacks wrapped so that
     * they also receive the request's iterable key as a trailing argument.
     *
     * @param array{
     *     base_uri?: string|UriInterface,
     *     allow_redirects?: bool|array{
     *         max?: int,
     *         strict?: bool,
     *         referer?: bool,
     *         protocols?: non-empty-array<array-key, string>,
     *         on_redirect?: callable(RequestInterface, ResponseInterface, UriInterface, int|string): mixed,
     *         track_redirects?: bool
     *     },
     *     auth?: array{
     *         0: string,
     *         1: string,
     *         2?: string|null
     *     }|string|false|null,
     *     body?: resource|string|null|StreamInterface|(callable&object)|\Iterator|\Stringable,
     *     cert?: string|array{
     *         0: string,
     *         1?: string|null
     *     },
     *     cert_type?: string,
     *     connect_timeout?: int|float,
     *     cookies?: false|CookieJarInterface,
     *     crypto_method?: int,
     *     crypto_method_max?: int,
     *     debug?: bool|resource,
     *     decode_content?: bool|string,
     *     delay?: int|float,
     *     expect?: bool|int,
     *     form_params?: array<array-key, string|int|float|bool|null|array>,
     *     force_ip_resolve?: string,
     *     headers?: array<array-key, string|non-empty-array<array-key, string>>|null,
     *     http_errors?: bool,
     *     idn_conversion?: bool|int|null,
     *     json?: mixed,
     *     multipart?: array<array-key, array{
     *         name: string|int,
     *         contents: mixed,
     *         headers?: array<array-key, string>,
     *         filename?: string
     *     }>,
     *     multiplex?: string,
     *     on_headers?: callable(ResponseInterface, RequestInterface, int|string): mixed,
     *     on_stats?: callable(TransferStats, int|string): mixed,
     *     on_trailers?: callable(array<string, list<string>>, ResponseInterface, RequestInterface, int|string): mixed,
     *     progress?: callable(int, int, int, int, int|string): mixed,
     *     protocols?: non-empty-array<array-key, string>,
     *     proxy?: string|array{
     *         http?: string|null,
     *         https?: string|null,
     *         no?: string|array<array-key, string>|null
     *     },
     *     query?: array<array-key, mixed>|string,
     *     read_timeout?: int|float,
     *     retries?: int,
     *     request_factory?: RequestFactoryInterface,
     *     response_factory?: ResponseFactoryInterface,
     *     sink?: resource|string|StreamInterface,
     *     ssl_key?: string|array{
     *         0: string,
     *         1?: string|null
     *     },
     *     ssl_key_type?: string,
     *     stream?: bool,
     *     stream_factory?: StreamFactoryInterface,
     *     stream_context?: array<array-key, mixed>,
     *     synchronous?: bool,
     *     timeout?: int|float,
     *     uri_factory?: UriFactoryInterface,
     *     verify?: bool|string,
     *     version?: string|int|float,
     *     curl?: array<int|string, mixed>,
     *     ...
     * } $options
     * @param int|string $key
     *
     * @return array{
     *     base_uri?: string|UriInterface,
     *     allow_redirects?: bool|array{
     *         max?: int,
     *         strict?: bool,
     *         referer?: bool,
     *         protocols?: non-empty-array<array-key, string>,
     *         on_redirect?: callable(RequestInterface, ResponseInterface, UriInterface): mixed,
     *         track_redirects?: bool
     *     },
     *     auth?: array{
     *         0: string,
     *         1: string,
     *         2?: string|null
     *     }|string|false|null,
     *     body?: resource|string|null|StreamInterface|(callable&object)|\Iterator|\Stringable,
     *     cert?: string|array{
     *         0: string,
     *         1?: string|null
     *     },
     *     cert_type?: string,
     *     connect_timeout?: int|float,
     *     cookies?: false|CookieJarInterface,
     *     crypto_method?: int,
     *     crypto_method_max?: int,
     *     debug?: bool|resource,
     *     decode_content?: bool|string,
     *     delay?: int|float,
     *     expect?: bool|int,
     *     form_params?: array<array-key, string|int|float|bool|null|array>,
     *     force_ip_resolve?: string,
     *     headers?: array<array-key, string|non-empty-array<array-key, string>>|null,
     *     http_errors?: bool,
     *     idn_conversion?: bool|int|null,
     *     json?: mixed,
     *     multipart?: array<array-key, array{
     *         name: string|int,
     *         contents: mixed,
     *         headers?: array<array-key, string>,
     *         filename?: string
     *     }>,
     *     multiplex?: string,
     *     on_headers?: callable(ResponseInterface, RequestInterface): mixed,
     *     on_stats?: callable(TransferStats): mixed,
     *     on_trailers?: callable(array<string, list<string>>, ResponseInterface, RequestInterface): mixed,
     *     progress?: callable(int, int, int, int): mixed,
     *     protocols?: non-empty-array<array-key, string>,
     *     proxy?: string|array{
     *         http?: string|null,
     *         https?: string|null,
     *         no?: string|array<array-key, string>|null
     *     },
     *     query?: array<array-key, mixed>|string,
     *     read_timeout?: int|float,
     *     retries?: int,
     *     request_factory?: RequestFactoryInterface,
     *     response_factory?: ResponseFactoryInterface,
     *     sink?: resource|string|StreamInterface,
     *     ssl_key?: string|array{
     *         0: string,
     *         1?: string|null
     *     },
     *     ssl_key_type?: string,
     *     stream?: bool,
     *     stream_factory?: StreamFactoryInterface,
     *     stream_context?: array<array-key, mixed>,
     *     synchronous?: bool,
     *     timeout?: int|float,
     *     uri_factory?: UriFactoryInterface,
     *     verify?: bool|string,
     *     version?: string|int|float,
     *     curl?: array<int|string, mixed>,
     *     ...
     * }
     */
    private static function keyedRequestOptions(array $options, $key): array
    {
        if (\is_array($options['allow_redirects'] ?? null)
            && \is_callable($options['allow_redirects']['on_redirect'] ?? null)
        ) {
            $onRedirect = $options['allow_redirects']['on_redirect'];
            $options['allow_redirects']['on_redirect'] = static function (
                #[\SensitiveParameter]
                RequestInterface $request,
                #[\SensitiveParameter]
                ResponseInterface $response,
                #[\SensitiveParameter]
                UriInterface $uri
            ) use ($onRedirect, $key): void {
                $onRedirect($request, $response, $uri, $key);
            };
        }

        if (\is_callable($options['on_headers'] ?? null)) {
            $onHeaders = $options['on_headers'];
            $options['on_headers'] = static function (
                #[\SensitiveParameter]
                ResponseInterface $response,
                #[\SensitiveParameter]
                RequestInterface $request
            ) use ($onHeaders, $key): void {
                $onHeaders($response, $request, $key);
            };
        }

        if (\is_callable($options['on_stats'] ?? null)) {
            $onStats = $options['on_stats'];
            $options['on_stats'] = static function (TransferStats $stats) use ($onStats, $key): void {
                $onStats($stats, $key);
            };
        }

        if (\is_callable($options['on_trailers'] ?? null)) {
            $onTrailers = $options['on_trailers'];
            $options['on_trailers'] = static function (
                array $trailers,
                #[\SensitiveParameter]
                ResponseInterface $response,
                #[\SensitiveParameter]
                RequestInterface $request
            ) use ($onTrailers, $key): void {
                $onTrailers($trailers, $response, $request, $key);
            };
        }

        if (\is_callable($options['progress'] ?? null)) {
            $progress = $options['progress'];
            $options['progress'] = static function (int $downloadTotal, int $downloadedBytes, int $uploadTotal, int $uploadedBytes) use ($progress, $key) {
                return $progress($downloadTotal, $downloadedBytes, $uploadTotal, $uploadedBytes, $key);
            };
        }

        return $options;
    }
}
