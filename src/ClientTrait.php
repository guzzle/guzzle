<?php

namespace GuzzleHttp;

use GuzzleHttp\Cookie\CookieJarInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\UriInterface;

/**
 * Client interface for sending HTTP requests.
 */
trait ClientTrait
{
    /**
     * Create and send an HTTP request.
     *
     * Use an absolute path to override the base path of the client, or a
     * relative path to append to the base path of the client. The URL can
     * contain the query string as well.
     *
     * @param string              $method HTTP method.
     * @param string|UriInterface $uri    URI object or string.
     * @param array{
     *     handler?: callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed>,
     *     base_uri?: string|UriInterface,
     *     allow_redirects?: bool|array{
     *         max?: int,
     *         strict?: bool,
     *         referer?: bool,
     *         protocols?: array<array-key, string>,
     *         on_redirect?: callable(RequestInterface, ResponseInterface, UriInterface): mixed,
     *         track_redirects?: bool
     *     },
     *     auth?: array{
     *         0: string,
     *         1: string,
     *         2?: string
     *     }|null,
     *     body?: mixed,
     *     cert?: string|array{
     *         0: string,
     *         1?: string
     *     },
     *     cert_type?: string,
     *     connect_timeout?: int|float,
     *     cookies?: bool|CookieJarInterface,
     *     crypto_method?: int,
     *     debug?: bool|resource,
     *     decode_content?: bool|string,
     *     delay?: int|float,
     *     expect?: bool|int,
     *     form_params?: array<array-key, mixed>,
     *     force_ip_resolve?: string,
     *     headers?: array<array-key, string|array<array-key, string>>|null,
     *     http_errors?: bool,
     *     idn_conversion?: bool|int,
     *     json?: mixed,
     *     multipart?: array<array-key, array{
     *         name: string,
     *         contents: mixed,
     *         headers?: array<array-key, string|array<array-key, string>>,
     *         filename?: string
     *     }>,
     *     on_headers?: callable(ResponseInterface, RequestInterface): mixed,
     *     on_stats?: callable(TransferStats): mixed,
     *     progress?: callable(int|float, int|float, int|float, int|float): mixed,
     *     protocols?: array<array-key, string>,
     *     proxy?: string|array{
     *         http?: string,
     *         https?: string,
     *         no?: string|array<array-key, string>
     *     },
     *     query?: array<array-key, mixed>|string,
     *     read_timeout?: int|float,
     *     request_factory?: RequestFactoryInterface,
     *     sink?: resource|string|StreamInterface,
     *     ssl_key?: string|array{
     *         0: string,
     *         1?: string
     *     },
     *     ssl_key_type?: string,
     *     stream?: bool,
     *     stream_factory?: StreamFactoryInterface,
     *     stream_context?: array<array-key, mixed>,
     *     synchronous?: bool,
     *     timeout?: int|float,
     *     uri_factory?: UriFactoryInterface,
     *     verify?: bool|string,
     *     version?: string|float,
     *     curl?: array<int|string, mixed>,
     *     ...
     * } $options Request options to apply.
     *
     * @throws GuzzleException
     */
    abstract public function request(string $method, $uri, array $options = []): ResponseInterface;

    /**
     * Create and send an HTTP GET request.
     *
     * Use an absolute path to override the base path of the client, or a
     * relative path to append to the base path of the client. The URL can
     * contain the query string as well.
     *
     * @param string|UriInterface $uri URI object or string.
     * @param array{
     *     handler?: callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed>,
     *     base_uri?: string|UriInterface,
     *     allow_redirects?: bool|array{
     *         max?: int,
     *         strict?: bool,
     *         referer?: bool,
     *         protocols?: array<array-key, string>,
     *         on_redirect?: callable(RequestInterface, ResponseInterface, UriInterface): mixed,
     *         track_redirects?: bool
     *     },
     *     auth?: array{
     *         0: string,
     *         1: string,
     *         2?: string
     *     }|null,
     *     body?: mixed,
     *     cert?: string|array{
     *         0: string,
     *         1?: string
     *     },
     *     cert_type?: string,
     *     connect_timeout?: int|float,
     *     cookies?: bool|CookieJarInterface,
     *     crypto_method?: int,
     *     debug?: bool|resource,
     *     decode_content?: bool|string,
     *     delay?: int|float,
     *     expect?: bool|int,
     *     form_params?: array<array-key, mixed>,
     *     force_ip_resolve?: string,
     *     headers?: array<array-key, string|array<array-key, string>>|null,
     *     http_errors?: bool,
     *     idn_conversion?: bool|int,
     *     json?: mixed,
     *     multipart?: array<array-key, array{
     *         name: string,
     *         contents: mixed,
     *         headers?: array<array-key, string|array<array-key, string>>,
     *         filename?: string
     *     }>,
     *     on_headers?: callable(ResponseInterface, RequestInterface): mixed,
     *     on_stats?: callable(TransferStats): mixed,
     *     progress?: callable(int|float, int|float, int|float, int|float): mixed,
     *     protocols?: array<array-key, string>,
     *     proxy?: string|array{
     *         http?: string,
     *         https?: string,
     *         no?: string|array<array-key, string>
     *     },
     *     query?: array<array-key, mixed>|string,
     *     read_timeout?: int|float,
     *     request_factory?: RequestFactoryInterface,
     *     sink?: resource|string|StreamInterface,
     *     ssl_key?: string|array{
     *         0: string,
     *         1?: string
     *     },
     *     ssl_key_type?: string,
     *     stream?: bool,
     *     stream_factory?: StreamFactoryInterface,
     *     stream_context?: array<array-key, mixed>,
     *     synchronous?: bool,
     *     timeout?: int|float,
     *     uri_factory?: UriFactoryInterface,
     *     verify?: bool|string,
     *     version?: string|float,
     *     curl?: array<int|string, mixed>,
     *     ...
     * } $options Request options to apply.
     *
     * @throws GuzzleException
     */
    public function get($uri, array $options = []): ResponseInterface
    {
        return $this->request('GET', $uri, $options);
    }

