<?php

declare(strict_types=1);

namespace GuzzleHttp;

use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\CookieJarInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\InvalidArgumentException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\CurlShareHandleState;
use GuzzleHttp\Promise as P;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\DiagnosticValue;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\UriInterface;

/**
 * @final
 */
class Client implements ClientInterface, \Psr\Http\Client\ClientInterface
{
    use ClientTrait;
    use NonSerializableTrait;

    /**
     * @var array Default request options
     */
    private array $config;

    /**
     * Clients accept an array of constructor parameters.
     *
     * Here's an example of creating a client using a base_uri and an array of
     * default request options to apply to each request:
     *
     *     $client = new Client([
     *         'base_uri'        => 'http://www.foo.com/1.0/',
     *         'timeout'         => 0,
     *         'allow_redirects' => false,
     *         'proxy'           => '192.168.16.1:10'
     *     ]);
     *
     * Client configuration settings include the following options:
     *
     * - handler: (callable) Function that transfers HTTP requests over the
     *   wire. The function is called with a Psr7\Http\Message\RequestInterface
     *   and array of transfer options, and must return a
     *   GuzzleHttp\Promise\PromiseInterface that is fulfilled with a
     *   Psr7\Http\Message\ResponseInterface on success. If no handler is
     *   provided, a default handler will be created that enables all of the
     *   request options below by attaching all of the default middleware to the
     *   handler.
     * - base_uri: (string|UriInterface) Base URI of the client that is merged
     *   into relative URIs. Can be a string or instance of UriInterface.
     * - transport_sharing: (string|null) Transport sharing mode for the default
     *   handler. Accepts TransportSharing::* or null. Defaults to null.
     * - max_host_connections: (int|null) Maximum concurrent connections per
     *   host, enforced by the default CurlMultiHandler. The default stream
     *   fallback receives the cap as a marker only: it rejects enabled
     *   response streaming ("stream" => true) and does not limit overlapping
     *   buffered calls.
     * - max_total_connections: (int|null) Maximum concurrent connections
     *   overall, enforced by the default CurlMultiHandler. The default stream
     *   fallback receives the cap as a marker only: it rejects enabled
     *   response streaming ("stream" => true) and does not limit overlapping
     *   buffered calls.
     * - multiplex: (string|null) Multiplexing::NONE to disable multiplexing on
     *   the default CurlMultiHandler; the value also becomes the default
     *   "multiplex" request option. Other Multiplexing::* values act as the
     *   default request option only.
     * - **: any request option
     *
     * @param array{
     *     handler?: callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed>,
     *     base_uri?: string|UriInterface,
     *     transport_sharing?: string|null,
     *     max_host_connections?: int|null,
     *     max_total_connections?: int|null,
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
     *     cookies?: bool|CookieJarInterface,
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
     * } $config Client configuration settings and default request options.
     *
     * @see RequestOptions for a list of available request options.
     */
    public function __construct(
        #[\SensitiveParameter]
        array $config = []
    ) {
        $handlerOptions = [];
        foreach (['max_host_connections', 'max_total_connections'] as $capOption) {
            if (\array_key_exists($capOption, $config)) {
                if ($config[$capOption] !== null) {
                    $handlerOptions[$capOption] = $config[$capOption];
                }

                unset($config[$capOption]);
            }
        }

        // Deliberately not unset: the value also becomes the default
        // "multiplex" request option, which the configured handler accepts.
        $handlerMultiplex = ($config['multiplex'] ?? null) === Multiplexing::NONE;

        $transportSharing = \array_key_exists('transport_sharing', $config) ? $config['transport_sharing'] : null;
        $transportSharingMode = CurlShareHandleState::normalizeMode($transportSharing, 'transport_sharing');
        unset($config['transport_sharing']);

        if (!isset($config['handler'])) {
            if ($transportSharingMode !== TransportSharing::NONE) {
                $handlerOptions['transport_sharing'] = $transportSharingMode;
            }

            if ($handlerMultiplex) {
                $handlerOptions['multiplex'] = Multiplexing::NONE;
            }

            $config['handler'] = $handlerOptions === []
                ? HandlerStack::create()
                : HandlerStack::create(Utils::chooseHandler($handlerOptions));
        } elseif (!\is_callable($config['handler'])) {
            throw new InvalidArgumentException('handler must be a callable');
        } elseif ($handlerOptions !== []) {
            throw new InvalidArgumentException('The "max_host_connections" and "max_total_connections" client options require Guzzle to create the default handler. Configure the options on the CurlMultiHandler constructor for numeric enforcement, or on the StreamHandler constructor to reject enabled response streaming, when providing a custom handler.');
        } elseif (\in_array($transportSharingMode, [TransportSharing::HANDLER_REQUIRE, TransportSharing::PERSISTENT_REQUIRE], true)) {
            throw new InvalidArgumentException('The "transport_sharing" client option can only require sharing when Guzzle creates the default handler. Configure the "transport_sharing" option on CurlHandler or CurlMultiHandler when providing a custom cURL handler.');
        }

        $factory = new HttpFactory();

        if (!isset($config[RequestOptions::REQUEST_FACTORY])) {
            $config[RequestOptions::REQUEST_FACTORY] = $factory;
        }

        if (!isset($config[RequestOptions::URI_FACTORY])) {
            $config[RequestOptions::URI_FACTORY] = $factory;
        }

        if (!isset($config[RequestOptions::STREAM_FACTORY])) {
            $config[RequestOptions::STREAM_FACTORY] = $factory;
        }

        if (!isset($config[RequestOptions::RESPONSE_FACTORY])) {
            $config[RequestOptions::RESPONSE_FACTORY] = $factory;
        }

        self::requireRequestFactory($config[RequestOptions::REQUEST_FACTORY]);
        self::requireResponseFactory($config[RequestOptions::RESPONSE_FACTORY]);
        self::requireStreamFactory($config[RequestOptions::STREAM_FACTORY]);
        $uriFactory = self::requireUriFactory($config[RequestOptions::URI_FACTORY]);

        // Convert the base_uri to a UriInterface using the configured URI factory.
        if (isset($config['base_uri'])) {
            $config['base_uri'] = self::createUri($config['base_uri'], $uriFactory);
        }

        $this->configureDefaults($config);
    }

