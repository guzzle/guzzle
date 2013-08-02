<?php

namespace Guzzle\Http;

use Guzzle\Common\Collection;
use Guzzle\Common\HasDispatcher;
use Guzzle\Common\Version;
use Guzzle\Http\Adapter\AdapterInterface;
use Guzzle\Http\Adapter\StreamAdapter;
use Guzzle\Http\Adapter\StreamingProxyAdapter;
use Guzzle\Http\Adapter\Curl\CurlAdapter;
use Guzzle\Http\Adapter\Transaction;
use Guzzle\Http\Event\BeforeSendEvent;
use Guzzle\Http\Exception\BatchException;
use Guzzle\Http\Message\MessageFactory;
use Guzzle\Http\Message\MessageFactoryInterface;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Url\Url;
use Guzzle\Url\UriTemplate;

/**
 * HTTP client
 */
class Client implements ClientInterface
{
    use HasDispatcher;

    /** @var MessageFactoryInterface Request factory used by the client */
    protected $messageFactory;

    /** @var AdapterInterface */
    private $adapter;

    /** @var string Base URL of the client */
    private $baseUrl;

    /** @var Collection Parameter object holding configuration data */
    private $config;

    /** @var string The user agent string to set on each request */
    private $userAgent;

    /**
     * @param array $config Client configuration settings
     *                      - base_url: Base URL of the client that is merged into relative URLs. Can be a string or
     *                      -           an array that contains a URI template followed by an associative array of
     *                                  expansion variables to inject into the URI template.
     *                      - message_factory: Factory used to create request and response object
     *                      - adapter: Adapter used to transfer requests
     *                      - defaults: Default request options to apply to each request
     */
    public function __construct(array $config = array())
    {
        $this->config = new Collection($config);
        $this->userAgent = $this->getDefaultUserAgent();
        $this->baseUrl = $this->buildUrl($this->config['base_url']);
        $this->messageFactory = $this->config['message_factory'] ?: MessageFactory::getInstance();
        $this->adapter = $this->config['adapter'] ?: self::getDefaultAdapter($this->messageFactory);
    }

    /**
     * Get a default adapter to use based on the environment
     *
     * @param MessageFactoryInterface $messageFactory Message factory used by the adapter
     *
     * @return AdapterInterface
     * @throws \RuntimeException
     */
    public static function getDefaultAdapter(MessageFactoryInterface $messageFactory)
    {
        if (extension_loaded('curl')) {
            return ini_get('allow_url_fopen')
                ? new StreamingProxyAdapter(
                    new CurlAdapter(),
                    new StreamAdapter()
                )
                : new CurlAdapter();
        } elseif (ini_get('allow_url_fopen')) {
            return new StreamAdapter();
        } else {
            throw new \RuntimeException('The curl extension must be installed or you must set allow_url_fopen to true');
        }
    }

    public function getConfig($key)
    {
        return $this->config->getPath($key);
    }

    /**
     * Set a default request option on the client that will be used as a default for each request
     *
     * @param string $keyOrPath request.options key (e.g. allow_redirects) or path to a nested key (e.g. headers/foo)
     * @param mixed  $value     Value to set
     *
     * @return $this
     */
    public function setDefaultOption($keyOrPath, $value)
    {
        $this->config->setPath("defaults/{$keyOrPath}", $value);

        return $this;
    }

    /**
     * Retrieve a default request option from the client
     *
     * @param string $keyOrPath request.options key (e.g. allow_redirects) or path to a nested key (e.g. headers/foo)
     *
     * @return mixed|null
     */
    public function getDefaultOption($keyOrPath)
    {
        return $this->config->getPath("defaults/{$keyOrPath}");
    }

    public function createRequest($method, $url = null, array $headers = [], $body = null, array $options = array())
    {
        $url = $url ? $this->buildUrl($url) : $this->getBaseUrl();

        // Merge in default options
        if ($default = $this->config->get('defaults')) {
            $options = array_replace_recursive($default, $options);
        }

        $request = $this->messageFactory->createRequest($method, (string) $url, $body, $options);
        $request->setEventDispatcher(clone $this->getEventDispatcher());
        if ($this->userAgent && !$request->hasHeader('User-Agent')) {
            $request->setHeader('User-Agent', $this->userAgent);
        }

        return $request;
    }