    /**
     * Create and send an HTTP HEAD request.
     *
     * Use an absolute path to override the base path of the client, or a
     * relative path to append to the base path of the client. The URL can
     * contain the query string as well.
     *
     * @param string|UriInterface $uri URI object or string.
     * @param array{
     *     handler?: callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed>,
     *     base_uri?: string|UriInterface,
     *     allow_redirects?: bool|array{
     *         max?: int,
     *         strict?: bool,
     *         referer?: bool,
     *         protocols?: array<array-key, string>,
     *         on_redirect?: callable(RequestInterface, ResponseInterface, UriInterface): mixed,
     *         track_redirects?: bool
     *     },
     *     auth?: array{
     *         0: string,
     *         1: string,
     *         2?: string
     *     }|null,
     *     body?: mixed,
     *     cert?: string|array{
     *         0: string,
     *         1?: string
     *     },
     *     cert_type?: string,
     *     connect_timeout?: int|float,
     *     cookies?: bool|CookieJarInterface,
     *     crypto_method?: int,
     *     debug?: bool|resource,
     *     decode_content?: bool|string,
     *     delay?: int|float,
     *     expect?: bool|int,
     *     form_params?: array<array-key, mixed>,
     *     force_ip_resolve?: string,
     *     headers?: array<array-key, string|array<array-key, string>>|null,
     *     http_errors?: bool,
     *     idn_conversion?: bool|int,
     *     json?: mixed,
     *     multipart?: array<array-key, array{
     *         name: string,
     *         contents: mixed,
     *         headers?: array<array-key, string|array<array-key, string>>,
     *         filename?: string
     *     }>,
     *     on_headers?: callable(ResponseInterface, RequestInterface): mixed,
     *     on_stats?: callable(TransferStats): mixed,
     *     progress?: callable(int|float, int|float, int|float, int|float): mixed,
     *     protocols?: array<array-key, string>,
     *     proxy?: string|array{
     *         http?: string,
     *         https?: string,
     *         no?: string|array<array-key, string>
     *     },
     *     query?: array<array-key, mixed>|string,
     *     read_timeout?: int|float,
     *     request_factory?: RequestFactoryInterface,
     *     sink?: resource|string|StreamInterface,
     *     ssl_key?: string|array{
     *         0: string,
     *         1?: string
     *     },
     *     ssl_key_type?: string,
     *     stream?: bool,
     *     stream_factory?: StreamFactoryInterface,
     *     stream_context?: array<array-key, mixed>,
     *     synchronous?: bool,
     *     timeout?: int|float,
     *     uri_factory?: UriFactoryInterface,
     *     verify?: bool|string,
     *     version?: string|float,
     *     curl?: array<int|string, mixed>,
     *     ...
     * } $options Request options to apply.
     *
     * @throws GuzzleException
     */
    public function head($uri, array $options = []): ResponseInterface
    {
        return $this->request('HEAD', $uri, $options);
    }

    /**
     * Create and send an HTTP PUT request.
     *
     * Use an absolute path to override the base path of the client, or a
     * relative path to append to the base path of the client. The URL can
     * contain the query string as well.
     *
     * @param string|UriInterface $uri URI object or string.
     * @param array{
     *     handler?: callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed>,
     *     base_uri?: string|UriInterface,
     *     allow_redirects?: bool|array{
     *         max?: int,
     *         strict?: bool,
     *         referer?: bool,
     *         protocols?: array<array-key, string>,
     *         on_redirect?: callable(RequestInterface, ResponseInterface, UriInterface): mixed,
     *         track_redirects?: bool
     *     },
     *     auth?: array{
     *         0: string,
     *         1: string,
     *         2?: string
     *     }|null,
     *     body?: mixed,
     *     cert?: string|array{
     *         0: string,
     *         1?: string
     *     },
     *     cert_type?: string,
     *     connect_timeout?: int|float,
     *     cookies?: bool|CookieJarInterface,
     *     crypto_method?: int,
     *     debug?: bool|resource,
     *     decode_content?: bool|string,
     *     delay?: int|float,
     *     expect?: bool|int,
     *     form_params?: array<array-key, mixed>,
     *     force_ip_resolve?: string,
     *     headers?: array<array-key, string|array<array-key, string>>|null,
     *     http_errors?: bool,
     *     idn_conversion?: bool|int,
     *     json?: mixed,
     *     multipart?: array<array-key, array{
     *         name: string,
     *         contents: mixed,
     *         headers?: array<array-key, string|array<array-key, string>>,
     *         filename?: string
     *     }>,
     *     on_headers?: callable(ResponseInterface, RequestInterface): mixed,
     *     on_stats?: callable(TransferStats): mixed,
     *     progress?: callable(int|float, int|float, int|float, int|float): mixed,
     *     protocols?: array<array-key, string>,
     *     proxy?: string|array{
     *         http?: string,
     *         https?: string,
     *         no?: string|array<array-key, string>
     *     },
     *     query?: array<array-key, mixed>|string,
     *     read_timeout?: int|float,
     *     request_factory?: RequestFactoryInterface,
     *     sink?: resource|string|StreamInterface,
     *     ssl_key?: string|array{
     *         0: string,
     *         1?: string
     *     },
     *     ssl_key_type?: string,
     *     stream?: bool,
     *     stream_factory?: StreamFactoryInterface,
     *     stream_context?: array<array-key, mixed>,
     *     synchronous?: bool,
     *     timeout?: int|float,
     *     uri_factory?: UriFactoryInterface,
     *     verify?: bool|string,
     *     version?: string|float,
     *     curl?: array<int|string, mixed>,
     *     ...
     * } $options Request options to apply.
     *
     * @throws GuzzleException
     */
    public function put($uri, array $options = []): ResponseInterface
    {
        return $this->request('PUT', $uri, $options);
    }

