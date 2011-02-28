<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Http\Message;

use Guzzle\Common\Subject\Subject;
use Guzzle\Http\EntityBody;
use Guzzle\Http\QueryString;
use Guzzle\Http\Curl\CurlFactoryInterface;
use Guzzle\Http\Curl\CurlHandle;
use Guzzle\Http\Cookie;

/**
 * Generic HTTP request interface
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
interface RequestInterface extends MessageInterface, Subject
{
    const STATE_NEW = 'new';
    const STATE_COMPLETE = 'complete';
    const STATE_TRANSFER = 'transfer';

    const AUTH_BASIC = 'Basic';
    const AUTH_DIGEST = 'Digest';

    const GET = 'GET';
    const PUT = 'PUT';
    const POST = 'POST';
    const DELETE = 'DELETE';
    const HEAD = 'HEAD';
    const CONNECT = 'CONNECT';
    const OPTIONS = 'OPTIONS';
    const TRACE = 'TRACE';

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
    public function __construct($method, $url, $headers = array(), CurlFactoryInterface $curlFactory = null);

    /**
     * Clone the request object, leaving off any response that was received
     */
    public function __clone();

    /**
     * Get the HTTP request as a string
     *
     * @return string
     */
    public function __toString();

    /**
     * Set the URL of the request
     *
     * Warning: Calling this method will modify headers, rewrite the  query
     * string object, and set other data associated with the request.
     *
     * @param string $url Full URL to set including query string
     *
     * @return RequestInterface
     */
    public function setUrl($url);

    /**
     * Send the request
     *
     * @return Response
     * @throws RequestException on a request error
     */
    public function send();

    /**
     * Get the previously received {@see Response} or NULL if the request has
     * not been sent
     *
     * @return Response|null
     */
    public function getResponse();

    /**
     * Get the intercepting filter Chain that is processed before the request
     * is sent.
     *
     * @return Chain
     */
    public function getPrepareChain();

    /**
     * Get the intercepting filter Chain that is processed after the response is
     * received
     *
     * @return Chain
     */
    public function getProcessChain();

    /**
     * Get the collection of key value pairs that will be used as the query
     * string in the request
     *
     * @return QueryString
     */
    public function getQuery();

    /**
     * Get the HTTP method of the request
     *
     * @return string
     */
    public function getMethod();

    /**
     * Get the URI scheme of the request (http, https, ftp, etc)
     *
     * @return string
     */
    public function getScheme();

    /**
     * Set the URI scheme of the request (http, https, ftp, etc)
     *
     * @param string $scheme Scheme to set
     *
     * @return RequestInterface
     */
    public function setScheme($scheme);

    /**
     * Get the host of the request
     *
     * @return string
     */
    public function getHost();

    /**
     * Set the host of the request.  Including a port in the host will modify
     * the port of the request.
     *
     * @param string $host Host to set (e.g. www.yahoo.com, www.yahoo.com:80)
     *
     * @return RequestInterface
     */
    public function setHost($host);

    /**
     * Get the HTTP protocol version of the request
     *
     * @param bool $curlValue (optional) Set to TRUE to retrieve the cURL value
     *      for the HTTP protocol version
     *
     * @return string|int
     */
    public function getProtocolVersion($curlValue = false);

    /**
     * Set the HTTP protocol version of the request (e.g. 1.1 or 1.0)
     *
     * @param string $protocol HTTP protocol version to use with the request
     *
     * @return RequestInterface
     */
    public function setProtocolVersion($protocol);

    /**
     * Get the path of the request (e.g. '/', '/index.html')
     *
     * @return string
     */
    public function getPath();

    /**
     * Set the path of the request (e.g. '/', '/index.html')
     *
     * @param string $path Path to set
     *
     * @return RequestInterface
     */
    public function setPath($path);

    /**
     * Get the port that the request will be sent on if it has been set
     *
     * @return int|null
     */
    public function getPort();

    /**
     * Set the port that the request will be sent on
     *
     * @param int $port Port number to set
     *
     * @return RequestInterface
     */
    public function setPort($port);

    /**
     * Get the username to pass in the URL if set
     *
     * @return string|null
     */
    public function getUsername();

    /**
     * Set HTTP authorization parameters
     *
     * @param string|false $user (optional) User name or false disable authentication
     * @param string $password (optional) Password
     * @param string $scheme (optional) Authentication scheme to use (Basic, Digest)
     *
     * @return Request
     *
     * @see http://www.ietf.org/rfc/rfc2617.txt
     * @throws RequestException
     */
    public function setAuth($user, $password = '', $scheme = 'Basic');

    /**
     * Get the password to pass in the URL if set
     *
     * @return string|null
     */
    public function getPassword();

    /**
     * Get the URI of the request (e.g. '/', '/index.html', '/index.html?q=1)
     * This is the path plus the query string, fragment
     *
     * @return string
     */
    public function getResourceUri();

    /**
     * Get the full URL of the request (e.g. 'http://www.guzzle-project.com/')
     *
     * scheme://username:password@domain:port/path?query_string#anchor
     *
     * @return string
     */
    public function getUrl();

    /**
     * Get the state of the request.  One of 'complete', 'sending', 'new'
     *
     * @return string
     */
    public function getState();

    /**
     * Set the state of the request
     *
     * @param string $state State of the request (complete, sending, or new)
     *
     * @return RequestInterface
     */
    public function setState($state);

    /**
     * Get the cURL options that will be applied when the cURL handle is created
     *
     * @return Collection
     */
    public function getCurlOptions();

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
    public function getCurlHandle();

    /**
     * Set the factory that will create cURL handles based on the request
     *
     * @param CurlFactoryInterface $factory Factory used to create cURL handles
     *
     * @return Request
     */
    public function setCurlFactory(CurlFactoryInterface $factory);

    /**
     * Method to receive HTTP response headers as they are retrieved
     *
     * @param string $data Header data.
     *
     * @return integer Returns the size of the data.
     */
    public function receiveResponseHeader($data);

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
    public function setResponseBody(EntityBody $body);

    /**
     * Determine if the response body is repeatable (readable + seekable)
     *
     * @return bool
     */
    public function isResponseBodyRepeatable();

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
     * @return RequestInterface Returns a reference to the object.
     */
    public function setResponse(Response $response, $queued = false);

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
    public function getCookie($name = null);

    /**
     * Set the Cookie header using an array or Cookie object
     *
     * @param array|Cookie $cookies Cookie data to set on the request
     *
     * @return RequestInterface
     */
    public function setCookie($cookies);

    /**
     * Add a Cookie value by name to the Cookie header
     *
     * @param string $name Name of the cookie to add
     * @param string $value Value to set
     *
     * @return RequestInterface
     */
    public function addCookie($name, $value);

    /**
     * Remove the cookie header or a specific cookie value by name
     *
     * @param string $name (optional) Cookie to remove by name.  If no value is
     *      provided, the entire Cookie header is removed from the request
     *
     * @return RequestInterface
     */
    public function removeCookie($name = null);

    /**
     * Returns whether or not the response served to the request can be cached
     *
     * @return bool
     */
    public function canCache();

    /**
     * Set the response processor
     *
     * @param null|ResponseProcessorInterface $processor Response processor
     *
     * @return RequestInterface
     *
     * @throws InvalidArgumentException on invalid response processor
     */
    public function setResponseProcessor($processor);

    /**
     * Release the cURL handle if one is claimed
     *
     * @return RequestInterface
     */
    public function releaseCurlHandle();
}