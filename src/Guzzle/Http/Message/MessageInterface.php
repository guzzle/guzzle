<?php

namespace Guzzle\Http\Message;

use Guzzle\Common\Collection;

/**
 * Request and response message interface
 */
interface MessageInterface
{
    /**
     * Get application and plugin specific parameters set on the message.  The
     * return object is a reference to the internal object.
     *
     * @return Collection
     */
    function getParams();

    /**
     * Add and merge in an array of HTTP headers.
     *
     * @param array $headers Associative array of header data.
     *
     * @return MessageInterface
     */
    function addHeaders(array $headers);

    /**
     * Retrieve an HTTP header by name
     *
     * @param string $header Header to retrieve.
     * @param mixed $default (optional) If the header is not found, the passed
     *      $default value will be returned
     * @param int $match (optional) Match mode:
     *     0 - Exact match
     *     1 - Case insensitive match
     *     2 - Regular expression match
     *
     * @return string|null Returns the matching HTTP header value or NULL if the
     *      header is not found
     */
    function getHeader($header, $default = null, $match = Collection::MATCH_EXACT);

    /**
     * Get a tokenized header as a Collection
     *
     * @param string $header Header to retrieve
     * @param string $token (optional) Token separator
     * @param int $match (optional) Match mode
     *
     * @return Collection|null
     */
    function getTokenizedHeader($header, $token = ';', $match = Collection::MATCH_EXACT);

    /**
     * Set a tokenized header on the request that implodes a Collection of data
     * into a string separated by a token
     *
     * @param string $header Header to set
     * @param array|Collection $data Header data
     * @param string $token (optional) Token delimiter
     *
     * @return MessageInterface
     * @throws InvalidArgumentException if data is not an array or Collection
     */
    function setTokenizedHeader($header, $data, $token = ';');

    /**
     * Get all or all matching headers.
     *
     * @param array $names (optional) Pass an array of header names to retrieve
     *      only a particular subset of headers.
     * @param int $match (optional) Match mode
     *
     * @see MessageInterface::getHeader
     * @return Collection Returns a collection of all headers if no $headers
     *      array is specified, or a Collection of only the headers matching
     *      the headers in the $headers array.
     */
    function getHeaders(array $headers = null, $match = Collection::MATCH_EXACT);

    /**
     * Check if the specified header is present.
     *
     * @param string $header The header to check.
     * @param int $match (optional) Match mode
     *
     * @see MessageInterface::getHeader
     * @return bool|mixed Returns TRUE or FALSE if the header is present and using exact matching
     *     Returns the matching header or FALSE if no match found and using regex or case
     *     insensitive matching
     */
    function hasHeader($header, $match = Collection::MATCH_EXACT);

    /**
     * Remove a specific HTTP header.
     *
     * @param string $header HTTP header to remove.
     * @param int $match (optional) Bitwise match setting
     *
     * @see MessageInterface::getHeader
     * @return MessageInterface
     */
    function removeHeader($header, $match = Collection::MATCH_EXACT);

    /**
     * Set an HTTP header
     *
     * @param string $header Name of the header to set.
     * @param mixed $value Value to set.
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
     * @param string $directive Directive to set
     * @param bool|string $value Value to set
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