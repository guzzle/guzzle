<?php

namespace Guzzle\Http\Message;

use DateTime;
use Guzzle\Common\Exception\InvalidArgumentException;
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
    public function getParams();

    /**
     * Add a header to an existing collection of headers.
     *
     * @param string $header Header name to add
     * @param string $value  Value of the header
     *
     * @return MessageInterface
     */
    public function addHeader($header, $value);

    /**
     * Add and merge in an array of HTTP headers.
     *
     * @param array $headers Associative array of header data.
     *
     * @return MessageInterface
     */
    public function addHeaders(array $headers);

    /**
     * Retrieve an HTTP header by name. Performs a case-insensitive search of all headers.
     *
     * @param string $header Header to retrieve.
     * @param bool   $string Set to true to get the header as a string
     *
     * @return string|Header|null Returns NULL if no matching header is found. Returns a string if $string is set to
     *                            TRUE. Returns a Header object if a matching header is found.
     */
    public function getHeader($header, $string = false);

    /**
     * Get a tokenized header as a Collection
     *
     * @param string $header Header to retrieve
     * @param string $token  Token separator
     *
     * @return Collection|null
     */
    public function getTokenizedHeader($header, $token = ';');

    /**
     * Set a tokenized header on the request that implodes a Collection of data into a string separated by a token.
     *
     * @param string           $header Header to set
     * @param array|Collection $data   Header data
     * @param string           $token  Token delimiter
     *
     * @return MessageInterface
     * @throws InvalidArgumentException if data is not an array or Collection
     */
    public function setTokenizedHeader($header, $data, $token = ';');

    /**
     * Get all headers as a collection
     *
     * @param bool $asObjects Set to true to retrieve a collection of Header objects
     *
     * @return Collection Returns a {@see Collection} of all headers
     */
    public function getHeaders($asObjects = false);

    /**
     * Get an array of message header lines
     *
     * @return array
     */
    public function getHeaderLines();

    /**
     * Check if the specified header is present.
     *
     * @param string $header The header to check.
     *
     * @return bool Returns TRUE or FALSE if the header is present
     */
    public function hasHeader($header);

    /**
     * Remove a specific HTTP header.
     *
     * @param string $header HTTP header to remove.
     *
     * @return MessageInterface
     */
    public function removeHeader($header);

    /**
     * Set an HTTP header
     *
     * @param string $header Name of the header to set.
     * @param mixed  $value  Value to set.
     *
     * @return MessageInterface
     */
    public function setHeader($header, $value);

    /**
     * Overwrite all HTTP headers with the supplied array of headers
     *
     * @param array $headers Associative array of header data.
     *
     * @return MessageInterface
     */
    public function setHeaders(array $headers);

    /**
     * Get the raw message headers as a string
     *
     * @return string
     */
    public function getRawHeaders();

    /**
     * Get a Cache-Control directive from the message
     *
     * @param string $directive Directive to retrieve
     *
     * @return null|string
     */
    public function getCacheControlDirective($directive);

    /**
     * Check if the message has a Cache-Control directive
     *
     * @param string $directive Directive to check
     *
     * @return bool
     */
    public function hasCacheControlDirective($directive);

    /**
     * Add a Cache-Control directive on the message
     *
     * @param string      $directive Directive to set
     * @param bool|string $value     Value to set
     *
     * @return MessageInterface
     */
    public function addCacheControlDirective($directive, $value = true);

    /**
     * Remove a Cache-Control directive from the message
     *
     * @param string $directive Directive to remove
     *
     * @return MessageInterface
     */
    public function removeCacheControlDirective($directive);

    /**
     * Get a message warning by code and, optionally, agent.
     *
     * If multiple warnings are found, the first, which should be the earliest, is returned.
     *
     * @param int         $code  Warning code
     * @param null|string $agent Warning agent
     *
     * @return Warning|null
     */
    public function getWarning($code, $agent = null);

    /**
     * Get message warnings.
     *
     * Supplying a code and/or agent filters the returned array.
     *
     * @param null|int    $code  Warning code to check
     * @param null|string $agent Warning agent to check
     *
     * @return Warning[]
     */
    public function getWarnings($code = null, $agent = null);

    /**
     * Check if the message has a warning for a code and, optionally, agent.
     *
     * @param int         $code
     * @param null|string $agent
     *
     * @return bool
     */
    public function hasWarning($code, $agent = null);

    /**
     * Check if the message has warnings.
     *
     * Supplying a code and/or agent filters the returned array.
     *
     * @param null|int    $code  Warning code to check
     * @param null|string $agent Warning agent to check
     *
     * @return bool
     */
    public function hasWarnings($code = null, $agent = null);

    /**
     * Add a warning to the message.
     *
     * @param int              $code  Code
     * @param string           $agent Agent
     * @param null|string      $text  Text, or null to use the recommended text
     * @param null|DateTime    $date  Date
     *
     * @return MessageInterface
     */
    public function addWarning($code, $agent, $text = null, DateTime $date = null);

    /**
     * Remove a warning by code and, optionally, agent.
     *
     * If multiple warnings are found, the first, which should be the earliest, is removed.
     *
     * @param int         $code  Code
     * @param null|string $agent Agent
     *
     * @return MessageInterface
     */
    public function removeWarning($code, $agent = null);

    /**
     * Remove warnings.
     *
     * Supplying a code and/or agent filters the removed warnings.
     *
     * @param null|int    $code  Warning code to filter on
     * @param null|string $agent Warning agent to filter on
     *
     * @return MessageInterface
     */
    public function removeWarnings($code = null, $agent = null);
}