    /**
     * Asynchronously send an HTTP request.
     *
     * @param array{
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
     * } $options Request options to apply to the given request and to the transfer. See {@see RequestOptions}.
     *
     * @return PromiseInterface<ResponseInterface, mixed>
     */
    public function sendAsync(
        #[\SensitiveParameter]
        RequestInterface $request,
        #[\SensitiveParameter]
        array $options = []
    ): PromiseInterface {
        // Merge the base URI into the request URI if needed.
        $options = $this->prepareDefaults($options);

        return $this->transfer(
            $request->withUri($this->buildUri($request->getUri(), $options), $request->hasHeader('Host')),
            $options
        );
    }

    /**
     * Send an HTTP request.
     *
     * @param array{
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
     * } $options Request options to apply to the given request and to the transfer. See {@see RequestOptions}.
     *
     * @throws GuzzleException
     */
    public function send(
        #[\SensitiveParameter]
        RequestInterface $request,
        #[\SensitiveParameter]
        array $options = []
    ): ResponseInterface {
        $options[RequestOptions::SYNCHRONOUS] = true;

        return $this->sendAsync($request, $options)->wait();
    }

    /**
     * The HttpClient PSR (PSR-18) specifies this method.
     *
     * {@inheritDoc}
     */
    public function sendRequest(
        #[\SensitiveParameter]
        RequestInterface $request
    ): ResponseInterface {
        $options[RequestOptions::SYNCHRONOUS] = true;
        $options[RequestOptions::ALLOW_REDIRECTS] = false;
        $options[RequestOptions::HTTP_ERRORS] = false;

        return $this->sendAsync($request, $options)->wait();
    }

