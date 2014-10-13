<?php
namespace GuzzleHttp;

use GuzzleHttp\Event\HasEmitterTrait;
use GuzzleHttp\Message\MessageFactory;
use GuzzleHttp\Message\MessageFactoryInterface;
use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Message\FutureResponse;
use GuzzleHttp\Ring\Client\Middleware;
use GuzzleHttp\Ring\Client\CurlMultiHandler;
use GuzzleHttp\Ring\Client\CurlHandler;
use GuzzleHttp\Ring\Client\StreamHandler;
use GuzzleHttp\Ring\Core;
use GuzzleHttp\Ring\Future\FutureInterface;
use GuzzleHttp\Exception\RequestException;
use React\Promise\FulfilledPromise;
use React\Promise\RejectedPromise;

/**
 * HTTP client
 */
class Client implements ClientInterface
{
    use HasEmitterTrait;

    /** @var MessageFactoryInterface Request factory used by the client */
    private $messageFactory;

    /** @var Url Base URL of the client */
    private $baseUrl;

    /** @var array Default request options */
    private $defaults;

    /** @var callable Request state machine */
    private $fsm;

    /**
     * Clients accept an array of constructor parameters.
     *
     * Here's an example of creating a client using an URI template for the
     * client's base_url and an array of default request options to apply
     * to each request:
     *
     *     $client = new Client([
     *         'base_url' => [
     *              'http://www.foo.com/{version}/',
     *              ['version' => '123']
     *          ],
     *         'defaults' => [
     *             'timeout'         => 10,
     *             'allow_redirects' => false,
     *             'proxy'           => '192.168.16.1:10'
     *         ]
     *     ]);
     *
     * @param array $config Client configuration settings
     *     - base_url: Base URL of the client that is merged into relative URLs.
     *       Can be a string or an array that contains a URI template followed
     *       by an associative array of expansion variables to inject into the
     *       URI template.
     *     - handler: callable RingPHP handler used to transfer requests
     *     - message_factory: Factory used to create request and response object
     *     - defaults: Default request options to apply to each request
     *     - emitter: Event emitter used for request events
     *     - fsm: (internal use only) The request finite state machine. A
     *       function that accepts a transaction and optional final state. The
     *       function is responsible for transitioning a request through its
     *       lifecycle events.
     */
    public function __construct(array $config = [])
    {
        $this->configureBaseUrl($config);
        $this->configureDefaults($config);

        if (isset($config['emitter'])) {
            $this->emitter = $config['emitter'];
        }

        $this->messageFactory = isset($config['message_factory'])
            ? $config['message_factory']
            : new MessageFactory();

        if (isset($config['fsm'])) {
            $this->fsm = $config['fsm'];
        } else {
            if (isset($config['handler'])) {
                $handler = $config['handler'];
            } elseif (isset($config['adapter'])) {
                $handler = $config['adapter'];
            } else {
                $handler = static::getDefaultHandler();
            }
            $this->fsm = new RequestFsm($handler, $this->messageFactory);
        }
    }

    /**
     * Create a default handler to use based on the environment
     *
     * @throws \RuntimeException if no viable Handler is available.
     */
    public static function getDefaultHandler()
    {
        $default = $future = $streaming = null;

        if (extension_loaded('curl')) {
            $config = [
                'select_timeout' => getenv('GUZZLE_CURL_SELECT_TIMEOUT') ?: 1
            ];
            if ($maxHandles = getenv('GUZZLE_CURL_MAX_HANDLES')) {
                $config['max_handles'] = $maxHandles;
            }
            $future = new CurlMultiHandler($config);
            if (function_exists('curl_reset')) {
                $default = new CurlHandler();
            }
        }

        if (ini_get('allow_url_fopen')) {
            $streaming = new StreamHandler();
        }

        if (!($default = ($default ?: $future) ?: $streaming)) {
            throw new \RuntimeException('Guzzle requires cURL, the '
                . 'allow_url_fopen ini setting, or a custom HTTP handler.');
        }

        $handler = $default;

        if ($streaming && $streaming !== $default) {
            $handler = Middleware::wrapStreaming($default, $streaming);
        }

        if ($future) {
            $handler = Middleware::wrapFuture($handler, $future);
        }

        return $handler;
    }

    /**
     * Get the default User-Agent string to use with Guzzle
     *
     * @return string
     */
    public static function getDefaultUserAgent()
    {
        static $defaultAgent = '';
        if (!$defaultAgent) {
            $defaultAgent = 'Guzzle/' . self::VERSION;
            if (extension_loaded('curl')) {
                $defaultAgent .= ' curl/' . curl_version()['version'];
            }
            $defaultAgent .= ' PHP/' . PHP_VERSION;
        }

        return $defaultAgent;
    }

    public function getDefaultOption($keyOrPath = null)
    {
        return $keyOrPath === null
            ? $this->defaults
            : Utils::getPath($this->defaults, $keyOrPath);
    }

    public function setDefaultOption($keyOrPath, $value)
    {
        Utils::setPath($this->defaults, $keyOrPath, $value);
    }

    public function getBaseUrl()
    {
        return (string) $this->baseUrl;
    }