    /**
     * Create and send an HTTP POST request.
     *
     * Use an absolute path to override the base path of the client, or a
     * relative path to append to the base path of the client. The URL can
     * contain the query string as well.
     *
     * @param string|UriInterface $uri URI object or string.
     * @param array{
     *     handler?: callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed>,
     *     base_uri?: string|UriInterface,
     *     allow_redirects?: bool|array{
     *         max?: int,
     *         strict?: bool,
     *         referer?: bool,
     *         protocols?: array<array-key, string>,
     *         on_redirect?: callable(RequestInterface, ResponseInterface, UriInterface): mixed,
     *         track_redirects?: bool
     *     },
     *     auth?: array{
     *         0: string,
     *         1: string,
     *         2?: string
     *     }|null,
     *     body?: mixed,
     *     cert?: string|array{
     *         0: string,
     *         1?: string
     *     },
     *     cert_type?: string,
     *     connect_timeout?: int|float,
     *     cookies?: bool|CookieJarInterface,
     *     crypto_method?: int,
     *     debug?: bool|resource,
     *     decode_content?: bool|string,
     *     delay?: int|float,
     *     expect?: bool|int,
     *     form_params?: array<array-key, mixed>,
     *     force_ip_resolve?: string,
     *     headers?: array<array-key, string|array<array-key, string>>|null,
     *     http_errors?: bool,
     *     idn_conversion?: bool|int,
     *     json?: mixed,
     *     multipart?: array<array-key, array{
     *         name: string,
     *         contents: mixed,
     *         headers?: array<array-key, string|array<array-key, string>>,
     *         filename?: string
     *     }>,
     *     on_headers?: callable(ResponseInterface, RequestInterface): mixed,
     *     on_stats?: callable(TransferStats): mixed,
     *     progress?: callable(int|float, int|float, int|float, int|float): mixed,
     *     protocols?: array<array-key, string>,
     *     proxy?: string|array{
     *         http?: string,
     *         https?: string,
     *         no?: string|array<array-key, string>
     *     },
     *     query?: array<array-key, mixed>|string,
     *     read_timeout?: int|float,
     *     request_factory?: RequestFactoryInterface,
     *     sink?: resource|string|StreamInterface,
     *     ssl_key?: string|array{
     *         0: string,
     *         1?: string
     *     },
     *     ssl_key_type?: string,
     *     stream?: bool,
     *     stream_factory?: StreamFactoryInterface,
     *     stream_context?: array<array-key, mixed>,
     *     synchronous?: bool,
     *     timeout?: int|float,
     *     uri_factory?: UriFactoryInterface,
     *     verify?: bool|string,
     *     version?: string|float,
     *     curl?: array<int|string, mixed>,
     *     ...
     * } $options Request options to apply.
     *
     * @throws GuzzleException
     */
    public function post($uri, array $options = []): ResponseInterface
    {
        return $this->request('POST', $uri, $options);
    }