    public function getBaseUrl()
    {
        return $this->baseUrl;
    }

    public function setUserAgent($userAgent, $includeDefault = false)
    {
        if ($includeDefault) {
            $userAgent .= ' ' . $this->getDefaultUserAgent();
        }
        $this->userAgent = $userAgent;

        return $this;
    }

    public function get($url = null, array $headers = [], $options = array())
    {
        return $this->send($this->createRequest('GET', $url, $headers, null, $options));
    }

    public function head($url = null, array $headers = [], array $options = array())
    {
        return $this->send($this->createRequest('HEAD', $url, $headers, null, $options));
    }

    public function delete($url = null, array $headers = [], array $options = array())
    {
        return $this->send($this->createRequest('DELETE', $url, $headers, null, $options));
    }

    public function put($url = null, array $headers = [], $body = null, array $options = array())
    {
        return $this->send($this->createRequest('PUT', $url, $headers, $body, $options));
    }

    public function patch($url = null, array $headers = [], $body = null, array $options = array())
    {
        return $this->send($this->createRequest('PATCH', $url, $headers, $body, $options));
    }

    public function post($url = null, array $headers = [], $body = null, array $options = array())
    {
        return $this->send($this->createRequest('POST', $url, $headers, $body, $options));
    }

    public function options($url = null, array $headers = [], array $options = array())
    {
        return $this->send($this->createRequest('OPTIONS', $url, $headers, $options));
    }

    public function send(RequestInterface $request)
    {
        $transaction = new Transaction($this);
        if (!$this->preSend($request, $transaction)->isPropagationStopped()) {
            $transaction[$request] = $this->messageFactory->createResponse();
            $this->adapter->send($transaction);
        }

        if ($transaction[$request] instanceof \Exception) {
            throw $transaction[$request];
        }

        return $transaction[$request];
    }

    public function batch(array $requests)
    {
        $transaction = new Transaction($this);
        $intercepted = new Transaction($this);

        foreach ($requests as $request) {
            if ($this->preSend($request, $transaction)->isPropagationStopped()) {
                $intercepted[$request] = $transaction[$request];
                unset($transaction[$request]);
            } else {
                $transaction[$request] = $this->messageFactory->createResponse();
            }
        }

        if (count($transaction)) {
            $this->adapter->send($transaction);
        }

        $transaction->addAll($intercepted);

        if ($transaction->hasExceptions()) {
            throw new BatchException($transaction, $this);
        }

        return $transaction;
    }

    /**
     * Get the default User-Agent string to use with Guzzle
     *
     * @return string
     */
    protected function getDefaultUserAgent()
    {
        return 'Guzzle/' . Version::VERSION . ' curl/' . curl_version()['version'] . ' PHP/' . PHP_VERSION;
    }

    /**
     * @param RequestInterface $request Request about to be sent
     * @param Transaction      $transaction Transaction
     *
     * @return BeforeSendEvent
     */
    private function preSend(RequestInterface $request, Transaction $transaction)
    {
        return $request->getEventDispatcher()->dispatch(
            'request.before_send',
            new BeforeSendEvent($request, $transaction)
        );
    }

    /**
     * Expand a URI template
     *
     * @param string $template  Template to expand
     * @param array  $variables Variables to inject
     *
     * @return string
     */
    private function expandTemplate($template, array $variables = array())
    {
        return function_exists('uri_template')
            ? uri_template($template, $variables)
            : UriTemplate::getInstance()->expand($template, $variables);
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
        if ($url) {
            if (is_array($url)) {
                list($url, $templateVars) = $url;
            } else {
                $templateVars = array();
            }
            if (substr($url, 0, 4) === 'http') {
                // Use absolute URLs as-is
                $url = $this->expandTemplate($url, $templateVars);
            } else {
                $url = Url::fromString($this->getBaseUrl())->combine($this->expandTemplate($url, $templateVars));
            }
        }

        return (string) $url;
    }
}
