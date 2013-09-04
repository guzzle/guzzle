<?php

namespace Guzzle\Http;

use Guzzle\Common\Collection;
use Guzzle\Common\HasDispatcherTrait;
use Guzzle\Common\Version;
use Guzzle\Http\Adapter\AdapterInterface;
use Guzzle\Http\Adapter\FutureProxyAdapter;
use Guzzle\Http\Adapter\StreamAdapter;
use Guzzle\Http\Adapter\StreamingProxyAdapter;
use Guzzle\Http\Adapter\Curl\CurlAdapter;
use Guzzle\Http\Adapter\Transaction;
use Guzzle\Http\Event\RequestBeforeSendEvent;
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
    use HasDispatcherTrait;

    /** @var MessageFactoryInterface Request factory used by the client */
    private $messageFactory;

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
    public function __construct(array $config = [])
    {
        $this->config = new Collection($config);
        $this->userAgent = $this->getDefaultUserAgent();
        $this->baseUrl = $this->buildUrl($this->config['base_url']);
        $this->messageFactory = $this->config['message_factory'] ?: MessageFactory::getInstance();
        $this->adapter = $this->config['adapter'] ?: $this->getDefaultAdapter();
        // Add default request options
        if (!$this->config['defaults']) {
            $this->config['defaults'] = $this->getDefaultOptions();
        } else {
            $this->config['defaults'] = array_replace($this->getDefaultOptions(), $this->config['defaults']);
        }
    }

    /**
     * Get a default adapter to use based on the environment
     *
     * @return AdapterInterface
     * @throws \RuntimeException
     */
    private function getDefaultAdapter()
    {
        $batchClient = null;

        if (extension_loaded('curl')) {
            if (!ini_get('allow_url_fopen')) {
                $batchClient = $adapter = new CurlAdapter($this->messageFactory);
            } else {
                $batchClient = new CurlAdapter($this->messageFactory);
                $adapter = new StreamingProxyAdapter(
                    $batchClient,
                    new StreamAdapter($this->messageFactory)
                );
            }
        } elseif (ini_get('allow_url_fopen')) {
            $batchClient = $adapter = new StreamAdapter($this->messageFactory);
        } else {
            throw new \RuntimeException('The curl extension must be installed or you must set allow_url_fopen to true');
        }

        return new FutureProxyAdapter($adapter, $batchClient);
    }

    public function getConfig($key)
    {
        return $this->config->getPath($key);
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

    public function createRequest($method, $url = null, array $headers = [], $body = null, array $options = [])
    {
        $url = $url ? $this->buildUrl($url) : $this->getBaseUrl();

        // Merge in default options
        if ($default = $this->config->get('defaults')) {
            $options = array_replace_recursive($default, $options);
        }

        // Use a clone of the client's event dispatcher
        $options['constructor_options'] = ['event_dispatcher' => clone $this->getEventDispatcher()];
        $request = $this->messageFactory->createRequest($method, (string) $url, $headers, $body, $options);

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

    public function get($url = null, array $headers = [], $options = [])
    {
        return $this->send($this->createRequest('GET', $url, $headers, null, $options));
    }

    public function head($url = null, array $headers = [], array $options = [])
    {
        return $this->send($this->createRequest('HEAD', $url, $headers, null, $options));
    }

    public function delete($url = null, array $headers = [], array $options = [])
    {
        return $this->send($this->createRequest('DELETE', $url, $headers, null, $options));
    }

    public function put($url = null, array $headers = [], $body = null, array $options = [])
    {
        return $this->send($this->createRequest('PUT', $url, $headers, $body, $options));
    }

    public function patch($url = null, array $headers = [], $body = null, array $options = [])
    {
        return $this->send($this->createRequest('PATCH', $url, $headers, $body, $options));
    }

    public function post($url = null, array $headers = [], $body = null, array $options = [])
    {
        return $this->send($this->createRequest('POST', $url, $headers, $body, $options));
    }

    public function options($url = null, array $headers = [], array $options = [])
    {
        return $this->send($this->createRequest('OPTIONS', $url, $headers, $options));
    }

    public function send(RequestInterface $request)
    {
        $transaction = new Transaction($this, $request, $this->messageFactory);

        if (!$request->getEventDispatcher()->dispatch(
            'request.before_send',
            new RequestBeforeSendEvent($transaction)
        )->isPropagationStopped()) {
            $this->adapter->send($transaction);
        }

        $response = $transaction->getResponse();
        if (!$response->getEffectiveUrl()) {
            $response->setEffectiveUrl($request->getUrl());
        }

        return $response;
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
     * Get an array of default options to apply to the client
     *
     * @return array
     */
    protected function getDefaultOptions()
    {
        return ['allow_redirects' => true, 'exceptions' => true, 'verify' => __DIR__ . '/Resources/cacert.pem'];
    }

    /**
     * Expand a URI template
     *
     * @param string $template  Template to expand
     * @param array  $variables Variables to inject
     *
     * @return string
     */
    private function expandTemplate($template, array $variables = [])
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
                $templateVars = [];
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