    /**
     * Create and send an HTTP PATCH request.
     *
     * Use an absolute path to override the base path of the client, or a
     * relative path to append to the base path of the client. The URL can
     * contain the query string as well.
     *
     * @param string|UriInterface $uri URI object or string.
     * @param array{
     *     handler?: callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed>,
     *     base_uri?: string|UriInterface,
     *     allow_redirects?: bool|array{
     *         max?: int,
     *         strict?: bool,
     *         referer?: bool,
     *         protocols?: array<array-key, string>,
     *         on_redirect?: callable(RequestInterface, ResponseInterface, UriInterface): mixed,
     *         track_redirects?: bool
     *     },
     *     auth?: array{
     *         0: string,
     *         1: string,
     *         2?: string
     *     }|null,
     *     body?: mixed,
     *     cert?: string|array{
     *         0: string,
     *         1?: string
     *     },
     *     cert_type?: string,
     *     connect_timeout?: int|float,
     *     cookies?: bool|CookieJarInterface,
     *     crypto_method?: int,
     *     debug?: bool|resource,
     *     decode_content?: bool|string,
     *     delay?: int|float,
     *     expect?: bool|int,
     *     form_params?: array<array-key, mixed>,
     *     force_ip_resolve?: string,
     *     headers?: array<array-key, string|array<array-key, string>>|null,
     *     http_errors?: bool,
     *     idn_conversion?: bool|int,
     *     json?: mixed,
     *     multipart?: array<array-key, array{
     *         name: string,
     *         contents: mixed,
     *         headers?: array<array-key, string|array<array-key, string>>,
     *         filename?: string
     *     }>,
     *     on_headers?: callable(ResponseInterface, RequestInterface): mixed,
     *     on_stats?: callable(TransferStats): mixed,
     *     progress?: callable(int|float, int|float, int|float, int|float): mixed,
     *     protocols?: array<array-key, string>,
     *     proxy?: string|array{
     *         http?: string,
     *         https?: string,
     *         no?: string|array<array-key, string>
     *     },
     *     query?: array<array-key, mixed>|string,
     *     read_timeout?: int|float,
     *     request_factory?: RequestFactoryInterface,
     *     sink?: resource|string|StreamInterface,
     *     ssl_key?: string|array{
     *         0: string,
     *         1?: string
     *     },
     *     ssl_key_type?: string,
     *     stream?: bool,
     *     stream_factory?: StreamFactoryInterface,
     *     stream_context?: array<array-key, mixed>,
     *     synchronous?: bool,
     *     timeout?: int|float,
     *     uri_factory?: UriFactoryInterface,
     *     verify?: bool|string,
     *     version?: string|float,
     *     curl?: array<int|string, mixed>,
     *     ...
     * } $options Request options to apply.
     *
     * @throws GuzzleException
     */
    public function patch($uri, array $options = []): ResponseInterface
    {
        return $this->request('PATCH', $uri, $options);
    }

    /**
     * Create and send an HTTP DELETE request.
     *
     * Use an absolute path to override the base path of the client, or a
     * relative path to append to the base path of the client. The URL can
     * contain the query string as well.
     *
     * @param string|UriInterface $uri URI object or string.
     * @param array{
     *     handler?: callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed>,
     *     base_uri?: string|UriInterface,
     *     allow_redirects?: bool|array{
     *         max?: int,
     *         strict?: bool,
     *         referer?: bool,
     *         protocols?: array<array-key, string>,
     *         on_redirect?: callable(RequestInterface, ResponseInterface, UriInterface): mixed,
     *         track_redirects?: bool
     *     },
     *     auth?: array{
     *         0: string,
     *         1: string,
     *         2?: string
     *     }|null,
     *     body?: mixed,
     *     cert?: string|array{
     *         0: string,
     *         1?: string
     *     },
     *     cert_type?: string,
     *     connect_timeout?: int|float,
     *     cookies?: bool|CookieJarInterface,
     *     crypto_method?: int,
     *     debug?: bool|resource,
     *     decode_content?: bool|string,
     *     delay?: int|float,
     *     expect?: bool|int,
     *     form_params?: array<array-key, mixed>,
     *     force_ip_resolve?: string,
     *     headers?: array<array-key, string|array<array-key, string>>|null,
     *     http_errors?: bool,
     *     idn_conversion?: bool|int,
     *     json?: mixed,
     *     multipart?: array<array-key, array{
     *         name: string,
     *         contents: mixed,
     *         headers?: array<array-key, string|array<array-key, string>>,
     *         filename?: string
     *     }>,
     *     on_headers?: callable(ResponseInterface, RequestInterface): mixed,
     *     on_stats?: callable(TransferStats): mixed,
     *     progress?: callable(int|float, int|float, int|float, int|float): mixed,
     *     protocols?: array<array-key, string>,
     *     proxy?: string|array{
     *         http?: string,
     *         https?: string,
     *         no?: string|array<array-key, string>
     *     },
     *     query?: array<array-key, mixed>|string,
     *     read_timeout?: int|float,
     *     request_factory?: RequestFactoryInterface,
     *     sink?: resource|string|StreamInterface,
     *     ssl_key?: string|array{
     *         0: string,
     *         1?: string
     *     },
     *     ssl_key_type?: string,
     *     stream?: bool,
     *     stream_factory?: StreamFactoryInterface,
     *     stream_context?: array<array-key, mixed>,
     *     synchronous?: bool,
     *     timeout?: int|float,
     *     uri_factory?: UriFactoryInterface,
     *     verify?: bool|string,
     *     version?: string|float,
     *     curl?: array<int|string, mixed>,
     *     ...
     * } $options Request options to apply.
     *
     * @throws GuzzleException
     */
    public function delete($uri, array $options = []): ResponseInterface
    {
        return $this->request('DELETE', $uri, $options);
    }

