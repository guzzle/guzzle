<?php

namespace Guzzle\Http\Message;

use Guzzle\Common\Collection;

/**
 * Request and response message interface
 */
interface MessageInterface
{
    /**
     * Get application and plugin specific parameters set on the message.
     *
     * @return Collection
     */
    function getParams();

    /**
     * Add a header to an existing collection of headers.
     *
     * @param string $header Header name to add
     * @param string $value  Value of the header
     *
     * @return MessageInterface
     */
    function addHeader($header, $value);

    /**
     * Add and merge in an array of HTTP headers.
     *
     * @param array $headers Associative array of header data.
     *
     * @return MessageInterface
     */
    function addHeaders(array $headers);

    /**
     * Retrieve an HTTP header by name.  Performs a case-insensitive search of
     * all headers.
     *
     * @param string $header Header to retrieve.
     * @param bool   $string Set to true to get the header as a string
     *
     * @return string|Header|null Returns NULL if no matching header is found.
     *     Returns a string if $string is set to TRUE.  Returns a Header object
     *     if a matching header is found.
     */
    function getHeader($header, $string = false);

    /**
     * Get a tokenized header as a Collection
     *
     * @param string $header Header to retrieve
     * @param string $token  Token separator
     *
     * @return Collection|null
     */
    function getTokenizedHeader($header, $token = ';');

    /**
     * Set a tokenized header on the request that implodes a Collection of data
     * into a string separated by a token.
     *
     * @param string           $header Header to set
     * @param array|Collection $data   Header data
     * @param string           $token  Token delimiter
     *
     * @return MessageInterface
     * @throws InvalidArgumentException if data is not an array or Collection
     */
    function setTokenizedHeader($header, $data, $token = ';');

    /**
     * Get all headers as a collection
     *
     * @return Collection Returns a {@see Collection} of all headers
     */
    function getHeaders();

    /**
     * Check if the specified header is present.
     *
     * @param string $header The header to check.
     *
     * @return bool Returns TRUE or FALSE if the header is present
     */
    function hasHeader($header);

    /**
     * Remove a specific HTTP header.
     *
     * @param string $header HTTP header to remove.
     *
     * @return MessageInterface
     */
    function removeHeader($header);

    /**
     * Set an HTTP header
     *
     * @param string $header Name of the header to set.
     * @param mixed  $value  Value to set.
     *
     * @return MessageInterface
     */
    function setHeader($header, $value);

    /**
     * Overwrite all HTTP headers with the supplied array of headers
     *
     * @param array $headers Associative array of header data.
     *
     * @return MessageInterface
     */
    function setHeaders(array $headers);

    /**
     * Get the raw message headers as a string
     *
     * @return string
     */
    function getRawHeaders();

    /**
     * Get a Cache-Control directive from the message
     *
     * @param string $directive Directive to retrieve
     *
     * @return null|string
     */
    function getCacheControlDirective($directive);

    /**
     * Check if the message has a Cache-Control directive
     *
     * @param string $directive Directive to check
     *
     * @return bool
     */
    function hasCacheControlDirective($directive);

    /**
     * Add a Cache-Control directive on the message
     *
     * @param string      $directive Directive to set
     * @param bool|string $value     Value to set
     *
     * @return MessageInterface
     */
    function addCacheControlDirective($directive, $value);

    /**
     * Remove a Cache-Control directive from the message
     *
     * @param string $directive Directive to remove
     *
     * @return MessageInterface
     */
    function removeCacheControlDirective($directive);
}
