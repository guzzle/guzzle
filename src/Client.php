<?php

namespace GuzzleHttp;

use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\InvalidArgumentException;
use GuzzleHttp\Promise as P;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
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

    /**
     * @var array Default request options
     */
    private $config;

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
     *   Psr7\Http\Message\ResponseInterface on success.
     *   If no handler is provided, a default handler will be created
     *   that enables all of the request options below by attaching all of the
     *   default middleware to the handler.
     * - base_uri: (string|UriInterface) Base URI of the client that is merged
     *   into relative URIs. Can be a string or instance of UriInterface.
     * - **: any request option
     *
     * @param array $config Client configuration settings.
     *
     * @see RequestOptions for a list of available request options.
     */
    public function __construct(array $config = [])
    {
        if (!isset($config['handler'])) {
            $config['handler'] = HandlerStack::create();
        } elseif (!\is_callable($config['handler'])) {
            throw new InvalidArgumentException('handler must be a callable');
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

        self::requireRequestFactory($config[RequestOptions::REQUEST_FACTORY]);
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
     * @param array $options Request options to apply to the given
     *                       request and to the transfer. See {@see RequestOptions}.
     *
     * @return PromiseInterface<ResponseInterface, mixed>
     */
    public function sendAsync(RequestInterface $request, array $options = []): PromiseInterface
    {
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
     * @param array $options Request options to apply to the given
     *                       request and to the transfer. See {@see RequestOptions}.
     *
     * @throws GuzzleException
     */
    public function send(RequestInterface $request, array $options = []): ResponseInterface
    {
        $options[RequestOptions::SYNCHRONOUS] = true;

        return $this->sendAsync($request, $options)->wait();
    }

    /**
     * The HttpClient PSR (PSR-18) specify this method.
     *
     * {@inheritDoc}
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
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
     * contain the query string as well. Use an array to provide a URL
     * template and additional variables to use in the URL template expansion.
     *
     * @param string              $method  HTTP method
     * @param string|UriInterface $uri     URI object or string.
     * @param array               $options Request options to apply. See {@see RequestOptions}.
     *
     * @return PromiseInterface<ResponseInterface, mixed>
     */
    public function requestAsync(string $method, $uri = '', array $options = []): PromiseInterface
    {
        $options = $this->prepareDefaults($options);

        $factory = new HttpFactory();
        $uriFactory = self::requireUriFactory($options[RequestOptions::URI_FACTORY] ?? $factory);
        $requestFactory = self::requireRequestFactory($options[RequestOptions::REQUEST_FACTORY] ?? $factory);

        // Merge the URI into the base URI.
        $uriIsString = \is_string($uri);
        $uri = self::createUri($uri, $uriFactory);
        $builtUri = $this->buildUri($uri, $options);
        if ($uriIsString && $builtUri !== $uri) {
            $builtUri = self::createUri((string) $builtUri, $uriFactory);
        }

        $uri = $builtUri;
        $request = $requestFactory->createRequest($method, $uri);

        return $this->transfer($request, $options);
    }

    /**
     * Create and send an HTTP request.
     *
     * Use an absolute path to override the base path of the client, or a
     * relative path to append to the base path of the client. The URL can
     * contain the query string as well.
     *
     * @param string              $method  HTTP method.
     * @param string|UriInterface $uri     URI object or string.
     * @param array               $options Request options to apply. See {@see RequestOptions}.
     *
     * @throws GuzzleException
     */
    public function request(string $method, $uri = '', array $options = []): ResponseInterface
    {
        $options[RequestOptions::SYNCHRONOUS] = true;

        return $this->requestAsync($method, $uri, $options)->wait();
    }

    /**
     * Get a client configuration option.
     *
     * These options include default request options of the client, a "handler"
     * (if utilized by the concrete client), and a "base_uri" if utilized by
     * the concrete client.
     *
     * @param string|null $option The config option to retrieve.
     *
     * @return mixed
     */
    public function getConfig(?string $option = null)
    {
        return $option === null
            ? $this->config
            : ($this->config[$option] ?? null);
    }

    private function buildUri(UriInterface $uri, array $config): UriInterface
    {
        if (isset($config['base_uri'])) {
            $uriFactory = self::requireUriFactory($config[RequestOptions::URI_FACTORY] ?? new HttpFactory());
            $uri = Psr7\UriResolver::resolve(self::createUri($config['base_uri'], $uriFactory), $uri);
        }

        if (isset($config['idn_conversion']) && ($config['idn_conversion'] !== false)) {
            $idnOptions = ($config['idn_conversion'] === true) ? \IDNA_DEFAULT : $config['idn_conversion'];
            $uri = Utils::idnUriConvert($uri, $idnOptions);
        }

        return $uri->getScheme() === '' && $uri->getHost() !== '' ? $uri->withScheme('http') : $uri;
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

        if (\is_scalar($body)) {
            return $streamFactory->createStream((string) $body);
        }

        if ($body instanceof \Iterator || \is_callable($body)) {
            return Psr7\Utils::streamFor($body);
        }

        if (\is_object($body) && \method_exists($body, '__toString')) {
            return $streamFactory->createStream((string) $body);
        }

        throw new InvalidArgumentException('Invalid resource type: '.\gettype($body));
    }

    /**
     * Configures the default options for a client.
     */
    private function configureDefaults(array $config): void
    {
        $defaults = [
            'allow_redirects' => RedirectMiddleware::DEFAULT_SETTINGS,
            'http_errors' => true,
            'decode_content' => true,
            'verify' => true,
            'cookies' => false,
            'idn_conversion' => false,
        ];

        // Use the standard Linux HTTP_PROXY and HTTPS_PROXY if set.

        // We can only trust the HTTP_PROXY environment variable in a CLI
        // process due to the fact that PHP has no reliable mechanism to
        // get environment variables that start with "HTTP_".
        if (\PHP_SAPI === 'cli' && ($proxy = Utils::getenv('HTTP_PROXY'))) {
            $defaults['proxy']['http'] = $proxy;
        }

        if ($proxy = Utils::getenv('HTTPS_PROXY')) {
            $defaults['proxy']['https'] = $proxy;
        }

        $noProxy = Utils::getenv('NO_PROXY');
        if ($noProxy !== null) {
            $noProxy = Utils::normalizeNoProxy($noProxy);
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
            // Add the User-Agent header if one was not already set.
            foreach (\array_keys($this->config['headers']) as $name) {
                if (\strtolower($name) === 'user-agent') {
                    return;
                }
            }
            $this->config['headers']['User-Agent'] = Utils::defaultUserAgent();
        }
    }

    /**
     * Merges default options into the array.
     *
     * @param array $options Options to modify by reference
     */
    private function prepareDefaults(array $options): array
    {
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

        return $result;
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
    private function transfer(RequestInterface $request, array $options): PromiseInterface
    {
        $request = $this->applyOptions($request, $options);

        self::assertProtocolVersion($request->getProtocolVersion());

        /** @var HandlerStack $handler */
        $handler = $options['handler'];

        try {
            /** @var PromiseInterface<ResponseInterface, mixed> */
            return P\Create::promiseFor($handler($request, $options));
        } catch (\Exception $e) {
            /** @var PromiseInterface<ResponseInterface, mixed> */
            return P\Create::rejectionFor($e);
        }
    }

    /**
     * Applies the array of request options to a request.
     */
    private function applyOptions(RequestInterface $request, array &$options): RequestInterface
    {
        $modify = [
            'set_headers' => [],
        ];

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
            $options['body'] = Utils::jsonEncode($options['json']);
            unset($options['json']);
            // Ensure that we don't have the header in different case and set the new value.
            $options['_conditional'] = Psr7\Utils::caselessRemove(['Content-Type'], $options['_conditional']);
            $options['_conditional']['Content-Type'] = 'application/json';
        }

        if (!empty($options['decode_content'])
            && $options['decode_content'] !== true
        ) {
            // Ensure that we don't have the header in different case and set the new value.
            $options['_conditional'] = Psr7\Utils::caselessRemove(['Accept-Encoding'], $options['_conditional']);
            $modify['set_headers']['Accept-Encoding'] = (string) $options['decode_content'];
        }

        if (isset($options['body'])) {
            if (\is_array($options['body'])) {
                throw $this->invalidBody();
            }
            $streamFactory = self::requireStreamFactory($options[RequestOptions::STREAM_FACTORY] ?? new HttpFactory());
            $modify['body'] = self::createBodyStream($options['body'], $streamFactory);
            unset($options['body']);
        }

        if (isset($options['auth']) && \is_array($options['auth'])) {
            $value = $options['auth'];

            if (!\array_key_exists(0, $value) || !\array_key_exists(1, $value)) {
                throw new InvalidArgumentException('auth must contain username and password strings');
            }

            $username = $value[0];
            $password = $value[1];

            if (!\is_string($username) || !\is_string($password)) {
                throw new InvalidArgumentException('auth must contain username and password strings');
            }

            $type = 'basic';
            if (\array_key_exists(2, $value)) {
                $type = $value[2];
                if (!\is_string($type)) {
                    throw new InvalidArgumentException('auth type must be a string');
                }
            }

            switch (\strtolower($type)) {
                case 'basic':
                    // Ensure that we don't have the header in different case and set the new value.
                    $modify['set_headers'] = Psr7\Utils::caselessRemove(['Authorization'], $modify['set_headers']);
                    $modify['set_headers']['Authorization'] = 'Basic '
                        .\base64_encode($username.':'.$password);
                    break;
                case 'digest':
                    // @todo: Do not rely on curl
                    $options['curl'][\CURLOPT_HTTPAUTH] = \CURLAUTH_DIGEST;
                    $options['curl'][\CURLOPT_USERPWD] = $username.':'.$password;
                    break;
                case 'ntlm':
                    $options['curl'][\CURLOPT_HTTPAUTH] = \CURLAUTH_NTLM;
                    $options['curl'][\CURLOPT_USERPWD] = $username.':'.$password;
                    break;
                default:
                    throw new InvalidArgumentException(\sprintf('Unsupported auth type "%s"', $type));
            }
        }

        if (isset($options['query'])) {
            $value = $options['query'];
            if (\is_array($value)) {
                $value = \http_build_query($value, '', '&', \PHP_QUERY_RFC3986);
            }
            if (!\is_string($value)) {
                throw new InvalidArgumentException('query must be a string or array');
            }
            $modify['query'] = $value;
            unset($options['query']);
        }

        // Ensure that sink is not an invalid value.
        if (isset($options['sink'])) {
            // TODO: Add more sink validation?
            if (\is_bool($options['sink'])) {
                throw new InvalidArgumentException('sink must not be a boolean');
            }
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
                if (!$request->hasHeader($k)) {
                    $modify['set_headers'][$k] = $v;
                }
            }
            $request = Psr7\Utils::modifyRequest($request, $modify);
            // Don't pass this internal value along to middleware/handlers.
            unset($options['_conditional']);
        }

        return $request;
    }

    /**
     * @param string|float $version
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
