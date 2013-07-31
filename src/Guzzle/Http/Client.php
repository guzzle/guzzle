<?php

namespace Guzzle\Http;

use Guzzle\Common\Collection;
use Guzzle\Common\HasDispatcher;
use Guzzle\Common\Version;
use Guzzle\Http\Adapter\AdapterInterface;
use Guzzle\Http\Adapter\StreamAdapter;
use Guzzle\Http\Adapter\StreamingProxyAdapter;
use Guzzle\Http\Adapter\Curl\CurlAdapter;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Url\Url;
use Guzzle\Url\UriTemplate;
use Guzzle\Http\Message\MessageFactory;
use Guzzle\Http\Message\MessageFactoryInterface;

/**
 * HTTP client
 */
class Client implements ClientInterface
{
    use HasDispatcher;

    const REQUEST_OPTIONS = 'request.options';

    /** @var string The user agent string to set on each request */
    protected $userAgent;

    /** @var Collection Parameter object holding configuration data */
    private $config;

    /** @var string Base URL of the client */
    private $baseUrl;

    /** @var AdapterInterface */
    private $adapter;

    /** @var MessageFactoryInterface Request factory used by the client */
    protected $messageFactory;

    /**
     * @param array $config Client configuration settings
     *                      - base_url: Base URL of the client that is merged into relative URLs. Can be a string or
     *                      -           an array that contains a URI template followed by an associative array of
     *                                  expansion variables to inject into the URI template.
     *                      - message_factory: Factory used to create request and response object
     *                      - adapter: Adapter used to transfer requests
     */
    public function __construct(array $config = array())
    {
        $this->config = new Collection($config);
        $this->userAgent = $this->getDefaultUserAgent();
        $this->baseUrl = $this->buildUrl($this->config['base_url']);
        $this->messageFactory = $config['message_factory'] ?: MessageFactory::getInstance();
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
                    new CurlAdapter($messageFactory),
                    new StreamAdapter($messageFactory)
                )
                : new CurlAdapter($messageFactory);
        } elseif (ini_get('allow_url_fopen')) {
            return new StreamAdapter($messageFactory);
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
        $this->config->setPath(self::REQUEST_OPTIONS . '/' . $keyOrPath, $value);

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
        return $this->config->getPath(self::REQUEST_OPTIONS . '/' . $keyOrPath);
    }

    public function createRequest($method, $url = null, $body = null, array $options = array())
    {
        $url = $url ? $this->buildUrl($url) : $this->getBaseUrl();

        // Merge in default options
        if ($default = $this->config->get(self::REQUEST_OPTIONS)) {
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

    public function get($uri = null, $options = array())
    {
        return $this->send($this->createRequest('GET', $uri, null, $options));
    }

    public function head($uri = null, array $options = array())
    {
        return $this->send($this->createRequest('HEAD', $uri, null, $options));
    }

    public function delete($uri = null, array $options = array())
    {
        return $this->send($this->createRequest('DELETE', $uri, null, $options));
    }

    public function put($uri = null, $body = null, array $options = array())
    {
        return $this->send($this->createRequest('PUT', $uri, $body, $options));
    }

    public function patch($uri = null, $body = null, array $options = array())
    {
        return $this->send($this->createRequest('PATCH', $uri, $body, $options));
    }

    public function post($uri = null, $body = null, array $options = array())
    {
        return $this->send($this->createRequest('POST', $uri, $body, $options));
    }

    public function options($uri = null, array $options = array())
    {
        return $this->send($this->createRequest('OPTIONS', $uri, $options));
    }

    public function send(RequestInterface $request)
    {

    }

    public function batch($requests)
    {

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
     * Expand a URI template
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
                $templateVars = null;
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
