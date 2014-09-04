<?php
namespace GuzzleHttp;

use GuzzleHttp\Event\ErrorEvent;
use GuzzleHttp\Event\HasEmitterTrait;
use GuzzleHttp\Event\RequestEvents;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Message\MessageFactory;
use GuzzleHttp\Message\MessageFactoryInterface;
use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Message\FutureResponse;
use GuzzleHttp\Ring\Future;
use GuzzleHttp\Ring\Client\Middleware;
use GuzzleHttp\Ring\Client\CurlMultiAdapter;
use GuzzleHttp\Ring\Client\CurlAdapter;
use GuzzleHttp\Ring\Client\StreamAdapter;

/**
 * HTTP client
 */
class Client implements ClientInterface
{
    use HasEmitterTrait;

    const DEFAULT_CONCURRENCY = 50;

    /** @var MessageFactoryInterface Request factory used by the client */
    private $messageFactory;

    /** @var callable */
    private $adapter;

    /** @var Url Base URL of the client */
    private $baseUrl;

    /** @var array Default request options */
    private $defaults;

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
     *     - adapter: callable adapter used to transfer requests
     *     - message_factory: Factory used to create request and response object
     *     - defaults: Default request options to apply to each request
     *     - emitter: Event emitter used for request events
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
        $this->adapter = isset($config['adapter'])
            ? $config['adapter']
            : self::getDefaultAdapter();
    }

    /**
     * Create a default adapter to use based on the environment
     *
     * @throws \RuntimeException if no viable adapter is available.
     */
    public static function getDefaultAdapter()
    {
        $default = $future = $streaming = null;

        if (extension_loaded('curl')) {
            $future = new CurlMultiAdapter([
                'max_handles' => isset($_SERVER['GUZZLE_CURL_MAX_HANDLES'])
                        ? $_SERVER['GUZZLE_CURL_MAX_HANDLES']
                        : self::DEFAULT_CONCURRENCY,
                'select_timepout' => isset($_SERVER['GUZZLE_CURL_SELECT_TIMEOUT'])
                        ? $_SERVER['GUZZLE_CURL_SELECT_TIMEOUT']
                        : 1
            ]);
            if (function_exists('curl_reset')) {
                $default = new CurlAdapter();
            }
        }

        if (ini_get('allow_url_fopen')) {
            $streaming = new StreamAdapter();
        }

        if (!($default = ($default ?: $future) ?: $streaming)) {
            throw new \RuntimeException('Guzzle requires cURL, the '
                . 'allow_url_fopen ini setting, or a custom HTTP adapter.');
        }

        $handler = $default;

        if ($streaming && $streaming !== $default) {
            $handler = Middleware::wrapStreaming($default, $streaming);
        }

        if ($future && $default !== $future) {
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
        try {
            return $this->sendTransaction(new Transaction($this, $request));
        } catch (RequestException $e) {
            throw $e;
        } catch (\Exception $e) {
            // Wrap exceptions in a RequestException to adhere to the interface
            throw new RequestException($e->getMessage(), $request, null, $e);
        }
    }

    private function sendTransaction(Transaction $trans)
    {
        RequestEvents::emitBefore($trans);
        if ($trans->response) {
            return $trans->response;
        }

        // Send the request using the Guzzle ring handler
        $adapter = $this->adapter;
        $response = $adapter(
            RequestEvents::createRingRequest($trans, $this->messageFactory)
        );

        if ($response instanceof Future) {
            return new FutureResponse(function () use ($response, $trans) {
                $response->deref();
                return $trans;
            });
        } elseif ($trans->response) {
            return $trans->response;
        }

        throw new \RuntimeException('No response was associated with the '
            . 'transaction! This means the ring adapter did something '
            . 'wrong and never completed the transaction.');
    }

    public function sendAll($requests, array $options = [])
    {
        if (!($requests instanceof TransactionIterator)) {
            $requests = new TransactionIterator($requests, $this, $options);
        }

        $stopErrors = function (ErrorEvent $e) { $e->stopPropagation(); };
        $lastFuture = $counter = null;
        $concurrency = isset($options['parallel'])
            ? $options['parallel']
            : self::DEFAULT_CONCURRENCY;

        foreach ($requests as $trans) {
            $trans->request->getConfig()->set('future', true);
            $trans->request->getEmitter()->on('error', $stopErrors, 'last');
            $response = $this->sendTransaction($trans);
            if ($response instanceof FutureResponse) {
                $lastFuture = $response;
                if (++$counter == $concurrency) {
                    $response->wait();
                    $counter = $lastFuture = null;
                }
            }
        }

        // Be sure to wait on the last few responses that may have sent.
        if ($lastFuture) {
            $lastFuture->wait();
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
        if (isset($_SERVER['HTTP_PROXY'])) {
            $settings['proxy']['http'] = $_SERVER['HTTP_PROXY'];
        }

        if (isset($_SERVER['HTTPS_PROXY'])) {
            $settings['proxy']['https'] = $_SERVER['HTTPS_PROXY'];
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
        if (!is_array($url)) {
            if (strpos($url, '://')) {
                return (string) $url;
            }
            return (string) $this->baseUrl->combine($url);
        } elseif (strpos($url[0], '://')) {
            return Utils::uriTemplate($url[0], $url[1]);
        }

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
        } elseif (!isset(array_change_key_case($this->defaults['headers'])['user-agent'])) {
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
        if (!isset($options['headers'])
            || !isset($this->defaults['headers'])
        ) {
            $options = array_replace_recursive($this->defaults, $options);
            return null;
        }

        $defaults = $this->defaults;
        unset($defaults['headers']);
        $options = array_replace_recursive($defaults, $options);

        return $this->defaults['headers'];
    }
}