    /**
     * Create and send an asynchronous HTTP request.
     *
     * Use an absolute path to override the base path of the client, or a
     * relative path to append to the base path of the client. The URL can
     * contain the query string as well. Use an array to provide a URL
     * template and additional variables to use in the URL template expansion.
     *
     * @param string              $method HTTP method
     * @param string|UriInterface $uri    URI object or string.
     * @param array{
     *     handler?: callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed>,
     *     base_uri?: string|UriInterface,
     *     allow_redirects?: bool|array{
     *         max?: int,
     *         strict?: bool,
     *         referer?: bool,
     *         protocols?: array<array-key, string>,
     *         on_redirect?: callable(RequestInterface, ResponseInterface, UriInterface): mixed,
     *         track_redirects?: bool
     *     },
     *     auth?: array{
     *         0: string,
     *         1: string,
     *         2?: string
     *     }|null,
     *     body?: mixed,
     *     cert?: string|array{
     *         0: string,
     *         1?: string
     *     },
     *     cert_type?: string,
     *     connect_timeout?: int|float,
     *     cookies?: bool|CookieJarInterface,
     *     crypto_method?: int,
     *     debug?: bool|resource,
     *     decode_content?: bool|string,
     *     delay?: int|float,
     *     expect?: bool|int,
     *     form_params?: array<array-key, mixed>,
     *     force_ip_resolve?: string,
     *     headers?: array<array-key, string|array<array-key, string>>|null,
     *     http_errors?: bool,
     *     idn_conversion?: bool|int,
     *     json?: mixed,
     *     multipart?: array<array-key, array{
     *         name: string,
     *         contents: mixed,
     *         headers?: array<array-key, string|array<array-key, string>>,
     *         filename?: string
     *     }>,
     *     on_headers?: callable(ResponseInterface, RequestInterface): mixed,
     *     on_stats?: callable(TransferStats): mixed,
     *     progress?: callable(int|float, int|float, int|float, int|float): mixed,
     *     protocols?: array<array-key, string>,
     *     proxy?: string|array{
     *         http?: string,
     *         https?: string,
     *         no?: string|array<array-key, string>
     *     },
     *     query?: array<array-key, mixed>|string,
     *     read_timeout?: int|float,
     *     request_factory?: RequestFactoryInterface,
     *     sink?: resource|string|StreamInterface,
     *     ssl_key?: string|array{
     *         0: string,
     *         1?: string
     *     },
     *     ssl_key_type?: string,
     *     stream?: bool,
     *     stream_factory?: StreamFactoryInterface,
     *     stream_context?: array<array-key, mixed>,
     *     synchronous?: bool,
     *     timeout?: int|float,
     *     uri_factory?: UriFactoryInterface,
     *     verify?: bool|string,
     *     version?: string|float,
     *     curl?: array<int|string, mixed>,
     *     ...
     * } $options Request options to apply.
     *
     * @return PromiseInterface<ResponseInterface, mixed>
     */
    abstract public function requestAsync(string $method, $uri, array $options = []): PromiseInterface;

    /**
     * Create and send an asynchronous HTTP GET request.
     *
     * Use an absolute path to override the base path of the client, or a
     * relative path to append to the base path of the client. The URL can
     * contain the query string as well. Use an array to provide a URL
     * template and additional variables to use in the URL template expansion.
     *
     * @param string|UriInterface $uri URI object or string.
     * @param array{
     *     handler?: callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed>,
     *     base_uri?: string|UriInterface,
     *     allow_redirects?: bool|array{
     *         max?: int,
     *         strict?: bool,
     *         referer?: bool,
     *         protocols?: array<array-key, string>,
     *         on_redirect?: callable(RequestInterface, ResponseInterface, UriInterface): mixed,
     *         track_redirects?: bool
     *     },
     *     auth?: array{
     *         0: string,
     *         1: string,
     *         2?: string
     *     }|null,
     *     body?: mixed,
     *     cert?: string|array{
     *         0: string,
     *         1?: string
     *     },
     *     cert_type?: string,
     *     connect_timeout?: int|float,
     *     cookies?: bool|CookieJarInterface,
     *     crypto_method?: int,
     *     debug?: bool|resource,
     *     decode_content?: bool|string,
     *     delay?: int|float,
     *     expect?: bool|int,
     *     form_params?: array<array-key, mixed>,
     *     force_ip_resolve?: string,
     *     headers?: array<array-key, string|array<array-key, string>>|null,
     *     http_errors?: bool,
     *     idn_conversion?: bool|int,
     *     json?: mixed,
     *     multipart?: array<array-key, array{
     *         name: string,
     *         contents: mixed,
     *         headers?: array<array-key, string|array<array-key, string>>,
     *         filename?: string
     *     }>,
     *     on_headers?: callable(ResponseInterface, RequestInterface): mixed,
     *     on_stats?: callable(TransferStats): mixed,
     *     progress?: callable(int|float, int|float, int|float, int|float): mixed,
     *     protocols?: array<array-key, string>,
     *     proxy?: string|array{
     *         http?: string,
     *         https?: string,
     *         no?: string|array<array-key, string>
     *     },
     *     query?: array<array-key, mixed>|string,
     *     read_timeout?: int|float,
     *     request_factory?: RequestFactoryInterface,
     *     sink?: resource|string|StreamInterface,
     *     ssl_key?: string|array{
     *         0: string,
     *         1?: string
     *     },
     *     ssl_key_type?: string,
     *     stream?: bool,
     *     stream_factory?: StreamFactoryInterface,
     *     stream_context?: array<array-key, mixed>,
     *     synchronous?: bool,
     *     timeout?: int|float,
     *     uri_factory?: UriFactoryInterface,
     *     verify?: bool|string,
     *     version?: string|float,
     *     curl?: array<int|string, mixed>,
     *     ...
     * } $options Request options to apply.
     *
     * @return PromiseInterface<ResponseInterface, mixed>
     */
    public function getAsync($uri, array $options = []): PromiseInterface
    {
        return $this->requestAsync('GET', $uri, $options);
    }