    public function createRequest($method, $url = null, array $options = [])
    {
        $headers = $this->mergeDefaults($options);
        // Use a clone of the client's emitter
        $options['config']['emitter'] = clone $this->getEmitter();

        $request = $this->messageFactory->createRequest(
            $method,
            $url ? (string) $this->buildUrl($url) : (string) $this->baseUrl,
            $options
        );

        // Merge in default headers
        if ($headers) {
            foreach ($headers as $key => $value) {
                if (!$request->hasHeader($key)) {
                    $request->setHeader($key, $value);
                }
            }
        }

        return $request;
    }

    public function get($url = null, $options = [])
    {
        return $this->send($this->createRequest('GET', $url, $options));
    }

    public function head($url = null, array $options = [])
    {
        return $this->send($this->createRequest('HEAD', $url, $options));
    }

    public function delete($url = null, array $options = [])
    {
        return $this->send($this->createRequest('DELETE', $url, $options));
    }

    public function put($url = null, array $options = [])
    {
        return $this->send($this->createRequest('PUT', $url, $options));
    }

    public function patch($url = null, array $options = [])
    {
        return $this->send($this->createRequest('PATCH', $url, $options));
    }

    public function post($url = null, array $options = [])
    {
        return $this->send($this->createRequest('POST', $url, $options));
    }

    public function options($url = null, array $options = [])
    {
        return $this->send($this->createRequest('OPTIONS', $url, $options));
    }

    public function send(RequestInterface $request)
    {
        $trans = new Transaction($this, $request);
        $fn = $this->fsm;

        // Ensure a future response is returned if one was requested.
        if ($request->getConfig()->get('future')) {
            try {
                $fn($trans);
                // Turn the normal response into a future if needed.
                return $trans->response instanceof FutureInterface
                    ? $trans->response
                    : new FutureResponse(new FulfilledPromise($trans->response));
            } catch (RequestException $e) {
                // Wrap the exception in a promise if the user asked for a future.
                return new FutureResponse(new RejectedPromise($e));
            }
        } else {
            try {
                $fn($trans);
                return $trans->response instanceof FutureInterface
                    ? $trans->response->wait()
                    : $trans->response;
            } catch (\Exception $e) {
                throw RequestException::wrapException($trans->request, $e);
            }
        }
    }

    /**
     * Get an array of default options to apply to the client
     *
     * @return array
     */
    protected function getDefaultOptions()
    {
        $settings = [
            'allow_redirects' => true,
            'exceptions'      => true,
            'decode_content'  => true,
            'verify'          => true
        ];

        // Use the standard Linux HTTP_PROXY and HTTPS_PROXY if set
        if ($proxy = getenv('HTTP_PROXY')) {
            $settings['proxy']['http'] = $proxy;
        }

        if ($proxy = getenv('HTTPS_PROXY')) {
            $settings['proxy']['https'] = $proxy;
        }

        return $settings;
    }

    /**
     * Expand a URI template and inherit from the base URL if it's relative
     *
     * @param string|array $url URL or URI template to expand
     *
     * @return string
     */
    private function buildUrl($url)
    {
        // URI template (absolute or relative)
        if (!is_array($url)) {
            return strpos($url, '://')
                ? (string) $url
                : (string) $this->baseUrl->combine($url);
        }

        // Absolute URL
        if (strpos($url[0], '://')) {
            return Utils::uriTemplate($url[0], $url[1]);
        }

        // Combine the relative URL with the base URL
        return (string) $this->baseUrl->combine(
            Utils::uriTemplate($url[0], $url[1])
        );
    }

    private function configureBaseUrl(&$config)
    {
        if (!isset($config['base_url'])) {
            $this->baseUrl = new Url('', '');
        } elseif (is_array($config['base_url'])) {
            $this->baseUrl = Url::fromString(
                Utils::uriTemplate(
                    $config['base_url'][0],
                    $config['base_url'][1]
                )
            );
            $config['base_url'] = (string) $this->baseUrl;
        } else {
            $this->baseUrl = Url::fromString($config['base_url']);
        }
    }

    private function configureDefaults($config)
    {
        if (!isset($config['defaults'])) {
            $this->defaults = $this->getDefaultOptions();
        } else {
            $this->defaults = array_replace(
                $this->getDefaultOptions(),
                $config['defaults']
            );
        }

        // Add the default user-agent header
        if (!isset($this->defaults['headers'])) {
            $this->defaults['headers'] = [
                'User-Agent' => static::getDefaultUserAgent()
            ];
        } elseif (!Core::hasHeader($this->defaults, 'User-Agent')) {
            // Add the User-Agent header if one was not already set
            $this->defaults['headers']['User-Agent'] = static::getDefaultUserAgent();
        }
    }

    /**
     * Merges default options into the array passed by reference and returns
     * an array of headers that need to be merged in after the request is
     * created.
     *
     * @param array $options Options to modify by reference
     *
     * @return array|null
     */
    private function mergeDefaults(&$options)
    {
        // Merging optimization for when no headers are present
        if (!isset($options['headers']) || !isset($this->defaults['headers'])) {
            $options = array_replace_recursive($this->defaults, $options);
            return null;
        }

        $defaults = $this->defaults;
        unset($defaults['headers']);
        $options = array_replace_recursive($defaults, $options);

        return $this->defaults['headers'];
    }

    /**
     * @deprecated Use {@see GuzzleHttp\Pool} instead.
     * @see GuzzleHttp\Pool
     */
    public function sendAll($requests, array $options = [])
    {
        (new Pool($this, $requests, $options))->wait();
    }
}
