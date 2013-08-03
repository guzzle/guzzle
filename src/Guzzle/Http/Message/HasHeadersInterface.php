<?php

namespace Guzzle\Http\Message;

use Guzzle\Http\Header\HeaderInterface;
use Guzzle\Http\Header\HeaderCollection;

/**
 * A class that implements this interface contains a bag of HTTP headers
 */
interface HasHeadersInterface
{
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
}
