<?php

namespace Guzzle\Http\Message;

use Guzzle\Common\HasDispatcherInterface;
use Guzzle\Http\ClientInterface;
use Guzzle\Http\EntityBody;
use Guzzle\Http\QueryString;

/**
 * Generic HTTP request interface
 */
interface RequestInterface extends MessageInterface, HasDispatcherInterface
{
    const STATE_NEW = 'new';
    const STATE_COMPLETE = 'complete';
    const STATE_TRANSFER = 'transfer';
    const STATE_ERROR = 'error';

    const GET = 'GET';
    const PUT = 'PUT';
    const POST = 'POST';
    const DELETE = 'DELETE';
    const HEAD = 'HEAD';
    const CONNECT = 'CONNECT';
    const OPTIONS = 'OPTIONS';
    const TRACE = 'TRACE';
    const PATCH = 'PATCH';

    /**
     * Create a new request
     *
     * @param string     $method HTTP method
     * @param string|Url $url    HTTP URL to connect to.  The URI scheme, host
     *      header, and URI are parsed from the full URL.  If query string
     *      parameters are present they will be parsed as well.
     * @param array|Collection $headers HTTP headers
     */
    function __construct($method, $url, $headers = array());

    /**
     * Get the HTTP request as a string
     *
     * @return string
     */
    function __toString();

    /**
     * Set the client used to transport the request
     *
     * @param ClientInterface $client
     *
     * @return RequestInterface
     */
    function setClient(ClientInterface $client);

    /**
     * Get the client used to transport the request
     *
     * @return ClientInterface $client
     */
    function getClient();

    /**
     * Set the URL of the request
     *
     * Warning: Calling this method will modify headers, rewrite the  query
     * string object, and set other data associated with the request.
     *
     * @param string $url|Url Full URL to set including query string
     *
     * @return RequestInterface
     */
    function setUrl($url);

    /**
     * Send the request
     *
     * @return Response
     * @throws RequestException on a request error
     */
    function send();

    /**
     * Get the previously received {@see Response} or NULL if the request has
     * not been sent
     *
     * @return Response|null
     */
    function getResponse();

    /**
     * Get the collection of key value pairs that will be used as the query
     * string in the request
     *
     * @return QueryString
     */
    function getQuery();

    /**
     * Get the HTTP method of the request
     *
     * @return string
     */
    function getMethod();

    /**
     * Get the URI scheme of the request (http, https, ftp, etc)
     *
     * @return string
     */
    function getScheme();

    /**
     * Set the URI scheme of the request (http, https, ftp, etc)
     *
     * @param string $scheme Scheme to set
     *
     * @return RequestInterface
     */
    function setScheme($scheme);

    /**
     * Get the host of the request
     *
     * @return string
     */
    function getHost();

    /**
     * Set the host of the request.  Including a port in the host will modify
     * the port of the request.
     *
     * @param string $host Host to set (e.g. www.yahoo.com, www.yahoo.com:80)
     *
     * @return RequestInterface
     */
    function setHost($host);

    /**
     * Get the HTTP protocol version of the request
     *
     * @return string
     */
    function getProtocolVersion();

    /**
     * Set the HTTP protocol version of the request (e.g. 1.1 or 1.0)
     *
     * @param string $protocol HTTP protocol version to use with the request
     *
     * @return RequestInterface
     */
    function setProtocolVersion($protocol);

    /**
     * Get the path of the request (e.g. '/', '/index.html')
     *
     * @return string
     */
    function getPath();

    /**
     * Set the path of the request (e.g. '/', '/index.html')
     *
     * @param string|array $path Path to set or array of segments to implode
     *
     * @return RequestInterface
     */
    function setPath($path);

    /**
     * Get the port that the request will be sent on if it has been set
     *
     * @return int|null
     */
    function getPort();

    /**
     * Set the port that the request will be sent on
     *
     * @param int $port Port number to set
     *
     * @return RequestInterface
     */
    function setPort($port);

    /**
     * Get the username to pass in the URL if set
     *
     * @return string|null
     */
    function getUsername();

    /**
     * Set HTTP authorization parameters
     *
     * @param string|false $user     User name or false disable authentication
     * @param string       $password Password
     * @param string       $scheme   Authentication scheme to use (Basic, Digest)
     *
     * @return Request
     *
     * @see http://www.ietf.org/rfc/rfc2617.txt
     * @throws RequestException
     */
    function setAuth($user, $password = '', $scheme = 'Basic');

    /**
     * Get the password to pass in the URL if set
     *
     * @return string|null
     */
    function getPassword();

    /**
     * Get the resource part of the the request, including the path, query
     * string, and fragment
     *
     * @return string
     */
    function getResource();

    /**
     * Get the full URL of the request (e.g. 'http://www.guzzle-project.com/')
     * scheme://username:password@domain:port/path?query_string#fragment
     *
     * @param bool $asObject Set to TRUE to retrieve the URL as
     *     a clone of the URL object owned by the request.
     *
     * @return string|Url
     */
    function getUrl($asObject = false);

    /**
     * Get the state of the request.  One of 'complete', 'sending', 'new'
     *
     * @return string
     */
    function getState();

    /**
     * Set the state of the request
     *
     * @param string $state State of the request (complete, sending, or new)
     *
     * @return RequestInterface
     */
    function setState($state);

    /**
     * Get the cURL options that will be applied when the cURL handle is created
     *
     * @return Collection
     */
    function getCurlOptions();

    /**
     * Method to receive HTTP response headers as they are retrieved
     *
     * @param string $data Header data.
     *
     * @return integer Returns the size of the data.
     */
    function receiveResponseHeader($data);

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
    function setResponseBody(EntityBody $body);

    /**
     * Determine if the response body is repeatable (readable + seekable)
     *
     * @return bool
     */
    function isResponseBodyRepeatable();

    /**
     * Manually set a response for the request.
     *
     * This method is useful for specifying a mock response for the request or
     * setting the response using a cache.  Manually setting a response will
     * bypass the actual sending of a request.
     *
     * @param Response $response Response object to set
     * @param bool     $queued   Set to TRUE to keep the request in a stat
     *      of not having been sent, but queue the response for send()
     *
     * @return RequestInterface Returns a reference to the object.
     */
    function setResponse(Response $response, $queued = false);

    /**
     * Get an array of Cookies
     *
     * @return array
     */
    function getCookies();

    /**
     * Get a cookie value by name
     *
     * @param string $name Cookie to retrieve
     *
     * @return null|string
     */
    function getCookie($name);

    /**
     * Add a Cookie value by name to the Cookie header
     *
     * @param string $name  Name of the cookie to add
     * @param string $value Value to set
     *
     * @return RequestInterface
     */
    function addCookie($name, $value);

    /**
     * Remove a specific cookie value by name
     *
     * @param string $name Cookie to remove by name
     *
     * @return RequestInterface
     */
    function removeCookie($name);

    /**
     * Returns whether or not the response served to the request can be cached
     *
     * @return bool
     */
    function canCache();
}
