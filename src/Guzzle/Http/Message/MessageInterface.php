<?php

namespace Guzzle\Http\Message;

use Guzzle\Common\Collection;

/**
 * HTTP messages consist of request messages that request data from a server,
 * and response messages that carry back data from the server to the client.
 * 
 * @author Michael Dowling <michael@guzzlephp.org>
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
     * @param string $header The case-insensitive header to retrieve. Can be a
     *      regular expression
     * @param mixed $default (optional) If the header is not found, the passed
     *      $default value will be returned
     *
     * @return string|null Returns the matching HTTP header or NULL if the
     *      header is not found
     */
    function getHeader($header, $default = null);
    
    /**
     * Get all or all matching headers.
     *
     * @param array $names (optional) Pass an array of header names to retrieve
     *      only a particular subset of headers.  Regular expressions are
     *      accepted in the $names array of values.
     *
     * @return array Returns an array of all headers if no $names array is
     *      specified, or an array of only the headers matching the values in
     *      the $names array.
     */
    function getHeaders(array $headers = null);

    /**
     * Returns TRUE or FALSE if the specified header is present.
     *
     * @param string $header The header to check.  This parameter can also be a
     *      regular expression
     * @param bool $caseInsensitive (optional) Set to TRUE to compliment the
     *      $header argument and match headers in a case-insensitive comparison.
     *
     * @return bool Returns TRUE if the header is present and FALSE if not set
     */
    function hasHeader($header, $caseInsensitive = false);

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