    /**
     * Create and send an asynchronous HTTP HEAD request.
     *
     * Use an absolute path to override the base path of the client, or a
     * relative path to append to the base path of the client. The URL can
     * contain the query string as well. Use an array to provide a URL
     * template and additional variables to use in the URL template expansion.
     *
     * @param string|UriInterface $uri URI object or string.
     * @param array{
     *     handler?: callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed>,
     *     base_uri?: string|UriInterface,
     *     allow_redirects?: bool|array{
     *         max?: int,
     *         strict?: bool,
     *         referer?: bool,
     *         protocols?: array<array-key, string>,
     *         on_redirect?: callable(RequestInterface, ResponseInterface, UriInterface): mixed,
     *         track_redirects?: bool
     *     },
     *     auth?: array{
     *         0: string,
     *         1: string,
     *         2?: string
     *     }|null,
     *     body?: mixed,
     *     cert?: string|array{
     *         0: string,
     *         1?: string
     *     },
     *     cert_type?: string,
     *     connect_timeout?: int|float,
     *     cookies?: bool|CookieJarInterface,
     *     crypto_method?: int,
     *     debug?: bool|resource,
     *     decode_content?: bool|string,
     *     delay?: int|float,
     *     expect?: bool|int,
     *     form_params?: array<array-key, mixed>,
     *     force_ip_resolve?: string,
     *     headers?: array<array-key, string|array<array-key, string>>|null,
     *     http_errors?: bool,
     *     idn_conversion?: bool|int,
     *     json?: mixed,
     *     multipart?: array<array-key, array{
     *         name: string,
     *         contents: mixed,
     *         headers?: array<array-key, string|array<array-key, string>>,
     *         filename?: string
     *     }>,
     *     on_headers?: callable(ResponseInterface, RequestInterface): mixed,
     *     on_stats?: callable(TransferStats): mixed,
     *     progress?: callable(int|float, int|float, int|float, int|float): mixed,
     *     protocols?: array<array-key, string>,
     *     proxy?: string|array{
     *         http?: string,
     *         https?: string,
     *         no?: string|array<array-key, string>
     *     },
     *     query?: array<array-key, mixed>|string,
     *     read_timeout?: int|float,
     *     request_factory?: RequestFactoryInterface,
     *     sink?: resource|string|StreamInterface,
     *     ssl_key?: string|array{
     *         0: string,
     *         1?: string
     *     },
     *     ssl_key_type?: string,
     *     stream?: bool,
     *     stream_factory?: StreamFactoryInterface,
     *     stream_context?: array<array-key, mixed>,
     *     synchronous?: bool,
     *     timeout?: int|float,
     *     uri_factory?: UriFactoryInterface,
     *     verify?: bool|string,
     *     version?: string|float,
     *     curl?: array<int|string, mixed>,
     *     ...
     * } $options Request options to apply.
     *
     * @return PromiseInterface<ResponseInterface, mixed>
     */
    public function headAsync($uri, array $options = []): PromiseInterface
    {
        return $this->requestAsync('HEAD', $uri, $options);
    }

    /**
     * Create and send an asynchronous HTTP PUT request.
     *
     * Use an absolute path to override the base path of the client, or a
     * relative path to append to the base path of the client. The URL can
     * contain the query string as well. Use an array to provide a URL
     * template and additional variables to use in the URL template expansion.
     *
     * @param string|UriInterface $uri URI object or string.
     * @param array{
     *     handler?: callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed>,
     *     base_uri?: string|UriInterface,
     *     allow_redirects?: bool|array{
     *         max?: int,
     *         strict?: bool,
     *         referer?: bool,
     *         protocols?: array<array-key, string>,
     *         on_redirect?: callable(RequestInterface, ResponseInterface, UriInterface): mixed,
     *         track_redirects?: bool
     *     },
     *     auth?: array{
     *         0: string,
     *         1: string,
     *         2?: string
     *     }|null,
     *     body?: mixed,
     *     cert?: string|array{
     *         0: string,
     *         1?: string
     *     },
     *     cert_type?: string,
     *     connect_timeout?: int|float,
     *     cookies?: bool|CookieJarInterface,
     *     crypto_method?: int,
     *     debug?: bool|resource,
     *     decode_content?: bool|string,
     *     delay?: int|float,
     *     expect?: bool|int,
     *     form_params?: array<array-key, mixed>,
     *     force_ip_resolve?: string,
     *     headers?: array<array-key, string|array<array-key, string>>|null,
     *     http_errors?: bool,
     *     idn_conversion?: bool|int,
     *     json?: mixed,
     *     multipart?: array<array-key, array{
     *         name: string,
     *         contents: mixed,
     *         headers?: array<array-key, string|array<array-key, string>>,
     *         filename?: string
     *     }>,
     *     on_headers?: callable(ResponseInterface, RequestInterface): mixed,
     *     on_stats?: callable(TransferStats): mixed,
     *     progress?: callable(int|float, int|float, int|float, int|float): mixed,
     *     protocols?: array<array-key, string>,
     *     proxy?: string|array{
     *         http?: string,
     *         https?: string,
     *         no?: string|array<array-key, string>
     *     },
     *     query?: array<array-key, mixed>|string,
     *     read_timeout?: int|float,
     *     request_factory?: RequestFactoryInterface,
     *     sink?: resource|string|StreamInterface,
     *     ssl_key?: string|array{
     *         0: string,
     *         1?: string
     *     },
     *     ssl_key_type?: string,
     *     stream?: bool,
     *     stream_factory?: StreamFactoryInterface,
     *     stream_context?: array<array-key, mixed>,
     *     synchronous?: bool,
     *     timeout?: int|float,
     *     uri_factory?: UriFactoryInterface,
     *     verify?: bool|string,
     *     version?: string|float,
     *     curl?: array<int|string, mixed>,
     *     ...
     * } $options Request options to apply.
     *
     * @return PromiseInterface<ResponseInterface, mixed>
     */
    public function putAsync($uri, array $options = []): PromiseInterface
    {
        return $this->requestAsync('PUT', $uri, $options);
    }

