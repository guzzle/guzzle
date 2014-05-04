<?php

namespace GuzzleHttp\Message;

use GuzzleHttp\Event\HasEmitterInterface;
use GuzzleHttp\Query;

/**
 * Generic HTTP request interface
 */
interface RequestInterface extends MessageInterface, HasEmitterInterface
{
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
     * @return string
     */
    public function __toString();

    /**
     * Sets the request URL.
     *
     * The URL MUST be a string, or an object that implements the
     * `__toString()` method.
     *
     * @param string $url Request URL.
     *
     * @return self Reference to the request.
     * @throws \InvalidArgumentException If the URL is invalid.
     */
    public function setUrl($url);

    /**
     * Gets the request URL.
     *
     * @return string Returns the URL as a string.
     */
    public function getUrl();

    /**
     * Get the resource part of the the request, including the path, query
     * string, and fragment.
     *
     * @return string
     */
    public function getResource();

    /**
     * Get the collection of key value pairs that will be used as the query
     * string in the request.
     *
     * @return Query
     */
    public function getQuery();

    /**
     * Set the query string used by the request
     *
     * @param array|Query $query Query to set
     *
     * @return self
     */
    public function setQuery($query);

    /**
     * Get the HTTP method of the request
     *
     * @return string
     */
    public function getMethod();

    /**
     * Set the HTTP method of the request
     *
     * @param string $method HTTP method
     *
     * @return self
     */
    public function setMethod($method);

    /**
     * Get the URI scheme of the request (http, https, etc.)
     *
     * @return string
     */
    public function getScheme();

    /**
     * Set the URI scheme of the request (http, https, etc.)
     *
     * @param string $scheme Scheme to set
     *
     * @return self
     */
    public function setScheme($scheme);

    /**
     * Get the host of the request
     *
     * @return string
     */
    public function getHost();

    /**
     * Set the host of the request. Including a port in the host will modify
     * the port of the request.
     *
     * @param string $host Host to set (e.g. www.yahoo.com, www.yahoo.com:80)
     *
     * @return self
     */
    public function setHost($host);

    /**
     * Get the path of the request (e.g. '/', '/index.html')
     *
     * @return string
     */
    public function getPath();

    /**
     * Set the path of the request (e.g. '/', '/index.html')
     *
     * @param string|array $path Path to set or array of segments to implode
     *
     * @return self
     */
    public function setPath($path);

    /**
     * Get the request's configuration options
     *
     * @return \GuzzleHttp\Collection
     */
    public function getConfig();
}
