<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Http\Message;

use Guzzle\Guzzle;
use Guzzle\Common\Collection;
use Guzzle\Common\Filter\Chain;
use Guzzle\Common\Subject\SubjectMediator;
use Guzzle\Common\Subject\Observer;
use Guzzle\Http\Curl\CurlFactoryInterface;
use Guzzle\Http\Curl\CurlFactory;
use Guzzle\Http\Curl\CurlHandle;
use Guzzle\Http\Curl\CurlException;
use Guzzle\Http\Plugin\AbstractPlugin;
use Guzzle\Http\QueryString;
use Guzzle\Http\Cookie;
use Guzzle\Http\EntityBody;
use Guzzle\Http\Transaction\Transaction;
use Guzzle\Http\RequestManager;
use Guzzle\Http\Transaction\TransactionOptions;
use Guzzle\Http\RequestManagerException;
use Guzzle\Http\Url;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class Request extends AbstractMessage implements RequestInterface
{
    /**
     * @var string Protocol version of the request (1.0, 1.1, etc)
     */
    protected $protocolVersion = '1.1';

    /**
     * @var Url HTTP Url
     */
    protected $url;

    /**
     * @var string HTTP method (GET, PUT, POST, DELETE, HEAD, OPTIONS, TRACE)
     */
    protected $method;

    /**
     * @var SubjectMediator Subject mediator
     */
    protected $subjectMediator;

    /**
     * @var Response Response of the request
     */
    protected $response;

    /**
     * @var EntityBody Response body
     */
    protected $responseBody;

    /**
     * @var bool Has the response been sent through the process chain
     */
    protected $processedResponse = false;

    /**
     * @var Chain Chain of intercepting filters to process the request before sending
     */
    protected $prepareChain;

    /**
     * @var Chain Chain of intercepting filters to process the request after sending
     */
    protected $processChain;

    /**
     * @var string State of the request object
     */
    protected $state;

    /**
     * @var Cookie Cookies to send with the request
     */
    protected $cookie;

    /**
     * @param string Auth username
     */
    protected $username;

    /**
     * @param string Auth password
     */
    protected $password;

    /**
     * @param ResponseProcessorInterface Object used to process the response
     */
    protected $responseProcessor;

    /**
     * @var Collection cURL specific transfer options
     */
    protected $curlOptions;

    /**
     * @var CurlFactory Curl factory object used to create cURL handles
     */
    protected $curlFactory;

    /**
     * @var CurlHandle cURL handle associated with the request
     */
    protected $curlHandle;

    /**
     * @param DefaultResponseProcessor
     */
    protected static $defaultResponseProcessor;

    /**
     * Create a new request
     *
     * @param string $method HTTP method
     * @param string|Url $url HTTP URL to connect to.  The URI scheme, host
     *      header, and URI are parsed from the full URL.  If query string
     *      parameters are present they will be parsed as well.
     * @param array|Collection $headers (optional) HTTP headers
     * @param CurlFactoryInterface $curlFactory (optional) Curl factory object
     */
    public function __construct($method, $url, $headers = array(), CurlFactoryInterface $curlFactory = null)
    {
        $this->method = strtoupper($method);
        if (is_array($headers)) {
            $this->headers = new Collection($headers);
        } else if ($headers instanceof Collection) {
            $this->headers = $headers;
        } else {
            $this->headers = new Collection();
        }

        $this->curlFactory = $curlFactory ?: CurlFactory::getInstance();
        $this->curlOptions = new Collection();
        $this->cookie = Cookie::factory($this->getHeader('Cookie'));
        $this->subjectMediator = new SubjectMediator($this);

        if (!$this->hasHeader('User-Agent', true)) {
            $this->setHeader('User-Agent', Guzzle::getDefaultUserAgent());
        }
        
        $this->parseCacheControlDirective();

        $this->setState(self::STATE_NEW);
        $this->setUrl($url);
    }

    /**
     * Release curl handle
     *
     * @codeCoverageIgnore
     */
    public function __destruct()
    {
        $this->releaseCurlHandle();
    }

    /**
     * Clone the request object, leaving off any response that was received
     */
    public function __clone()
    {
        // Clone object properties
        $this->prepareChain = $this->prepareChain ? clone $this->prepareChain : null;
        $this->processChain = $this->processChain ? clone $this->processChain : null;
        $this->subjectMediator = clone $this->subjectMediator;

        // Reattach any plugins
        foreach ($this->subjectMediator->getAttached() as $observer) {
            if ($observer instanceof AbstractPlugin) {
                $observer->attach($this);
            }
        }

        $this->curlOptions = clone $this->curlOptions;
        $this->headers = clone $this->headers;
        $this->params = clone $this->params;
        $this->url = clone $this->url;
        $this->curlHandle = null;

        // Reset response properties
        $this->params->set('queued_response', false);
        $this->response = null;
        $this->responseBody = null;
        $this->processedResponse = $this->preparedRequest = false;

        $this->setState(RequestInterface::STATE_NEW);
    }

    /**
     * Get the HTTP request as a string
     *
     * @return string
     */
    public function __toString()
    {
        return $this->getRawHeaders() . "\r\n\r\n";
    }

    /**
     * Get the raw message headers as a string
     *
     * @return string
     */
    public function getRawHeaders()
    {
        $raw = $this->method . ' ' . $this->getResourceUri();
        $protocolVersion = $this->protocolVersion ?: '1.1';
        $raw = trim($raw) . ' ' . strtoupper(str_replace('https', 'http', $this->url->getScheme())) . '/' . $protocolVersion . "\r\n";

        foreach ($this->headers as $key => $value) {
            $raw .= $key . ': ' . $value . "\r\n";
        }

        return rtrim($raw, "\r\n");
    }

    /**
     * Set the URL of the request
     *
     * Warning: Calling this method will modify headers, rewrite the  query
     * string object, and set other data associated with the request.
     *
     * @param string|Url $url Full URL to set including query string
     *
     * @return Request
     */
    public function setUrl($url)
    {
        if (is_string($url)) {
            $this->url = Url::factory($url);
        } else if ($url instanceof Url) {
            $this->url = $url;
        } else {
            throw new \InvalidArgumentException('Invalid URL sent to ' . __METHOD__);
        }

        $this->setHost($this->url->getHost());
        $this->setPort($this->url->getPort());
        if ($this->url->getUsername() && $this->url->getPassword()) {
            $this->setAuth($this->url->getUsername(), $this->url->getPassword());
        }

        // Remove the auth info from the URL
        $this->url->setUsername(null);
        $this->url->setPassword(null);

        return $this;
    }

    /**
     * Get the SubjectMediator of the request
     *
     * @return SubjectMediator
     */
    public function getSubjectMediator()
    {
        return $this->subjectMediator;
    }

    /**
     * Send the request
     *
     * @return Response
     * @throws RequestException on a request error
     */
    public function send()
    {
        if ($this->state == self::STATE_NEW) {
            try {
                try {
                    $this->state = self::STATE_TRANSFER;
                    $this->getSubjectMediator()->notify('request.before_send');
                    $this->getPrepareChain()->process($this);
                    if (!$this->response && !$this->getParams()->get('queued_response')) {
                        curl_exec($this->getCurlHandle()->getHandle());
                    }
                    $this->setState(self::STATE_COMPLETE);
                } catch (BadResponseException $e) {
                    $this->getSubjectMediator()->notify('request.bad_response');
                    if ($this->response) {
                        $e->setResponse($this->response);
                    }
                    throw $e;
                }
            } catch (RequestException $e) {
                $e->setRequest($this);
                $this->getSubjectMediator()->notify('request.exception', $e);
                throw $e;
            }
        }

        return $this->response;
    }

    /**
     * Get the previously received {@see Response} or NULL if the request has
     * not been sent
     *
     * @return Response|null
     */
    public function getResponse()
    {
        return $this->response;
    }

    /**
     * Get the intercepting filter Chain that is processed before the request
     * is sent.
     *
     * @return Chain
     */
    public function getPrepareChain()
    {
        if (!$this->prepareChain) {
            $this->prepareChain = new Chain();
        }

        return $this->prepareChain;
    }

    /**
     * Get the intercepting filter Chain that is processed after the response is
     * received
     *
     * @return Chain
     */
    public function getProcessChain()
    {
        if (!$this->processChain) {
            $this->processChain = new Chain();
        }

        return $this->processChain;
    }

    /**
     * Get the collection of key value pairs that will be used as the query
     * string in the request
     *
     * @param bool $asString (optional) Set to TRUE to get the query as string
     *
     * @return QueryString|string
     */
    public function getQuery($asString = false)
    {
        return $asString ? (string) $this->url->getQuery() : $this->url->getQuery();
    }

    /**
     * Get the HTTP method of the request
     *
     * @return string
     */
    public function getMethod()
    {
        return $this->method;
    }

    /**
     * Get the URI scheme of the request (http, https, ftp, etc)
     *
     * @return string
     */
    public function getScheme()
    {
        return $this->url->getScheme();
    }

    /**
     * Set the URI scheme of the request (http, https, ftp, etc)
     *
     * @param string $scheme Scheme to set
     *
     * @return RequestInterface
     */
    public function setScheme($scheme)
    {
        $this->url->setScheme($scheme);

        return $this;
    }

    /**
     * Get the host of the request
     *
     * @return string
     */
    public function getHost()
    {
        return $this->url->getHost();
    }

    /**
     * Set the host of the request.
     *
     * @param string $host Host to set (e.g. www.yahoo.com, www.yahoo.com)
     *
     * @return RequestInterface
     */
    public function setHost($host)
    {
        $parts = explode(':', $host);
        $this->url->setHost($parts[0]);
        if (isset($parts[1])) {
            $this->setPort($parts[1]);
        } else {
            $this->headers->set('Host', $host);
        }

        return $this;
    }

    /**
     * Get the HTTP protocol version of the request
     *
     * @param bool $curlValue (optional) Set to TRUE to retrieve the cURL value
     *      for the HTTP protocol version
     *
     * @return string|int
     */
    public function getProtocolVersion($curlValue = false)
    {
        if (!$curlValue) {
            return $this->protocolVersion;
        } else {
            return ($this->protocolVersion === '1.0')
                ? CURL_HTTP_VERSION_1_0 : CURL_HTTP_VERSION_1_1;
        }
    }

    /**
     * Set the HTTP protocol version of the request (e.g. 1.1 or 1.0)
     *
     * @param string $protocol HTTP protocol version to use with the request
     *
     * @return Request
     */
    public function setProtocolVersion($protocol)
    {
        $this->protocolVersion = $protocol;

        return $this;
    }

    /**
     * Get the path of the request (e.g. '/', '/index.html')
     *
     * @return string
     */
    public function getPath()
    {
        return $this->url->getPath();
    }

    /**
     * Set the path of the request (e.g. '/', '/index.html')
     *
     * @param string $path Path to set
     *
     * @return RequestInterface
     */
    public function setPath($path)
    {
        $this->url->setPath($path);

        return $this;
    }

    /**
     * Get the port that the request will be sent on if it has been set
     *
     * @return int|null
     */
    public function getPort()
    {
        return $this->url->getPort();
    }

    /**
     * Set the port that the request will be sent on
     *
     * @param int $port Port number to set
     *
     * @return RequestInterface
     */
    public function setPort($port)
    {
        $this->url->setPort($port);
        // Include the port in the Host header if it is not the default port
        // for the scheme of the URL
        if (($this->url->getScheme() == 'http' && $port != 80) || ($this->url->getScheme() == 'https' && $port != 443)) {
            $this->headers->set('Host', $this->url->getHost() . ':' . $port);
        } else {
            $this->headers->set('Host', $this->url->getHost());
        }

        return $this;
    }

    /**
     * Get the username to pass in the URL if set
     *
     * @return string|null
     */
    public function getUsername()
    {
        return $this->username;
    }

    /**
     * Get the password to pass in the URL if set
     *
     * @return string|null
     */
    public function getPassword()
    {
        return $this->password;
    }

    /**
     * Set HTTP authorization parameters
     *
     * @param string|false $user (optional) User name or false disable authentication
     * @param string $password (optional) Password
     * @param string $scheme (optional) Curl authentication scheme to use
     *
     * @return Request
     *
     * @see http://www.ietf.org/rfc/rfc2617.txt
     * @throws RequestException
     */
    public function setAuth($user, $password = '', $scheme = CURLAUTH_BASIC)
    {
        // If we got false or null, disable authentication
        if (!$user || !$password) {
            $this->password = $this->username = null;
            $this->removeHeader('Authorization');
            $this->getCurlOptions()->remove(CURLOPT_HTTPAUTH);
        } else {
            $this->username = $user;
            $this->password = $password;
            // Bypass CURL when using basic auth to promote connection reuse
            if ($scheme == CURLAUTH_BASIC) {
                $this->getCurlOptions()->remove(CURLOPT_HTTPAUTH);
                $this->setHeader('Authorization', 'Basic ' . base64_encode($this->username . ':' . $this->password));
            } else {
                $this->getCurlOptions()->set(CURLOPT_HTTPAUTH, $scheme)
                     ->set(CURLOPT_USERPWD, $this->username . ':' . $this->password);
            }
        }

        return $this;
    }

    /**
     * Get the URI of the request (e.g. '/', '/index.html', '/index.html?q=1)
     * This is the path plus the query string, fragment
     *
     * @return string
     */
    public function getResourceUri()
    {
        $url = $this->url->getPath();

        $query = (string) $this->url->getQuery();
        if ($query) {
            $url .= $query;
        }

        return $url;
    }

    /**
     * Get the full URL of the request (e.g. 'http://www.guzzle-project.com/')
     *
     * scheme://username:password@domain:port/path?query_string#fragment
     *
     * @return string
     */
    public function getUrl()
    {
        return (string)$this->url;
    }

    /**
     * Get the state of the request.  One of 'complete', 'sending', 'new'
     *
     * @return string
     */
    public function getState()
    {
        return $this->state;
    }

    /**
     * Set the state of the request
     *
     * @param string $state State of the request (complete, sending, or new)
     *
     * @return RequestInterface
     */
    public function setState($state)
    {
        $this->state = $state;

        switch ($state) {
            case self::STATE_NEW:
                $this->response = null;
                $this->responseBody = null;
                $this->processedResponse = false;
                $this->preparedRequest = false;
                $this->getParams()->remove('queued_response');
                $this->curlOptions->clear();
                $this->releaseCurlHandle();
                break;
            case self::STATE_COMPLETE:
                $this->processResponse();
                break;
        }

        return $this;
    }

    /**
     * Get the cURL options that will be applied when the cURL handle is created
     *
     * @return Collection
     */
    public function getCurlOptions()
    {
        return $this->curlOptions;
    }

    /**
     * Get the cURL handle
     *
     * This method will only create the cURL handle once.  After calling this
     * method, subsequent modifications to this request will not ever take
     * effect or modify the curl handle associated with the request until
     * ->setState('new') is called, causing a new cURL handle to be given to
     * the request (using a smart factory, the new handle might be the same
     * handle).
     *
     * @return CurlHandle|null Returns NULL if no handle should be created
     */
    public function getCurlHandle()
    {
        // Create a new cURL handle using the cURL factory
        if (!$this->curlHandle) {
            // Call the prepare chain to prepare the request
            $this->getPrepareChain()->process($this);
            $this->curlHandle = $this->curlFactory->getHandle($this);
        }

        return $this->curlHandle;
    }

    /**
     * Set the factory that will create cURL handles based on the request
     *
     * @param CurlFactoryInterface $factory Factory used to create cURL handles
     *
     * @return Request
     * @codeCoverageIgnore
     */
    public function setCurlFactory(CurlFactoryInterface $factory)
    {
        $this->curlFactory = $factory;

        return $this;
    }

    /**
     * Method to receive HTTP response headers as they are retrieved
     *
     * @param string $data Header data.
     *
     * @return integer Returns the size of the data.
     */
    public function receiveResponseHeader($data)
    {
        $this->state = self::STATE_TRANSFER;
        $length = strlen($data);

        // Normalize line endings
        $data = preg_replace("/([^\r])(\n)\b/", "$1\r\n", $data);

        if (preg_match('/^\HTTP\/1\.[0|1]\s\d{3}\s.+$/', $data)) {
            list($dummy, $code, $status) = explode(' ', str_replace("\r\n", '', $data), 3);
            $this->getSubjectMediator()->notify('transaction.receive_response_header', array(
                'header' => 'HTTP',
                'value' => $code
            ), true);
            $this->response = new Response($code, null, $this->getResponseBody());
            $this->response->setStatus($code, $status)->setRequest($this);
        } else if ($length > 2) {
            list($header, $value) = array_map('trim', explode(':', trim($data), 2));
            $this->response->addHeaders(array(
                $header => $value
            ));
            $this->getSubjectMediator()->notify('transaction.receive_response_header', array(
                'header' => $header,
                'value' => $value
            ), true);
        }

        return $length;
    }

    /**
     * Manually set a response for the request.
     *
     * This method is useful for specifying a mock response for the request or
     * setting the response using a cache.  Manually setting a response will
     * bypass the actual sending of a request.
     *
     * @param Response $response Response object to set
     * @param bool $queued (optional) Set to TRUE to keep the request in a stat
     *      of not having been sent, but queue the response for send()
     *
     * @return Request Returns a reference to the object.
     */
    public function setResponse(Response $response, $queued = false)
    {
        $this->transaction = null;
        $response->setRequest($this);

        if (!$queued) {
            $this->getParams()->remove('queued_response');
            $this->response = $response;
            $this->responseBody = $response->getBody();
            $this->processResponse();
        } else {
            $this->getParams()->set('queued_response', $response);
        }

        $this->getSubjectMediator()->notify('request.set_response', $this->response);

        return $this;
    }

    /**
     * Set the EntityBody that will hold the response message's entity body.
     *
     * This method should be invoked when you need to send the response's
     * entity body somewhere other than the normal php://temp buffer.  For
     * example, you can send the entity body to a socket, file, or some other
     * custom stream.
     *
     * @param EntityBody $body Response body object
     *
     * @return Request
     */
    public function setResponseBody(EntityBody $body)
    {
        $this->responseBody = $body;

        return $this;
    }

    /**
     * Determine if the response body is repeatable (readable + seekable)
     *
     * @return bool
     */
    public function isResponseBodyRepeatable()
    {
        return !$this->responseBody ? true : $this->responseBody->isSeekable() && $this->responseBody->isReadable();
    }

    /**
     * Get an array of Cookies or a specific cookie from the request
     *
     * @param string $name (optional) Cookie to retrieve
     *
     * @return null|string|Cookie Returns null if not found by name, a Cookie
     *      object if no $name is supplied, or the cookie value by name if found
     *      If a Cookie object is returned, changes to the cookie object does
     *      not modify the request's cookies.  You will need to set the cookie
     *      back on the request after modifying the object.
     */
    public function getCookie($name = null)
    {
        return !$name ? clone $this->cookie : $this->cookie->get($name);
    }

    /**
     * Set the Cookie header using an array or Cookie object
     *
     * @param array|Cookie $cookies Cookie data to set on the request
     *
     * @return Request
     */
    public function setCookie($cookies)
    {
        if ($cookies instanceof Cookie) {
            $this->cookie = $cookies;
        } else if (is_array($cookies)) {
            $this->cookie->replace($cookies);
        } else {
            throw new \InvalidArgumentException('Invalid cookie data');
        }

        $this->headers->set('Cookie', (string) $this->cookie);

        return $this;
    }

    /**
     * Add a Cookie value by name to the Cookie header
     *
     * @param string $name Name of the cookie to add
     * @param string $value Value to set
     *
     * @return Request
     */
    public function addCookie($name, $value)
    {
        $this->cookie->add($name, $value);
        $this->headers->set('Cookie', (string) $this->cookie);

        return $this;
    }

    /**
     * Remove the cookie header or a specific cookie value by name
     *
     * @param string $name (optional) Cookie to remove by name.  If no value is
     *      provided, the entire Cookie header is removed from the request
     *
     * @return Request
     */
    public function removeCookie($name = null)
    {
        $this->cookie->remove($name);
        $this->headers->set('Cookie', (string) $this->cookie);

        return $this;
    }

    /**
     * Returns whether or not the response served to the request can be cached
     *
     * @return bool
     */
    public function canCache()
    {
        // Only GET and HEAD requests can be cached
        if ($this->method != RequestInterface::GET && $this->method != RequestInterface::HEAD) {
            return false;
        }

        // Never cache requests when using no-store
        if ($this->hasCacheControlDirective('no-store')) {
            return false;
        }

        return true;
    }
    
    /**
     * Set the response processor
     *
     * @param null|ResponseProcessorInterface $processor Response processor
     *
     * @return Request
     *
     * @throws InvalidArgumentException on invalid response processor
     */
    public function setResponseProcessor($processor)
    {
        // @codeCoverageIgnoreStart
        if (!is_null($processor) && !($processor instanceof ResponseProcessorInterface)) {
            throw new \InvalidArgumentException('Invalid response processor');
        }
        // @codeCoverageIgnoreEnd

        $this->responseProcessor = $processor;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    protected function changedHeader($action, $keyOrArray)
    {
        $keys = (array)$keyOrArray;

        parent::changedHeader($action, $keys);

        if (in_array('Cookie', $keys)) {
            if ($action == 'set') {
                $this->cookie = Cookie::factory($this->getHeader('Cookie'));
            } else {
                $this->cookie->clear();
            }
        }

        if (in_array('Host', $keys)) {
            $parts = explode(':', $this->getHeader('Host'));
            $this->url->setHost($parts[0]);
            if (isset($parts[1])) {
                $this->url->setPort($parts[1]);
            } else if ($this->url->getScheme() == 'http') {
                $this->url->setPort(80);
            } else if ($this->url->getScheme() == 'https') {
                $this->url->setPort(443);
            }
        }
    }

    /**
     * Get the EntityBody that will store the received response entity body
     *
     * @return EntityBody
     */
    protected function getResponseBody()
    {
        if (is_null($this->responseBody)) {
            $this->responseBody = EntityBody::factory('');
        }

        return $this->responseBody;
    }

    /**
     * Process a received response
     *
     * @throws BadResponseException on unsuccessful responses
     */
    protected function processResponse()
    {
        if (!$this->processedResponse) {

            // Use the queued response if one is set
            if ($this->getParams()->get('queued_response')) {
                $this->response = $this->getParams()->get('queued_response');
                $this->responseBody = $this->response->getBody();
                $this->getParams()->remove('queued_response');
            } else if ($this->curlHandle && $this->curlHandle->getErrorNo()) {
                $error = $this->curlHandle->getError();
                $e = new CurlException('[curl] '
                    . $this->curlHandle->getErrorNo() . ': ' . $error
                    . ' [url] ' . $this->getUrl() . ' [info] '
                    . var_export($this->curlHandle->getInfo(), true)
                    . ' [debug] ' . $this->curlHandle->getStderr());

                $e->setRequest($this);
                $e->setCurlError($error);
                $this->curlFactory->releaseHandle($this->curlHandle, true);
                $this->curlHandle = null;

                throw $e;
            }

            if (!$this->response) {
                $e = new RequestException(
                    'Unable to set state to complete because no response has '
                    . 'been received by the request'
                );
                $e->setRequest($this);

                throw $e;
            }

            // cURL can modify the request from it's initial HTTP message.  The
            // following code parses the sent HTTP request headers from cURL and
            // updates the request object to most accurately reflect the HTTP
            // message sent over the wire.
            if ($this->curlHandle && $this->curlHandle->isAvailable()) {

                // Set the transfer stats on the response
                $log = $this->curlHandle->getStderr();
                $this->response->setInfo(array_merge(array(
                    'stderr' => $log
                ), $this->curlHandle->getInfo()));

                // Parse the cURL stderr output for outgoing requests
                $headers = '';
                fseek($this->curlHandle->getStderr(true), 0);
                while (($line = fgets($this->curlHandle->getStderr(true))) !== false) {
                    if ($line && $line[0] == '>') {
                        $headers = substr(trim($line), 2) . "\r\n";
                        while (($line = fgets($this->curlHandle->getStderr(true))) !== false) {
                            if ($line[0] == '*' || $line[0] == '<') {
                                break;
                            } else {
                                $headers .= trim($line) . "\r\n";
                            }
                        }
                    }
                }

                if ($headers) {
                    $parsed = RequestFactory::getInstance()->parseMessage($headers);
                    $this->method = $parsed['method'];
                    $this->setHost($parsed['parts']['host']);
                    $this->setPort($parsed['parts']['port']);
                    $this->setProtocolVersion($parsed['protocol_version']);
                    $this->headers->clear();
                    foreach ($parsed['headers'] as $name => $value) {
                        $this->setHeader($name, $value);
                    }
                }
            }

            $this->getSubjectMediator()->notify('request.sent', $this);
            $this->state = self::STATE_COMPLETE;
            $this->getProcessChain()->process($this);

            // Some response processor can remove the response or reset the state
            if ($this->state == RequestInterface::STATE_COMPLETE) {
                
                $this->processedResponse = true;

                $this->getSubjectMediator()->notify('request.complete', $this->response);

                // Release the cURL handle
                $this->releaseCurlHandle();

                // Use the default response processor if one was not set
                if (!$this->responseProcessor) {
                    // @codeCoverageIgnoreStart
                    if (!self::$defaultResponseProcessor) {
                        self::$defaultResponseProcessor = new DefaultResponseProcessor();
                    }
                    // @codeCoverageIgnoreEnd
                    $this->setResponseProcessor(self::$defaultResponseProcessor);
                }

                $this->responseProcessor->processResponse($this, $this->response);
            }
        }

        return $this;
    }

    /**
     * Release the cURL handle if one is claimed
     *
     * @return Request
     */
    public function releaseCurlHandle()
    {
        if ($this->curlHandle) {
            // Check if the handle should be closed
            $this->curlFactory->releaseHandle(
                $this->curlHandle,
                ($this->response && $this->response->getConnection() == 'close') || $this->getHeader('Connection') == 'close' || $this->getProtocolVersion() === '1.0'
            );
            
            $this->curlHandle = null;
        }

        return $this;
    }
}