<?php

namespace Guzzle\Http;

use Guzzle\Guzzle;
use Guzzle\Common\AbstractHasDispatcher;
use Guzzle\Common\ExceptionCollection;
use Guzzle\Common\Collection;
use Guzzle\Http\Url;
use Guzzle\Http\EntityBody;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\RequestFactory;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Curl\CurlMultiInterface;
use Guzzle\Http\Curl\CurlMulti;

/**
 * HTTP client
 */
class Client extends AbstractHasDispatcher implements ClientInterface
{
    /**
     * @var string User-Agent header to apply to all requests
     */
    protected $userAgent = null;

    /**
     * @var Collection Parameter object holding configuration data
     */
    private $config;

    /**
     * @var Url Base URL of the client
     */
    private $baseUrl;

    /**
     * @var CurlMultiInterface CurlMulti object used internally
     */
    private $curlMulti;

    /**
     * {@inheritdoc}
     */
    public static function getAllEvents()
    {
        return array('client.create_request');
    }

    /**
     * Client constructor
     *
     * @param string $baseUrl (optional) Base URL of the web service
     * @param array|Collection $config (optional) Configuration settings
     */
    public function __construct($baseUrl = '', $config = null)
    {
        $this->setConfig($config ?: new Collection());
        $this->setBaseUrl($baseUrl);
    }

    /**
     * Cast to a string
     */
    public function __toString()
    {
        return spl_object_hash($this);
    }

    /**
     * Set the configuration object to use with the client
     *
     * @param array|Collection|string $config Parameters that define how the
     *      client behaves and connects to a webservice.  Pass an array or a
     *      Collection object.
     *
     * @return Client
     */
    public final function setConfig($config)
    {
        // Set the configuration object
        if ($config instanceof Collection) {
            $this->config = $config;
        } else if (is_array($config)) {
            $this->config = new Collection($config);
        } else {
            throw new \InvalidArgumentException(
                'Config must be an array or Collection'
            );
        }

        return $this;
    }

    /**
     * Get a configuration setting or all of the configuration settings
     *
     * @param bool|string $key Configuration value to retrieve.  Set to FALSE
     *      to retrieve all values of the client.  The object return can be
     *      modified, and modifications will affect the client's config.
     *
     * @return mixed|Collection
     */
    public final function getConfig($key = false)
    {
        return $key ? $this->config->get($key) : $this->config;
    }

    /**
     * Inject configuration values into a formatted string with {{param}} as a
     * parameter delimiter (replace param with the configuration value name)
     *
     * @param string $string String to inject config values into
     *
     * @return string
     */
    public final function inject($string)
    {
        return Guzzle::inject($string, $this->getConfig());
    }

    /**
     * Create and return a new {@see RequestInterface} configured for the client
     *
     * @param string $method (optional) HTTP method.  Defaults to GET
     * @param string $uri (optional) Resource URI.  Use an absolute path to
     *      override the base path of the client, or a relative path to append
     *      to the base path of the client.  The URI can contain the
     *      querystring as well.
     * @param array|Collection $headers (optional) HTTP headers
     * @param string|resource|array|EntityBody $body (optional) Entity body of
     *      request (POST/PUT) or response (GET)
     *
     * @return RequestInterface
     */
    public function createRequest($method = RequestInterface::GET, $uri = null, $headers = null, $body = null)
    {
        if (!$uri) {
            $url = $this->getBaseUrl();
        } else if (strpos($uri, 'http') === 0) {
            // Use absolute URLs as-is
            $url = $this->inject($uri);
        } else {
            $url = Url::factory($this->getBaseUrl())->combine($this->inject($uri));
        }

        return $this->prepareRequest(
            RequestFactory::create($method, (string) $url, $headers, $body)
        );
    }

    /**
     * Prepare a request to be sent from the Client by adding client specific
     * behaviors and properties to the request.
     *
     * @param RequestInterface $request Request to prepare for the client
     *
     * @return RequestInterface
     */
    public function prepareRequest(RequestInterface $request)
    {
        $request->setClient($this);

        if ($this->userAgent) {
            $request->setHeader('User-Agent', $this->userAgent);
        }

        // Add any curl options that might in the config to the request
        foreach ($this->getConfig()->getAll('/^curl\..+/', Collection::MATCH_REGEX) as $key => $value) {
            $curlOption = str_replace('curl.', '', $key);
            if (defined($curlOption)) {
                $curlValue = defined($value) ? constant($value) : $value;
                $request->getCurlOptions()->set(constant($curlOption), $curlValue);
            }
        }

        // Add the cache key filter to requests if one is set on the client
        if ($this->getConfig('cache.key_filter')) {
            $request->getParams()->set('cache.key_filter', $this->getConfig('cache.key_filter'));
        }

        // Attach client observers to the request
        $request->setEventDispatcher(clone $this->getEventDispatcher());
        $request->dispatch('event.attach', array(
            'listener' => $request
        ));

        $this->dispatch('client.create_request', array(
            'client'  => $this,
            'request' => $request
        ));

        return $request;
    }

