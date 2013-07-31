<?php

namespace Guzzle\Http\Message;

use Guzzle\Http\Header;
use Guzzle\Http\Header\HeaderInterface;
use Guzzle\Http\Header\HeaderCollection;
use Guzzle\Stream\StreamInterface;

/**
 * Request and response message interface
 */
interface MessageInterface extends \Serializable
{
    /**
     * Get a string represenation of the message
     *
     * @return string
     */
    public function __toString();

    /**
     * Get the start line of the message (e.g. "HTTP/1.1 200 OK")
     *
     * @return string
     */
    public function getStartLine();

    /**
     * Add a header to an existing collection of headers.
     *
     * @param string $header Header name to add
     * @param string $value  Value of the header
     *
     * @return HeaderInterface Returns the added header object
     */
    public function addHeader($header, $value = null);

    /**
     * Add and merge in an array of HTTP headers.
     *
     * @param array $headers Associative array of header data.
     *
     * @return self
     */
    public function addHeaders(array $headers);

    /**
     * Retrieve an HTTP header by name. Performs a case-insensitive search of all headers.
     *
     * @param string $header Header to retrieve.
     *
     * @return HeaderInterface|null
     */
    public function getHeader($header);

    /**
     * Get all headers as a collection
     *
     * @return HeaderCollection
     */
    public function getHeaders();

    /**
     * Check if the specified header is present.
     *
     * @param string $header The header to check.
     *
     * @return bool
     */
    public function hasHeader($header);

    /**
     * Remove a specific HTTP header.
     *
     * @param string $header HTTP header to remove.
     *
     * @return self
     */
    public function removeHeader($header);

    /**
     * Set an HTTP header and overwrite any existing value for the header
     *
     * @param string $header Name of the header to set.
     * @param mixed  $value  Value to set.
     *
     * @return HeaderInterface Returns the set header object
     */
    public function setHeader($header, $value = null);

    /**
     * Overwrite all HTTP headers with the supplied array of headers
     *
     * @param array $headers Associative array of header data.
     *
     * @return self
     */
    public function setHeaders(array $headers);

    /**
     * Set the HTTP protocol version of the request (e.g. 1.1 or 1.0)
     *
     * @param string $protocol HTTP protocol version to use with the request
     *
     * @return self
     */
    public function setProtocolVersion($protocol);

    /**
     * Get the HTTP protocol version of the message
     *
     * @return string
     */
    public function getProtocolVersion();

    /**
     * Set the body of the message
     *
     * @param string|resource|StreamInterface $body Body of the message
     * @param string                                $contentType Content-Type to set. Leave null to use an existing
     *                                                           Content-Type or to guess the Content-Type
     * @return self
     * @throws \LogicException when set on a request if the protocol is < 1.1 and Content-Length cannot be determined
     */
    public function setBody($body, $contentType = null);

    /**
     * Get the body of the message
     *
     * @return StreamInterface|null
     */
    public function getBody();
}