    /**
     * Create and send an asynchronous HTTP POST request.
     *
     * Use an absolute path to override the base path of the client, or a
     * relative path to append to the base path of the client. The URL can
     * contain the query string as well. Use an array to provide a URL
     * template and additional variables to use in the URL template expansion.
     *
     * @param string|UriInterface $uri URI object or string.
     * @param array{
     *     handler?: callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed>,
     *     base_uri?: string|UriInterface,
     *     allow_redirects?: bool|array{
     *         max?: int,
     *         strict?: bool,
     *         referer?: bool,
     *         protocols?: array<array-key, string>,
     *         on_redirect?: callable(RequestInterface, ResponseInterface, UriInterface): mixed,
     *         track_redirects?: bool
     *     },
     *     auth?: array{
     *         0: string,
     *         1: string,
     *         2?: string
     *     }|null,
     *     body?: mixed,
     *     cert?: string|array{
     *         0: string,
     *         1?: string
     *     },
     *     cert_type?: string,
     *     connect_timeout?: int|float,
     *     cookies?: bool|CookieJarInterface,
     *     crypto_method?: int,
     *     debug?: bool|resource,
     *     decode_content?: bool|string,
     *     delay?: int|float,
     *     expect?: bool|int,
     *     form_params?: array<array-key, mixed>,
     *     force_ip_resolve?: string,
     *     headers?: array<array-key, string|array<array-key, string>>|null,
     *     http_errors?: bool,
     *     idn_conversion?: bool|int,
     *     json?: mixed,
     *     multipart?: array<array-key, array{
     *         name: string,
     *         contents: mixed,
     *         headers?: array<array-key, string|array<array-key, string>>,
     *         filename?: string
     *     }>,
     *     on_headers?: callable(ResponseInterface, RequestInterface): mixed,
     *     on_stats?: callable(TransferStats): mixed,
     *     progress?: callable(int|float, int|float, int|float, int|float): mixed,
     *     protocols?: array<array-key, string>,
     *     proxy?: string|array{
     *         http?: string,
     *         https?: string,
     *         no?: string|array<array-key, string>
     *     },
     *     query?: array<array-key, mixed>|string,
     *     read_timeout?: int|float,
     *     request_factory?: RequestFactoryInterface,
     *     sink?: resource|string|StreamInterface,
     *     ssl_key?: string|array{
     *         0: string,
     *         1?: string
     *     },
     *     ssl_key_type?: string,
     *     stream?: bool,
     *     stream_factory?: StreamFactoryInterface,
     *     stream_context?: array<array-key, mixed>,
     *     synchronous?: bool,
     *     timeout?: int|float,
     *     uri_factory?: UriFactoryInterface,
     *     verify?: bool|string,
     *     version?: string|float,
     *     curl?: array<int|string, mixed>,
     *     ...
     * } $options Request options to apply.
     *
     * @return PromiseInterface<ResponseInterface, mixed>
     */
    public function postAsync($uri, array $options = []): PromiseInterface
    {
        return $this->requestAsync('POST', $uri, $options);
    }

