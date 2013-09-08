<?php

namespace Guzzle\Http\Message;

/**
 * A class that implements this interface contains a bag of HTTP headers
 */
interface HasHeadersInterface
{
    /**
     * Get all headers as a collection
     *
     * @return HeaderCollection
     */
    public function getHeaders();

    /**
     * Retrieve an HTTP header by name. Performs a case-insensitive search of all headers.
     *
     * @param string $header Header to retrieve.
     *
     * @return string|null
     */
    public function getHeader($header);

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
     * Add a header to an existing collection of headers.
     *
     * @param string $header Header name to add
     * @param string $value  Value of the header
     *
     * @return self
     */
    public function addHeader($header, $value);

    /**
     * Set an HTTP header and overwrite any existing value for the header
     *
     * @param string $header Name of the header to set.
     * @param mixed  $value  Value to set.
     *
     * @return self
     */
    public function setHeader($header, $value);

    /**
     * Overwrite all HTTP headers with the supplied array of headers
     *
     * @param array $headers Associative array of header data.
     *
     * @return self
     */
    public function setHeaders(array $headers);
}
