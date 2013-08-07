<?php

namespace Guzzle\Http\Message;

use Guzzle\Common\HasDispatcherInterface;
use Guzzle\Url\Url;
use Guzzle\Url\QueryString;

/**
 * Generic HTTP request interface
 */
interface RequestInterface extends MessageInterface, HasDispatcherInterface
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
     * Set the URL of the request
     *
     * @param string $url|Url Full URL to set including query string
     *
     * @return self
     */
    public function setUrl($url);

    /**
     * Get the URL of the request
     *
     * @return string
     */
    public function getUrl();

    /**
     * Get the resource part of the the request, including the path, query string, and fragment
     *
     * @return string
     */
    public function getResource();

    /**
     * Get the collection of key value pairs that will be used as the query string in the request
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
     * Set the HTTP method of the request
     *
     * @param string $method HTTP method
     *
     * @return self
     */
    public function setMethod($method);

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
     * Set the host of the request. Including a port in the host will modify the port of the request.
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
     * @return self
     */
    public function setPort($port);

    /**
     * Prepare a request for sending over the wire. For example, this method should ensure that POST fields and files
     * are set in the body of the request, appropriate headers are set on the request, etc...
     *
     * @return self
     */
    public function prepare();

    /**
     * Get the request's configuration options
     *
     * @return \Guzzle\Common\Collection
     */
    public function getConfig();
}