    /**
     * Create and send an asynchronous HTTP PATCH request.
     *
     * Use an absolute path to override the base path of the client, or a
     * relative path to append to the base path of the client. The URL can
     * contain the query string as well. Use an array to provide a URL
     * template and additional variables to use in the URL template expansion.
     *
     * @param string|UriInterface $uri URI object or string.
     * @param array{
     *     handler?: callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed>,
     *     base_uri?: string|UriInterface,
     *     allow_redirects?: bool|array{
     *         max?: int,
     *         strict?: bool,
     *         referer?: bool,
     *         protocols?: array<array-key, string>,
     *         on_redirect?: callable(RequestInterface, ResponseInterface, UriInterface): mixed,
     *         track_redirects?: bool
     *     },
     *     auth?: array{
     *         0: string,
     *         1: string,
     *         2?: string
     *     }|null,
     *     body?: mixed,
     *     cert?: string|array{
     *         0: string,
     *         1?: string
     *     },
     *     cert_type?: string,
     *     connect_timeout?: int|float,
     *     cookies?: bool|CookieJarInterface,
     *     crypto_method?: int,
     *     debug?: bool|resource,
     *     decode_content?: bool|string,
     *     delay?: int|float,
     *     expect?: bool|int,
     *     form_params?: array<array-key, mixed>,
     *     force_ip_resolve?: string,
     *     headers?: array<array-key, string|array<array-key, string>>|null,
     *     http_errors?: bool,
     *     idn_conversion?: bool|int,
     *     json?: mixed,
     *     multipart?: array<array-key, array{
     *         name: string,
     *         contents: mixed,
     *         headers?: array<array-key, string|array<array-key, string>>,
     *         filename?: string
     *     }>,
     *     on_headers?: callable(ResponseInterface, RequestInterface): mixed,
     *     on_stats?: callable(TransferStats): mixed,
     *     progress?: callable(int|float, int|float, int|float, int|float): mixed,
     *     protocols?: array<array-key, string>,
     *     proxy?: string|array{
     *         http?: string,
     *         https?: string,
     *         no?: string|array<array-key, string>
     *     },
     *     query?: array<array-key, mixed>|string,
     *     read_timeout?: int|float,
     *     request_factory?: RequestFactoryInterface,
     *     sink?: resource|string|StreamInterface,
     *     ssl_key?: string|array{
     *         0: string,
     *         1?: string
     *     },
     *     ssl_key_type?: string,
     *     stream?: bool,
     *     stream_factory?: StreamFactoryInterface,
     *     stream_context?: array<array-key, mixed>,
     *     synchronous?: bool,
     *     timeout?: int|float,
     *     uri_factory?: UriFactoryInterface,
     *     verify?: bool|string,
     *     version?: string|float,
     *     curl?: array<int|string, mixed>,
     *     ...
     * } $options Request options to apply.
     *
     * @return PromiseInterface<ResponseInterface, mixed>
     */
    public function patchAsync($uri, array $options = []): PromiseInterface
    {
        return $this->requestAsync('PATCH', $uri, $options);
    }

    /**
     * Create and send an asynchronous HTTP DELETE request.
     *
     * Use an absolute path to override the base path of the client, or a
     * relative path to append to the base path of the client. The URL can
     * contain the query string as well. Use an array to provide a URL
     * template and additional variables to use in the URL template expansion.
     *
     * @param string|UriInterface $uri URI object or string.
     * @param array{
     *     handler?: callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed>,
     *     base_uri?: string|UriInterface,
     *     allow_redirects?: bool|array{
     *         max?: int,
     *         strict?: bool,
     *         referer?: bool,
     *         protocols?: array<array-key, string>,
     *         on_redirect?: callable(RequestInterface, ResponseInterface, UriInterface): mixed,
     *         track_redirects?: bool
     *     },
     *     auth?: array{
     *         0: string,
     *         1: string,
     *         2?: string
     *     }|null,
     *     body?: mixed,
     *     cert?: string|array{
     *         0: string,
     *         1?: string
     *     },
     *     cert_type?: string,
     *     connect_timeout?: int|float,
     *     cookies?: bool|CookieJarInterface,
     *     crypto_method?: int,
     *     debug?: bool|resource,
     *     decode_content?: bool|string,
     *     delay?: int|float,
     *     expect?: bool|int,
     *     form_params?: array<array-key, mixed>,
     *     force_ip_resolve?: string,
     *     headers?: array<array-key, string|array<array-key, string>>|null,
     *     http_errors?: bool,
     *     idn_conversion?: bool|int,
     *     json?: mixed,
     *     multipart?: array<array-key, array{
     *         name: string,
     *         contents: mixed,
     *         headers?: array<array-key, string|array<array-key, string>>,
     *         filename?: string
     *     }>,
     *     on_headers?: callable(ResponseInterface, RequestInterface): mixed,
     *     on_stats?: callable(TransferStats): mixed,
     *     progress?: callable(int|float, int|float, int|float, int|float): mixed,
     *     protocols?: array<array-key, string>,
     *     proxy?: string|array{
     *         http?: string,
     *         https?: string,
     *         no?: string|array<array-key, string>
     *     },
     *     query?: array<array-key, mixed>|string,
     *     read_timeout?: int|float,
     *     request_factory?: RequestFactoryInterface,
     *     sink?: resource|string|StreamInterface,
     *     ssl_key?: string|array{
     *         0: string,
     *         1?: string
     *     },
     *     ssl_key_type?: string,
     *     stream?: bool,
     *     stream_factory?: StreamFactoryInterface,
     *     stream_context?: array<array-key, mixed>,
     *     synchronous?: bool,
     *     timeout?: int|float,
     *     uri_factory?: UriFactoryInterface,
     *     verify?: bool|string,
     *     version?: string|float,
     *     curl?: array<int|string, mixed>,
     *     ...
     * } $options Request options to apply.
     *
     * @return PromiseInterface<ResponseInterface, mixed>
     */
    public function deleteAsync($uri, array $options = []): PromiseInterface
    {
        return $this->requestAsync('DELETE', $uri, $options);
    }
}