    /**
     * Create and send an asynchronous HTTP request.
     *
     * Use an absolute path to override the base path of the client, or a
     * relative path to append to the base path of the client. The URL can
     * contain the query string as well.
     *
     * @param string              $method HTTP method
     * @param string|UriInterface $uri    URI object or string.
     * @param array{
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
     * } $options Request options to apply. See {@see RequestOptions}.
     *
     * @return PromiseInterface<ResponseInterface, mixed>
     */
    public function requestAsync(
        string $method,
        $uri = '',
        #[\SensitiveParameter]
        array $options = []
    ): PromiseInterface {
        $options = $this->prepareDefaults($options);

        $version = self::normalizeProtocolVersion($options['version'] ?? '1.1');
        unset($options['version']);

        if (isset($options['body']) && \is_array($options['body'])) {
            throw $this->invalidBody();
        }

        $uriFactory = isset($options[RequestOptions::URI_FACTORY])
            ? self::requireUriFactory($options[RequestOptions::URI_FACTORY])
            : new HttpFactory();
        $requestFactory = isset($options[RequestOptions::REQUEST_FACTORY])
            ? self::requireRequestFactory($options[RequestOptions::REQUEST_FACTORY])
            : new HttpFactory();

        // Merge the URI into the base URI.
        $uriIsString = \is_string($uri);
        $uri = self::createUri($uri, $uriFactory);
        $builtUri = $this->buildUri($uri, $options);
        if ($uriIsString && $builtUri !== $uri) {
            $builtUri = self::createUri((string) $builtUri, $uriFactory);
        }

        $uri = $builtUri;
        $request = $requestFactory->createRequest($method, $uri);
        $request = Psr7\Utils::modifyRequest($request, ['version' => $version]);

        return $this->transfer($request, $options);
    }

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
     * } $options Request options to apply. See {@see RequestOptions}.
     *
     * @throws GuzzleException
     */
    public function request(
        string $method,
        $uri = '',
        #[\SensitiveParameter]
        array $options = []
    ): ResponseInterface {
        $options[RequestOptions::SYNCHRONOUS] = true;

        return $this->requestAsync($method, $uri, $options)->wait();
    }

    /**
     * Get a client configuration option.
     *
     * These options include default request options of the client, a "handler"
     * (if utilized by the concrete client), and a "base_uri" if utilized by the
     * concrete client.
     *
     * @param string|null $option The config option to retrieve.
     *
     * @return ($option is null ? array<string, mixed> : mixed)
     */
    public function getConfig(?string $option = null)
    {
        return $option === null
            ? $this->config
            : ($this->config[$option] ?? null);
    }

    private function buildUri(
        UriInterface $uri,
        #[\SensitiveParameter]
        array $config
    ): UriInterface {
        if (isset($config['base_uri'])) {
            $uriFactory = self::requireUriFactory($config[RequestOptions::URI_FACTORY] ?? new HttpFactory());
            $uri = Psr7\UriResolver::resolve(self::createUri($config['base_uri'], $uriFactory), $uri);
        }

        $idnOptions = Idn::normalizeConversionOption($config['idn_conversion'] ?? null);
        if ($idnOptions !== null) {
            $uri = Idn::convertUri($uri, $idnOptions);
        }

        if ($uri->getScheme() === '' && $uri->getHost() !== '') {
            $uri = $uri->withScheme('http');
        }

        return $uri;
    }

    /**
     * @param mixed $factory
     */
    private static function requireRequestFactory($factory): RequestFactoryInterface
    {
        if (!$factory instanceof RequestFactoryInterface) {
            throw new InvalidArgumentException(\sprintf(
                '%s must be an instance of %s',
                RequestOptions::REQUEST_FACTORY,
                RequestFactoryInterface::class
            ));
        }

        return $factory;
    }

    /**
     * @param mixed $factory
     */
    private static function requireResponseFactory($factory): ResponseFactoryInterface
    {
        if (!$factory instanceof ResponseFactoryInterface) {
            throw new InvalidArgumentException(\sprintf(
                '%s must be an instance of %s',
                RequestOptions::RESPONSE_FACTORY,
                ResponseFactoryInterface::class
            ));
        }

        return $factory;
    }

    /**
     * @param mixed $factory
     */
    private static function requireUriFactory($factory): UriFactoryInterface
    {
        if (!$factory instanceof UriFactoryInterface) {
            throw new InvalidArgumentException(\sprintf(
                '%s must be an instance of %s',
                RequestOptions::URI_FACTORY,
                UriFactoryInterface::class
            ));
        }

        return $factory;
    }

    /**
     * @param mixed $factory
     */
    private static function requireStreamFactory($factory): StreamFactoryInterface
    {
        if (!$factory instanceof StreamFactoryInterface) {
            throw new InvalidArgumentException(\sprintf(
                '%s must be an instance of %s',
                RequestOptions::STREAM_FACTORY,
                StreamFactoryInterface::class
            ));
        }

        return $factory;
    }

    /**
     * @param mixed $uri
     */
    private static function createUri($uri, UriFactoryInterface $uriFactory): UriInterface
    {
        if ($uri instanceof UriInterface) {
            return $uri;
        }

        if (\is_string($uri)) {
            return $uriFactory->createUri($uri);
        }

        throw new InvalidArgumentException(\sprintf('URI must be a string or %s', UriInterface::class));
    }

    /**
     * @param mixed $body
     */
    private static function createBodyStream($body, StreamFactoryInterface $streamFactory): StreamInterface
    {
        if ($body instanceof StreamInterface) {
            return $body;
        }

        if (\is_resource($body)) {
            return $streamFactory->createStreamFromResource($body);
        }

        if ($body === null) {
            return $streamFactory->createStream();
        }

        if (\is_string($body)) {
            return $streamFactory->createStream($body);
        }

        if ($body instanceof \Iterator) {
            return Psr7\Utils::streamFor($body);
        }

        if (\is_object($body) && \method_exists($body, '__toString')) {
            return $streamFactory->createStream((string) $body);
        }

        if (\is_callable($body)) {
            return Psr7\Utils::streamFor($body);
        }

        throw new InvalidArgumentException(\sprintf(
            'Passing %s to request option "body" is invalid; expected resource|string|null|StreamInterface|callable&object|Iterator|Stringable.',
            \get_debug_type($body)
        ));
    }

    /**
     * Configures the default options for a client.
     */
    private function configureDefaults(
        #[\SensitiveParameter]
        array $config
    ): void {
        $defaults = [
            'allow_redirects' => RedirectMiddleware::DEFAULT_SETTINGS,
            'http_errors' => true,
            'decode_content' => true,
            'verify' => true,
            'cookies' => false,
            'idn_conversion' => false,
            'protocols' => ['http', 'https'],
        ];

        // Use the standard Linux HTTP_PROXY and HTTPS_PROXY if set.

        // We can only trust the HTTP_PROXY environment variable in a CLI
        // process due to the fact that PHP has no reliable mechanism to
        // get environment variables that start with "HTTP_".
        if (\PHP_SAPI === 'cli' && ($proxy = Env::get('HTTP_PROXY'))) {
            $defaults['proxy']['http'] = $proxy;
        }

        if ($proxy = Env::get('HTTPS_PROXY')) {
            $defaults['proxy']['https'] = $proxy;
        }

        $noProxy = Env::get('NO_PROXY');
        if ($noProxy !== null) {
            $noProxy = ProxyOptions::normalizeNoProxy($noProxy);
            if ($noProxy !== []) {
                $defaults['proxy']['no'] = $noProxy;
            }
        }

        $this->config = $config + $defaults;

        if (!empty($config['cookies']) && $config['cookies'] === true) {
            $this->config['cookies'] = new CookieJar();
        }

        // Add the default user-agent header.
        if (!isset($this->config['headers'])) {
            $this->config['headers'] = ['User-Agent' => Utils::defaultUserAgent()];
        } else {
            self::assertHeaderOptionTypes($this->config['headers']);

            // Add the User-Agent header if one was not already set.
            $hasUserAgent = false;
            foreach (\array_keys($this->config['headers']) as $name) {
                if (Psr7\Utils::asciiToLower((string) $name) === 'user-agent') {
                    $hasUserAgent = true;
                    break;
                }
            }

            if (!$hasUserAgent) {
                $this->config['headers']['User-Agent'] = Utils::defaultUserAgent();
            }
        }
    }

    /**
     * Merges default options into the array.
     *
     * @param array $options Options to modify by reference
     */
    private function prepareDefaults(
        #[\SensitiveParameter]
        array $options
    ): array {
        if (isset($options['handler'])) {
            throw new InvalidArgumentException('The "handler" request option is not supported; configure the handler when creating the client, or use a separate client instance for requests that need a different handler.');
        }

        $defaults = $this->config;

        if (!empty($defaults['headers'])) {
            // Default headers are only added if they are not present.
            $defaults['_conditional'] = $defaults['headers'];
            unset($defaults['headers']);
        }

        // Special handling for headers is required as they are added as
        // conditional headers and as headers passed to a request ctor.
        if (\array_key_exists('headers', $options)) {
            // Allows default headers to be unset.
            if ($options['headers'] === null) {
                $defaults['_conditional'] = [];
                unset($options['headers']);
            } elseif (!\is_array($options['headers'])) {
                throw new InvalidArgumentException('headers must be an array');
            }
        }

        // Shallow merge defaults underneath options.
        $result = $options + $defaults;

        // Remove null values.
        foreach ($result as $k => $v) {
            if ($v === null) {
                unset($result[$k]);
            }
        }

        self::assertRequestOptionTypes($result);

        return $result;
    }

    private static function assertRequestOptionTypes(
        #[\SensitiveParameter]
        array $options
    ): void {
        if (isset($options['allow_redirects'])) {
            if (!\is_bool($options['allow_redirects']) && !\is_array($options['allow_redirects'])) {
                self::invalidRequestOptionType('allow_redirects', 'bool|array', $options['allow_redirects']);
            }

            if (\is_array($options['allow_redirects'])) {
                self::assertAllowRedirectsOptionTypes($options['allow_redirects']);
            }
        }

        if (
            isset($options['auth'])
            && $options['auth'] !== false
            && !\is_string($options['auth'])
            && !\is_array($options['auth'])
        ) {
            self::invalidRequestOptionType('auth', 'array{0: string, 1: string, 2?: string|null}|string|false|null', $options['auth']);
        }

        if (isset($options['auth']) && \is_array($options['auth']) && $options['auth'] !== []) {
            self::assertAuthOptionTypes($options['auth']);
        }

        self::assertTlsFileOptionTypes($options, 'cert');
        self::assertIfPresentAndNotString($options, 'cert_type');
        self::assertIfPresentAndNotNumber($options, 'connect_timeout');
        self::assertIfPresentAndNotInt($options, 'crypto_method');
        self::assertIfPresentAndNotInt($options, 'crypto_method_max');
        self::assertIfPresentAndNotBoolOrResource($options, 'debug');
        self::assertIfPresentAndNotBoolOrString($options, 'decode_content');
        self::assertIfPresentAndNotFiniteNonNegativeNumber($options, 'delay');
        self::assertIfPresentAndNotBoolOrInt($options, 'expect');

        if (isset($options['form_params'])) {
            self::assertFormParamTypes($options['form_params']);
        }

        self::assertIfPresentAndNotForceIpResolve($options);

        if (isset($options['headers'])) {
            self::assertHeaderOptionTypes($options['headers']);
        }

        self::assertIfPresentAndNotBool($options, 'http_errors');

        if (isset($options['multipart'])) {
            self::assertMultipartOptionTypes($options['multipart']);
        }

        self::assertValidMultiplex($options);
        self::assertIfPresentAndNotCallable($options, 'on_headers');
        self::assertIfPresentAndNotCallable($options, 'on_stats');
        self::assertIfPresentAndNotCallable($options, 'on_trailers');
        self::assertIfPresentAndNotCallable($options, 'progress');
        self::assertIfPresentAndNotProtocolArray($options, 'protocols');
        self::assertProxyOptionTypes($options);
        self::assertIfPresentAndNotNumber($options, 'read_timeout');
        self::assertIfPresentAndNotInt($options, 'retries');

        if (isset($options['sink']) && !\is_resource($options['sink']) && !\is_string($options['sink']) && !$options['sink'] instanceof StreamInterface) {
            self::invalidRequestOptionType('sink', 'resource|string|StreamInterface', $options['sink']);
        }

        self::assertTlsFileOptionTypes($options, 'ssl_key');
        self::assertIfPresentAndNotString($options, 'ssl_key_type');
        self::assertIfPresentAndNotBool($options, 'stream');
        self::assertIfPresentAndNotArray($options, 'stream_context', 'array<array-key, mixed>');
        self::assertIfPresentAndNotBool($options, 'synchronous');
        self::assertIfPresentAndNotNumber($options, 'timeout');
        self::assertIfPresentAndNotBoolOrString($options, 'verify');
        self::assertIfPresentAndNotStringOrNumber($options, 'version');
        self::assertIfPresentAndNotArray($options, 'curl', 'array<int|string, mixed>');

        if (isset($options['cookies']) && $options['cookies'] !== false && !$options['cookies'] instanceof CookieJarInterface) {
            self::invalidRequestOptionType('cookies', 'false|CookieJarInterface', $options['cookies']);
        }
    }

    private static function assertAllowRedirectsOptionTypes(array $allowRedirects): void
    {
        self::assertIfPresentAndNotInt($allowRedirects, 'max', 'allow_redirects.max');
        self::assertIfPresentAndNotBool($allowRedirects, 'strict', 'allow_redirects.strict');
        self::assertIfPresentAndNotBool($allowRedirects, 'referer', 'allow_redirects.referer');
        self::assertIfPresentAndNotProtocolArray($allowRedirects, 'protocols', 'allow_redirects.protocols');
        self::assertIfPresentAndNotCallable($allowRedirects, 'on_redirect', 'allow_redirects.on_redirect');
        self::assertIfPresentAndNotBool($allowRedirects, 'track_redirects', 'allow_redirects.track_redirects');
    }

    /**
     * @param array<array-key, mixed> $auth
     */
    private static function assertAuthOptionTypes(
        #[\SensitiveParameter]
        array $auth
    ): void {
        if (!\array_key_exists(0, $auth) || !\is_string($auth[0])) {
            self::invalidRequestOptionType('auth.0', 'string', $auth[0] ?? null);
        }

        if (!\array_key_exists(1, $auth) || !\is_string($auth[1])) {
            self::invalidRequestOptionType('auth.1', 'string', $auth[1] ?? null);
        }

        if (\array_key_exists(2, $auth) && $auth[2] !== null && !\is_string($auth[2])) {
            self::invalidRequestOptionType('auth.2', 'string|null', $auth[2]);
        }
    }

    /**
     * @param mixed $value
     */
    private static function assertFormParamTypes($value): void
    {
        if (!\is_array($value)) {
            self::invalidRequestOptionType('form_params', 'array<array-key, string|int|float|bool|null|array>', $value);

            return;
        }

        self::assertFormParamArray($value, 'form_params');
        self::assertFiniteFloats($value, 'form_params');
    }

    private static function assertFormParamArray(array $values, string $path): bool
    {
        foreach ($values as $key => $item) {
            $itemPath = $path.'.'.(string) $key;
            if (\is_array($item)) {
                if (!self::assertFormParamArray($item, $itemPath)) {
                    return false;
                }

                continue;
            }

            if ($item !== null && !\is_scalar($item)) {
                self::invalidRequestOptionType($itemPath, 'string|int|float|bool|null|array', $item);

                return false;
            }
        }

        return true;
    }

    private static function assertFiniteFloats(array $values, string $option): void
    {
        foreach ($values as $key => $value) {
            if (\is_array($value)) {
                self::assertFiniteFloats($value, $option.'.'.(string) $key);
            } elseif (\is_float($value) && !\is_finite($value)) {
                throw new InvalidArgumentException(\sprintf(
                    'Passing a non-finite float to request option "%s" is invalid; non-finite floats are not supported.',
                    DiagnosticValue::escape($option.'.'.(string) $key)
                ));
            }
        }
    }

    /**
     * @param mixed $headers
     */
    private static function assertHeaderOptionTypes(
        #[\SensitiveParameter]
        $headers
    ): void {
        if (!\is_array($headers)) {
            self::invalidRequestOptionType('headers', 'array<array-key, string|non-empty-array<array-key, string>>|null', $headers);

            return;
        }

        foreach ($headers as $name => $value) {
            $path = 'headers.'.(string) $name;
            if (\is_array($value)) {
                if ($value === []) {
                    self::invalidRequestOptionType($path, 'string|non-empty-array<array-key, string>', $value);
                }

                foreach ($value as $index => $item) {
                    if (!\is_string($item)) {
                        self::invalidRequestOptionType($path.'.'.(string) $index, 'string', $item);
                    }
                }
            } elseif (!\is_string($value)) {
                self::invalidRequestOptionType($path, 'string|non-empty-array<array-key, string>', $value);
            }
        }
    }

    /**
     * @param mixed $multipart
     */
    private static function assertMultipartOptionTypes($multipart): void
    {
        if (!\is_array($multipart)) {
            self::invalidRequestOptionType('multipart', 'array<array-key, array{name: string|int, contents: mixed, headers?: array<array-key, string>, filename?: string}>', $multipart);

            return;
        }

        foreach ($multipart as $index => $part) {
            $path = 'multipart.'.(string) $index;
            if (!\is_array($part)) {
                self::invalidRequestOptionType($path, 'array{name: string|int, contents: mixed, headers?: array<array-key, string>, filename?: string}', $part);

                return;
            }

            if (!\array_key_exists('name', $part) || (!\is_string($part['name']) && !\is_int($part['name']))) {
                self::invalidRequestOptionType($path.'.name', 'string|int', $part['name'] ?? null);
            }

            if (!\array_key_exists('contents', $part)) {
                self::invalidRequestOptionType($path, 'array{name: string|int, contents: mixed, headers?: array<array-key, string>, filename?: string}', $part);
            }

            if (\array_key_exists('headers', $part)) {
                if (!\is_array($part['headers'])) {
                    self::invalidRequestOptionType($path.'.headers', 'array<array-key, string>', $part['headers']);

                    return;
                }

                foreach ($part['headers'] as $name => $value) {
                    if (!\is_string($value)) {
                        self::invalidRequestOptionType($path.'.headers.'.(string) $name, 'string', $value);
                    }
                }
            }

            if (\array_key_exists('filename', $part) && !\is_string($part['filename'])) {
                self::invalidRequestOptionType($path.'.filename', 'string', $part['filename']);
            }
        }
    }

    private static function assertProxyOptionTypes(
        #[\SensitiveParameter]
        array $options
    ): void {
        if (!isset($options['proxy'])) {
            return;
        }

        if (!\is_string($options['proxy']) && !\is_array($options['proxy'])) {
            self::invalidRequestOptionType('proxy', 'string|array{http?: string|null, https?: string|null, no?: string|array<array-key, string>|null}', $options['proxy']);

            return;
        }

        if (!\is_array($options['proxy'])) {
            return;
        }

        foreach (['http', 'https'] as $scheme) {
            if (\array_key_exists($scheme, $options['proxy']) && $options['proxy'][$scheme] !== null && !\is_string($options['proxy'][$scheme])) {
                self::invalidRequestOptionType('proxy.'.$scheme, 'string|null', $options['proxy'][$scheme]);
            }
        }

        if (!\array_key_exists('no', $options['proxy']) || $options['proxy']['no'] === null) {
            return;
        }

        if (\is_string($options['proxy']['no'])) {
            return;
        }

        if (!\is_array($options['proxy']['no'])) {
            self::invalidRequestOptionType('proxy.no', 'string|array<array-key, string>|null', $options['proxy']['no']);

            return;
        }

        foreach ($options['proxy']['no'] as $index => $noProxy) {
            if (!\is_string($noProxy)) {
                self::invalidRequestOptionType('proxy.no.'.(string) $index, 'string', $noProxy);
            }
        }
    }

    private static function assertTlsFileOptionTypes(
        #[\SensitiveParameter]
        array $options,
        string $option
    ): void {
        if (!isset($options[$option])) {
            return;
        }

        if (\is_string($options[$option])) {
            return;
        }

        if (!\is_array($options[$option])) {
            self::invalidRequestOptionType($option, 'string|array{0: string, 1?: string}', $options[$option]);

            return;
        }

        if (!\array_key_exists(0, $options[$option]) || !\is_string($options[$option][0])) {
            self::invalidRequestOptionType($option.'.0', 'string', $options[$option][0] ?? null);
        }

        if (\array_key_exists(1, $options[$option]) && $options[$option][1] !== null && !\is_string($options[$option][1])) {
            self::invalidRequestOptionType($option.'.1', 'string|null', $options[$option][1]);
        }
    }

    private static function assertIfPresentAndNotArray(
        #[\SensitiveParameter]
        array $options,
        string $option,
        string $expected
    ): void {
        if (\array_key_exists($option, $options) && !\is_array($options[$option])) {
            self::invalidRequestOptionType($option, $expected, $options[$option]);
        }
    }

    private static function assertIfPresentAndNotBool(
        #[\SensitiveParameter]
        array $options,
        string $option,
        ?string $path = null
    ): void {
        if (\array_key_exists($option, $options) && !\is_bool($options[$option])) {
            self::invalidRequestOptionType($path ?? $option, 'bool', $options[$option]);
        }
    }

    private static function assertValidMultiplex(
        #[\SensitiveParameter]
        array $options
    ): void {
        if (!\array_key_exists('multiplex', $options) || $options['multiplex'] === null) {
            return;
        }

        if (!\in_array($options['multiplex'], [Multiplexing::NONE, Multiplexing::EAGER, Multiplexing::WAIT, Multiplexing::REQUIRE_EAGER, Multiplexing::REQUIRE_WAIT], true)) {
            throw new InvalidArgumentException(\sprintf(
                'The "multiplex" option must be null or a GuzzleHttp\\Multiplexing::* constant; received %s.',
                \get_debug_type($options['multiplex'])
            ));
        }
    }

    private static function assertIfPresentAndNotBoolOrInt(
        #[\SensitiveParameter]
        array $options,
        string $option
    ): void {
        if (\array_key_exists($option, $options) && !\is_bool($options[$option]) && !\is_int($options[$option])) {
            self::invalidRequestOptionType($option, 'bool|int', $options[$option]);
        }
    }

    private static function assertIfPresentAndNotBoolOrResource(
        #[\SensitiveParameter]
        array $options,
        string $option
    ): void {
        if (\array_key_exists($option, $options) && !\is_bool($options[$option]) && !\is_resource($options[$option])) {
            self::invalidRequestOptionType($option, 'bool|resource', $options[$option]);
        }
    }

    private static function assertIfPresentAndNotBoolOrString(
        #[\SensitiveParameter]
        array $options,
        string $option
    ): void {
        if (\array_key_exists($option, $options) && !\is_bool($options[$option]) && !\is_string($options[$option])) {
            self::invalidRequestOptionType($option, 'bool|string', $options[$option]);
        }
    }

    private static function assertIfPresentAndNotCallable(
        #[\SensitiveParameter]
        array $options,
        string $option,
        ?string $path = null
    ): void {
        if (\array_key_exists($option, $options) && !\is_callable($options[$option])) {
            self::invalidRequestOptionType($path ?? $option, 'callable', $options[$option]);
        }
    }

    private static function assertIfPresentAndNotInt(
        #[\SensitiveParameter]
        array $options,
        string $option,
        ?string $path = null
    ): void {
        if (\array_key_exists($option, $options) && !\is_int($options[$option])) {
            self::invalidRequestOptionType($path ?? $option, 'int', $options[$option]);
        }
    }

    private static function assertIfPresentAndNotNumber(
        #[\SensitiveParameter]
        array $options,
        string $option
    ): void {
        if (\array_key_exists($option, $options) && !\is_int($options[$option]) && !\is_float($options[$option])) {
            self::invalidRequestOptionType($option, 'int|float', $options[$option]);
        }
    }

    /**
     * @param array<array-key, mixed> $options
     */
    private static function assertIfPresentAndNotFiniteNonNegativeNumber(
        #[\SensitiveParameter]
        array $options,
        string $option
    ): void {
        if (!\array_key_exists($option, $options)) {
            return;
        }

        if (!\is_int($options[$option]) && !\is_float($options[$option])) {
            self::invalidRequestOptionType($option, 'finite int|float greater than or equal to 0', $options[$option]);

            return;
        }

        if (!\is_finite((float) $options[$option]) || $options[$option] < 0) {
            self::invalidRequestOptionType($option, 'finite int|float greater than or equal to 0', $options[$option]);
        }
    }

    /**
     * @param array<array-key, mixed> $options
     */
    private static function assertIfPresentAndNotForceIpResolve(
        #[\SensitiveParameter]
        array $options
    ): void {
        if (!\array_key_exists('force_ip_resolve', $options)) {
            return;
        }

        if (
            !\is_string($options['force_ip_resolve'])
            || ($options['force_ip_resolve'] !== 'v4' && $options['force_ip_resolve'] !== 'v6')
        ) {
            self::invalidRequestOptionType('force_ip_resolve', '"v4"|"v6"', $options['force_ip_resolve']);
        }
    }

    private static function assertIfPresentAndNotString(
        #[\SensitiveParameter]
        array $options,
        string $option
    ): void {
        if (\array_key_exists($option, $options) && !\is_string($options[$option])) {
            self::invalidRequestOptionType($option, 'string', $options[$option]);
        }
    }

    /**
     * @param array<array-key, mixed> $options
     */
    private static function assertIfPresentAndNotProtocolArray(
        #[\SensitiveParameter]
        array $options,
        string $option,
        ?string $path = null
    ): void {
        if (!\array_key_exists($option, $options)) {
            return;
        }

        $path = $path ?? $option;

        if (!\is_array($options[$option]) || $options[$option] === []) {
            self::invalidRequestOptionType($path, 'non-empty-array<array-key, "http"|"https">', $options[$option]);

            return;
        }

        foreach ($options[$option] as $index => $protocol) {
            if (!\is_string($protocol)) {
                self::invalidRequestOptionType($path.'.'.(string) $index, 'string', $protocol);

                continue;
            }

            if ($protocol !== 'http' && $protocol !== 'https') {
                self::invalidRequestOptionType($path.'.'.(string) $index, '"http"|"https"', $protocol);
            }
        }
    }

    private static function assertIfPresentAndNotStringOrNumber(
        #[\SensitiveParameter]
        array $options,
        string $option
    ): void {
        if (
            \array_key_exists($option, $options)
            && !\is_string($options[$option])
            && !\is_int($options[$option])
            && !\is_float($options[$option])
        ) {
            self::invalidRequestOptionType($option, 'string|int|float', $options[$option]);
        }
    }

    /**
     * @param mixed $value
     */
    private static function invalidRequestOptionType(
        string $option,
        string $expected,
        #[\SensitiveParameter]
        $value
    ): void {
        throw new InvalidArgumentException(\sprintf(
            'Passing %s to request option "%s" is invalid; expected %s.',
            \get_debug_type($value),
            DiagnosticValue::escape($option),
            $expected
        ));
    }

    /**
     * Transfers the given request and applies request options.
     *
     * The URI of the request is not modified and the request options are used
     * as-is without merging in default options.
     *
     * @param array $options See {@see RequestOptions}.
     *
     * @return PromiseInterface<ResponseInterface, mixed>
     */
    private function transfer(
        #[\SensitiveParameter]
        RequestInterface $request,
        #[\SensitiveParameter]
        array $options
    ): PromiseInterface {
        $request = $this->applyOptions($request, $options);

        /** @var callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed> $handler */
        $handler = $this->config['handler'];

        try {
            self::assertRequestProtocolVersion($request);

            /** @var PromiseInterface<ResponseInterface, mixed> */
            return P\Create::promiseFor($handler($request, $options));
        } catch (\Throwable $e) {
            /** @var PromiseInterface<ResponseInterface, mixed> */
            return P\Create::rejectionFor($e);
        }
    }

    /**
     * Applies the array of request options to a request.
     */
    private function applyOptions(
        #[\SensitiveParameter]
        RequestInterface $request,
        #[\SensitiveParameter]
        array &$options
    ): RequestInterface {
        $modify = [
            'set_headers' => [],
        ];

        // Validate the response and stream factories up front. Every request
        // yields a response, and the built-in handlers build the response body
        // stream via the stream factory even when the request has no body, so
        // both must be validated unconditionally.
        self::requireResponseFactory($options[RequestOptions::RESPONSE_FACTORY] ?? new HttpFactory());
        $streamFactory = self::requireStreamFactory($options[RequestOptions::STREAM_FACTORY] ?? new HttpFactory());

        if (isset($options['headers'])) {
            if (array_keys($options['headers']) === range(0, count($options['headers']) - 1)) {
                throw new InvalidArgumentException('The headers array must have header name as keys.');
            }
            $modify['set_headers'] = $options['headers'];
            unset($options['headers']);
        }

        if (isset($options['form_params'])) {
            if (isset($options['multipart'])) {
                throw new InvalidArgumentException('You cannot use '
                    .'form_params and multipart at the same time. Use the '
                    .'form_params option if you want to send application/'
                    .'x-www-form-urlencoded requests, and the multipart '
                    .'option to send multipart/form-data requests.');
            }
            $options['body'] = \http_build_query($options['form_params'], '', '&');
            unset($options['form_params']);
            // Ensure that we don't have the header in different case and set the new value.
            $options['_conditional'] = Psr7\Utils::caselessRemove(['Content-Type'], $options['_conditional']);
            $options['_conditional']['Content-Type'] = 'application/x-www-form-urlencoded';
        }

        if (isset($options['multipart'])) {
            $options['body'] = new Psr7\MultipartStream($options['multipart']);
            unset($options['multipart']);
        }

        if (isset($options['json'])) {
            try {
                $options['body'] = \json_encode($options['json'], \JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new InvalidArgumentException('json_encode error: '.$e->getMessage(), 0, $e);
            }

            unset($options['json']);
            // Ensure that we don't have the header in different case and set the new value.
            $options['_conditional'] = Psr7\Utils::caselessRemove(['Content-Type'], $options['_conditional']);
            $options['_conditional']['Content-Type'] = 'application/json';
        }

        if (isset($options['decode_content']) && \is_string($options['decode_content'])) {
            // Ensure that we don't have the header in different case and set the new value.
            $options['_conditional'] = Psr7\Utils::caselessRemove(['Accept-Encoding'], $options['_conditional']);
            $modify['set_headers']['Accept-Encoding'] = (string) $options['decode_content'];
        }

        if (isset($options['body'])) {
            if (\is_array($options['body'])) {
                throw $this->invalidBody();
            }
            $modify['body'] = self::createBodyStream($options['body'], $streamFactory);
            unset($options['body']);
        }

        if (isset($options['query'])) {
            $value = $options['query'];
            if (\is_array($value)) {
                self::assertFiniteFloats($value, 'query');
                $value = \http_build_query($value, '', '&', \PHP_QUERY_RFC3986);
            }
            if (!\is_string($value)) {
                throw new InvalidArgumentException('query must be a string or array');
            }
            $modify['query'] = $value;
            unset($options['query']);
        }

        if (isset($options['version'])) {
            $modify['version'] = self::normalizeProtocolVersion($options['version']);
        }

        $request = Psr7\Utils::modifyRequest($request, $modify);
        if ($request->getBody() instanceof Psr7\MultipartStream) {
            // Use a multipart/form-data POST if a Content-Type is not set.
            // Ensure that we don't have the header in different case and set the new value.
            $options['_conditional'] = Psr7\Utils::caselessRemove(['Content-Type'], $options['_conditional']);
            $options['_conditional']['Content-Type'] = self::getMultipartContentType($request->getBody());
        }

        // Merge in conditional headers if they are not present.
        if (isset($options['_conditional'])) {
            // Build up the changes so it's in a single clone of the message.
            $modify = [];
            foreach ($options['_conditional'] as $k => $v) {
                $name = (string) $k;
                if (!$request->hasHeader($name)) {
                    $modify['set_headers'][$name] = $v;
                }
            }
            $request = Psr7\Utils::modifyRequest($request, $modify);
            // Don't pass this internal value along to middleware/handlers.
            unset($options['_conditional']);
        }

        return $request;
    }

    /**
     * @param string|int|float $version
     */
    private static function normalizeProtocolVersion($version): string
    {
        $version = \is_float($version) ? \number_format($version, 1, '.', '') : (string) $version;

        self::assertProtocolVersion($version);

        return $version;
    }

    private static function assertProtocolVersion(string $version): void
    {
        if ('' === $version) {
            throw new InvalidArgumentException('HTTP protocol version must not be empty.');
        }

        if (1 !== \preg_match('/^\d+(?:\.\d+)?$/D', $version)) {
            throw new InvalidArgumentException('HTTP protocol version must be a valid HTTP version number.');
        }
    }

    private static function assertRequestProtocolVersion(
        #[\SensitiveParameter]
        RequestInterface $request
    ): void {
        $version = $request->getProtocolVersion();

        if ('' === $version) {
            throw new RequestException('HTTP protocol version must not be empty.', $request);
        }

        if (1 !== \preg_match('/^\d+(?:\.\d+)?$/D', $version)) {
            throw new RequestException('HTTP protocol version must be a valid HTTP version number.', $request);
        }
    }

    private static function getMultipartContentType(Psr7\MultipartStream $body): string
    {
        $boundary = $body->getBoundary();

        if (false !== \strpbrk($boundary, '()<>@,;:\"/[]?= ')) {
            $boundary = '"'.$boundary.'"';
        }

        return 'multipart/form-data; boundary='.$boundary;
    }

    /**
     * Return an InvalidArgumentException with pre-set message.
     */
    private function invalidBody(): InvalidArgumentException
    {
        return new InvalidArgumentException('Passing in the "body" request '
            .'option as an array to send a request is not supported. '
            .'Please use the "form_params" request option to send a '
            .'application/x-www-form-urlencoded request, or the "multipart" '
            .'request option to send a multipart/form-data request.');
    }
}