    /**
     * Get the base service endpoint URL with configuration options injected
     * into the configuration setting.
     *
     * @param bool $inject (optional) Set to FALSE to get the raw base URL
     *
     * @return string|null
     */
    public function getBaseUrl($inject = true)
    {
        return $inject ? $this->inject($this->baseUrl) : $this->baseUrl;
    }

    /**
     * Set the base service endpoint URL
     *
     * @param string $url The base service endpoint URL of the webservice
     *
     * @return Client
     */
    public function setBaseUrl($url)
    {
        $this->baseUrl = $url;

        return $this;
    }

    /**
     * Set the name of your application and application version that will be
     * appended to the User-Agent header of all reqeusts.
     *
     * @param string $userAgent User agent string
     * @param bool $includeDefault (optional) Set to TRUE to append the default
     *    Guzzle user agent
     *
     * @return Client
     */
    public function setUserAgent($userAgent, $includeDefault = false)
    {
        $this->userAgent = $userAgent;
        if ($includeDefault) {
            $this->userAgent .= ' ' . Guzzle::getDefaultUserAgent();
        }

        return $this;
    }

    /**
     * Create a GET request for the client
     *
     * @param string $path (optional) Resource URI of the request.  Use an
     *      absolute path to override the base path, or a relative path to append
     * @param array|Collection $headers (optional) HTTP headers
     * @param string|resource|array|EntityBody $body (optional) Where to store
     *      the response entity body
     *
     * @return Request
     */
    public final function get($path = null, $headers = null, $body = null)
    {
        return $this->createRequest('GET', $path, $headers, $body);
    }

    /**
     * Create a HEAD request for the client
     *
     * @param string $uri (optional) Resource URI of the request.  Use an
     *      absolute path to override the base path, or a relative path to append
     * @param array|Collection $headers (optional) HTTP headers
     *
     * @return Request
     */
    public final function head($uri = null, $headers = null)
    {
        return $this->createRequest('HEAD', $uri, $headers);
    }

    /**
     * Create a DELETE request for the client
     *
     * @param string $uri (optional) Resource URI of the request.  Use an
     *      absolute path to override the base path, or a relative path to append
     * @param array|Collection $headers (optional) HTTP headers
     *
     * @return Request
     */
    public final function delete($uri = null, $headers = null)
    {
        return $this->createRequest('DELETE', $uri, $headers);
    }

    /**
     * Create a PUT request for the client
     *
     * @param string $uri (optional) Resource URI of the request.  Use an
     *      absolute path to override the base path, or a relative path to append
     * @param array|Collection $headers (optional) HTTP headers
     * @param string|resource|array|EntityBody $body Body to send in the request
     *
     * @return EntityEnclosingRequest
     */
    public final function put($uri = null, $headers = null, $body = null)
    {
        return $this->createRequest('PUT', $uri, $headers, $body);
    }

    /**
     * Create a POST request for the client
     *
     * @param string $uri (optional) Resource URI of the request.  Use an absolute path to
     *      override the base path, or a relative path to append it.
     * @param array|Collection $headers (optional) HTTP headers
     * @param array|Collection|string|EntityBody $postBody (optional) POST
     *      body.  Can be a string, EntityBody, or associative array of POST
     *      fields to send in the body of the request.  Prefix a value in the
     *      array with the @ symbol reference a file.
     *
     * @return EntityEnclosingRequest
     */
    public final function post($uri = null, $headers = null, $postBody = null)
    {
        return $this->createRequest('POST', $uri, $headers, $postBody);
    }

    /**
     * Create an OPTIONS request for the client
     *
     * @param string $uri (optional) Resource URI of the request.  Use an
     *      absolute path to override the base path, or relative path to append
     *
     * @return Request
     */
    public final function options($uri = null)
    {
        return $this->createRequest('OPTIONS', $uri);
    }

    /**
     * Sends a single request or an array of requests in parallel
     *
     * @param array $requests Request(s) to send
     *
     * @return array Returns the response(s)
     */
    public function send($requests)
    {
        $curlMulti = $this->getCurlMulti();
        $multipleRequests = is_array($requests);
        $requests = $multipleRequests ? $requests : array($requests);

        foreach ($requests as $request) {
            $curlMulti->add($request);
        }

        try {
            $curlMulti->send();
        } catch (ExceptionCollection $e) {
            throw $multipleRequests ? $e : $e->getIterator()->offsetGet(0);
        }

        return !$multipleRequests
            ? end($requests)->getResponse()
            : array_map(function($request) {
                return $request->getResponse();
            }, $requests);
    }

    /**
     * Set a curl multi object to be used internally by the client for
     * transferring requests.
     *
     * @param CurlMultiInterface $curlMulti Mulit object
     *
     * @return Client
     */
    public function setCurlMulti(CurlMultiInterface $curlMulti)
    {
        $this->curlMulti = $curlMulti;

        return $this;
    }

    /**
     * Get the curl multi object used with the client
     *
     * @return CurlMultiInterface
     */
    public function getCurlMulti()
    {
        if (!$this->curlMulti) {
            $this->curlMulti = CurlMulti::getInstance();
        }

        return $this->curlMulti;
    }
}