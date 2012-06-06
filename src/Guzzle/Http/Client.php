<?php

namespace Guzzle\Http;

use Guzzle\Common\Collection;
use Guzzle\Common\AbstractHasDispatcher;
use Guzzle\Common\Exception\ExceptionCollection;
use Guzzle\Common\Exception\InvalidArgumentException;
use Guzzle\Http\Utils;
use Guzzle\Http\Url;
use Guzzle\Http\EntityBody;
use Guzzle\Http\Parser\ParserRegistry;
use Guzzle\Http\Parser\UriTemplate\UriTemplateInterface;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\RequestFactory;
use Guzzle\Http\Message\RequestFactoryInterface;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Curl\CurlMultiInterface;
use Guzzle\Http\Curl\CurlMulti;

/**
 * HTTP client
 */
class Client extends AbstractHasDispatcher implements ClientInterface
{
    /**
     * @var Collection Default HTTP headers to set on each request
     */
    protected $defaultHeaders;

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
     * @var UriTemplate URI template owned by the client
     */
    private $uriTemplate;

    /**
     * @var RequestFactoryInterface Request factory used by the client
     */
    protected $requestFactory;

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
     * @param string           $baseUrl Base URL of the web service
     * @param array|Collection $config  Configuration settings
     */
    public function __construct($baseUrl = '', $config = null)
    {
        $this->setConfig($config ?: new Collection());
        $this->setBaseUrl($baseUrl);
        $this->defaultHeaders = new Collection();
        $this->setRequestFactory(RequestFactory::getInstance());
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
     * @param array|Collection|string $config Parameters that define how the client
     *                                        behaves and connects to a webservice.
     *                                        Pass an array or a Collection object.
     *
     * @return Client
     */
    public final function setConfig($config)
    {
        // Set the configuration object
        if ($config instanceof Collection) {
            $this->config = $config;
        } elseif (is_array($config)) {
            $this->config = new Collection($config);
        } else {
            throw new InvalidArgumentException(
                'Config must be an array or Collection'
            );
        }

        return $this;
    }

    /**
     * Get a configuration setting or all of the configuration settings
     *
     * @param bool|string $key Configuration value to retrieve.  Set to FALSE
     *                         to retrieve all values of the client.  The
     *                         object return can be modified, and modifications
     *                         will affect the client's config.
     *
     * @return mixed|Collection
     */
    public final function getConfig($key = false)
    {
        return $key ? $this->config->get($key) : $this->config;
    }

    /**
     * Get the default HTTP headers to add to each request created by the client
     *
     * @return Collection
     */
    public function getDefaultHeaders()
    {
        return $this->defaultHeaders;
    }

    /**
     * Set the default HTTP headers to add to each request created by the client
     *
     * @param array|Collection $headers Default HTTP headers
     *
     * @return Client
     */
    public function setDefaultHeaders($headers)
    {
        if ($headers instanceof Collection) {
            $this->defaultHeaders = $headers;
        } elseif (is_array($headers)) {
            $this->defaultHeaders = new Collection($headers);
        } else {
            throw new InvalidArgumentException('Headers must be an array or Collection');
        }

        return $this;
    }

    /**
     * Expand a URI template using client configuration data
     *
     * @param string $template  URI template to expand
     * @param array  $variables Additional variables to use in the expansion
     *
     * @return string
     */
    public function expandTemplate($template, array $variables = null)
    {
        $expansionVars = $this->getConfig()->getAll();
        if ($variables) {
            $expansionVars = array_merge($expansionVars, $variables);
        }

        return $this->getUriTemplate()->expand($template, $expansionVars);
    }

    /**
     * Set the URI template expander to use with the client
     *
     * @param UriTemplateInterface $uriTemplate URI template expander
     *
     * @return Client
     */
    public function setUriTemplate(UriTemplateInterface $uriTemplate)
    {
        $this->uriTemplate = $uriTemplate;

        return $this;
    }

    /**
     * Get the URI template expander used by the client.  A default UriTemplate
     * object will be created if one does not exist.
     *
     * @return UriTemplateInterface
     */
    public function getUriTemplate()
    {
        if (!$this->uriTemplate) {
            $this->uriTemplate = ParserRegistry::get('uri_template');
        }

        return $this->uriTemplate;
    }

    /**
     * Create and return a new {@see RequestInterface} configured for the client.
     *
     * Use an absolute path to override the base path of the client, or a
     * relative path to append to the base path of the client.  The URI can
     * contain the query string as well.  Use an array to provide a URI
     * template and additional variables to use in the URI template expansion.
     *
     * @param string                           $method  HTTP method.  Defaults to GET
     * @param string|array                     $uri     Resource URI.
     * @param array|Collection                 $headers HTTP headers
     * @param string|resource|array|EntityBody $body    Entity body of request (POST/PUT) or response (GET)
     *
     * @return RequestInterface
     * @throws InvalidArgumentException if a URI array is passed that does not
     *                                  contain exactly two elements: the URI
     *                                  followed by template variables
     */
    public function createRequest($method = RequestInterface::GET, $uri = null, $headers = null, $body = null)
    {
        if (!is_array($uri)) {
            $templateVars = null;
        } else {
            if (count($uri) != 2 || !is_array($uri[1])) {
                throw new InvalidArgumentException(
                    'You must provide a URI template followed by an array of template variables '
                    . 'when using an array for a URI template'
                );
            }
            list($uri, $templateVars) = $uri;
        }

        if (!$uri) {
            $url = $this->getBaseUrl();
        } elseif (strpos($uri, 'http') === 0) {
            // Use absolute URLs as-is
            $url = $this->expandTemplate($uri, $templateVars);
        } else {
            $url = Url::factory($this->getBaseUrl())->combine($this->expandTemplate($uri, $templateVars));
        }

        // If default headers are provided, then merge them into exising headers
        // If a collision occurs, the header is completely replaced
        if (count($this->defaultHeaders)) {
            if ($headers instanceof Collection) {
                $headers = array_merge($this->defaultHeaders->getAll(), $headers->getAll());
            } elseif (is_array($headers)) {
                 $headers = array_merge($this->defaultHeaders->getAll(), $headers);
            } elseif ($headers === null) {
                $headers = $this->defaultHeaders;
            }
        }

        return $this->prepareRequest(
            $this->requestFactory->create($method, (string) $url, $headers, $body)
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
    protected function prepareRequest(RequestInterface $request)
    {
        $request->setClient($this);

        foreach ($this->getConfig()->getAll() as $key => $value) {
            if ($key == 'curl.blacklist') {
                continue;
            }
            // Add any curl options that might in the config to the request
            if (strpos($key, 'curl.') === 0) {
                $curlOption = substr($key, 5);
                // Convert constants represented as string to constant int values
                if (defined($curlOption)) {
                    $value = is_string($value) && defined($value) ? constant($value) : $value;
                    $curlOption = constant($curlOption);
                }
                $request->getCurlOptions()->set($curlOption, $value);
            } elseif (strpos($key, 'params.') === 0) {
                // Add request specific parameters to all requests (prefix with 'params.')
                $request->getParams()->set(substr($key, 7), $value);
            }
        }

        // Attach client observers to the request
        $request->setEventDispatcher(clone $this->getEventDispatcher());

        $this->dispatch('client.create_request', array(
            'client'  => $this,
            'request' => $request
        ));

        return $request;
    }

    /**
     * Get the client's base URL as either an expanded or raw URI template
     *
     * @param bool $expand Set to FALSE to get the raw base URL without URI
     *                     template expansion
     *
     * @return string|null
     */
    public function getBaseUrl($expand = true)
    {
        return $expand ? $this->expandTemplate($this->baseUrl) : $this->baseUrl;
    }

    /**
     * Set the base URL of the client
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
     * appended to the User-Agent header of all requests.
     *
     * @param string $userAgent      User agent string
     * @param bool   $includeDefault Set to TRUE to append the default Guzzle use agent
     *
     * @return Client
     */
    public function setUserAgent($userAgent, $includeDefault = false)
    {
        if ($includeDefault) {
            $userAgent .= ' ' . Utils::getDefaultUserAgent();
        }
        $this->defaultHeaders->set('User-Agent', $userAgent);

        return $this;
    }

    /**
     * Create a GET request for the client
     *
     * @param string|array                     $uri     Resource URI
     * @param array|Collection                 $headers HTTP headers
     * @param string|resource|array|EntityBody $body    Where to store the response entity body
     *
     * @return Request
     * @see    Guzzle\Http\Client::createRequest()
     */
    public function get($uri = null, $headers = null, $body = null)
    {
        return $this->createRequest('GET', $uri, $headers, $body);
    }

    /**
     * Create a HEAD request for the client
     *
     * @param string|array     $uri     Resource URI
     * @param array|Collection $headers HTTP headers
     *
     * @return Request
     * @see    Guzzle\Http\Client::createRequest()
     */
    public function head($uri = null, $headers = null)
    {
        return $this->createRequest('HEAD', $uri, $headers);
    }

    /**
     * Create a DELETE request for the client
     *
     * @param string|array     $uri     Resource URI
     * @param array|Collection $headers HTTP headers
     *
     * @return Request
     * @see    Guzzle\Http\Client::createRequest()
     */
    public function delete($uri = null, $headers = null)
    {
        return $this->createRequest('DELETE', $uri, $headers);
    }

    /**
     * Create a PUT request for the client
     *
     * @param string|array               $uri     Resource URI
     * @param array|Collection           $headers HTTP headers
     * @param string|resource|EntityBody $body    Body to send in the request
     *
     * @return EntityEnclosingRequest
     * @see    Guzzle\Http\Client::createRequest()
     */
    public function put($uri = null, $headers = null, $body = null)
    {
        return $this->createRequest('PUT', $uri, $headers, $body);
    }

    /**
     * Create a PATCH request for the client
     *
     * @param string|array               $uri     Resource URI
     * @param array|Collection           $headers HTTP headers
     * @param string|resource|EntityBody $body    Body to send in the request
     *
     * @return EntityEnclosingRequest
     * @see    Guzzle\Http\Client::createRequest()
     */
    public function patch($uri = null, $headers = null, $body = null)
    {
        return $this->createRequest('PATCH', $uri, $headers, $body);
    }

    /**
     * Create a POST request for the client
     *
     * @param string|array                       $uri      Resource URI
     * @param array|Collection                   $headers  HTTP headers
     * @param array|Collection|string|EntityBody $postBody POST body. Can be a string, EntityBody,
     *                                                     or associative array of POST fields to
     *                                                     send in the body of the request.  Prefix
     *                                                     a value in the array with the @ symbol
     *                                                     reference a file.
     *
     * @return EntityEnclosingRequest
     * @see    Guzzle\Http\Client::createRequest()
     */
    public function post($uri = null, $headers = null, $postBody = null)
    {
        return $this->createRequest('POST', $uri, $headers, $postBody);
    }

    /**
     * Create an OPTIONS request for the client
     *
     * @param string|array $uri Resource URI
     *
     * @return Request
     * @see    Guzzle\Http\Client::createRequest()
     */
    public function options($uri = null)
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
        $multipleRequests = !($requests instanceof RequestInterface);
        if (!$multipleRequests) {
            $requests = array($requests);
        }

        foreach ($requests as $request) {
            $curlMulti->add($request);
        }

        try {
            $curlMulti->send();
        } catch (ExceptionCollection $e) {
            throw $multipleRequests ? $e : $e->getIterator()->offsetGet(0);
        }

        if (!$multipleRequests) {
            return end($requests)->getResponse();
        }

        return array_map(function($request) {
            return $request->getResponse();
        }, $requests);
    }

    /**
     * Set a curl multi object to be used internally by the client for
     * transferring requests.
     *
     * @param CurlMultiInterface $curlMulti multi object
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

    /**
     * Set the request factory to use with the client when creating requests
     *
     * @param RequestFactoryInterface $factory Request factory
     *
     * @return Client
     */
    public function setRequestFactory(RequestFactoryInterface $factory)
    {
        $this->requestFactory = $factory;

        return $this;
    }
}
