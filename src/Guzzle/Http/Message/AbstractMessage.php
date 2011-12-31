<?php

namespace Guzzle\Http\Message;

use Guzzle\Common\Collection;

/**
 * HTTP messages consist of request messages that request data from a server,
 * and response messages that carry back data from the server to the client.
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
abstract class AbstractMessage implements MessageInterface
{
    /**
     * @var Collection Collection of HTTP headers
     */
    protected $headers;

    /**
     * @var Collection Custom message parameters that are extendable by plugins
     */
    protected $params;

    /**
     * @var array Cache-Control directive information
     */
    protected $cacheControl = null;

    /*
     * @var string HTTP protocol version of the message
     */
    protected $protocolVersion = '1.1';

    /**
     * Get application and plugin specific parameters set on the message.  The
     * return object is a reference to the internal object.
     *
     * @return Collection
     */
    public function getParams()
    {
        if (!$this->params) {
            $this->params = new Collection();
        }

        return $this->params;
    }

    /**
     * Add and merge in an array of HTTP headers.
     *
     * @param array $headers Associative array of header data.
     *
     * @return AbstractMessage
     */
    public function addHeaders(array $headers)
    {
        $this->headers->merge($headers);
        $this->changedHeader('set', array_keys($headers));

        return $this;
    }

    /**
     * Retrieve an HTTP header by name
     *
     * @param string $header Header to retrieve.
     * @param mixed $default (optional) If the header is not found, the passed
     *      $default value will be returned
     * @param int $match (optional) Bitwise match setting:
     *     0 - Exact match
     *     1 - Case insensitive match
     *     2 - Regular expression match
     *
     * @return string|null Returns the matching HTTP header value or NULL if the
     *      header is not found
     */
    public function getHeader($header, $default = null, $match = Collection::MATCH_EXACT)
    {
        return $this->headers->get($header, $default, $match);
    }

    /**
     * Get all or all matching headers.
     *
     * @param array $names (optional) Pass an array of header names to retrieve
     *      only a particular subset of headers.
     * @param int $match (optional) Bitwise match setting:
     *      0 - Exact match
     *      1 - Case insensitive match
     *      2 - Regular expression match
     *
     * @return Collection Returns a collection of all headers if no $headers
     *      array is specified, or a Collection of only the headers matching
     *      the headers in the $headers array.
     */
    public function getHeaders(array $headers = null, $match = Collection::MATCH_EXACT)
    {
        if (!$headers) {
            return clone $this->headers;
        } else {
            return new Collection($this->headers->getAll($headers, $match));
        }
    }

    /**
     * Check if the specified header is present.
     *
     * @param string $header The header to check.
     * @param int $match (optional) Match mode
     *
     * @see AbstractMessage::getHeader
     * @return bool|mixed Returns TRUE or FALSE if the header is present and using exact matching
     *     Returns the matching header or FALSE if no match found and using regex or case
     *     insensitive matching
     */
    public function hasHeader($header, $match = Collection::MATCH_EXACT)
    {
        return $match == Collection::MATCH_EXACT
            ? false !== $this->headers->hasKey($header, $match)
            : $this->headers->hasKey($header, $match);
    }

    /**
     * Remove a specific HTTP header.
     *
     * @param string $header HTTP header to remove.
     * @param int $match (optional) Bitwise match setting:
     *      0 - Exact match
     *      1 - Case insensitive match
     *      2 - Regular expression match
     *
     * @return AbstractMessage
     */
    public function removeHeader($header, $match = Collection::MATCH_EXACT)
    {
        $this->headers->remove($header, $match);
        $this->changedHeader('remove', $header);

        return $this;
    }

    /**
     * Set an HTTP header
     *
     * @param string $header Name of the header to set.
     * @param mixed $value Value to set.
     *
     * @return AbstractMessage
     */
    public function setHeader($header, $value)
    {
        $this->headers->set($header, $value);
        $this->changedHeader('set', $header);

        return $this;
    }

    /**
     * Overwrite all HTTP headers with the supplied array of headers
     *
     * @param array $headers Associative array of header data.
     *
     * @return AbstractMessage
     */
    public function setHeaders(array $headers)
    {
        $this->changedHeader('set', $this->getHeaders()->getKeys());
        $this->headers->replace($headers);

        return $this;
    }

    /**
     * Get a Cache-Control directive from the message
     *
     * @param string $directive Directive to retrieve
     *
     * @return null|string
     */
    public function getCacheControlDirective($directive)
    {
        return isset($this->cacheControl[$directive]) ? $this->cacheControl[$directive] : null;
    }

    /**
     * Check if the message has a Cache-Control directive
     *
     * @param string $directive Directive to check
     *
     * @return bool
     */
    public function hasCacheControlDirective($directive)
    {
        return isset($this->cacheControl[$directive]);
    }

    /**
     * Add a Cache-Control directive on the message
     *
     * @param string $directive Directive to set
     * @param bool|string $value (optional) Value to set
     *
     * @return AbstractMessage
     */
    public function addCacheControlDirective($directive, $value = true)
    {
        $this->cacheControl[$directive] = $value;
        $this->rebuildCacheControlDirective();

        return $this;
    }

    /**
     * Remove a Cache-Control directive from the message
     *
     * @param string $directive Directive to remove
     *
     * @return AbstractMessage
     */
    public function removeCacheControlDirective($directive)
    {
        if (array_key_exists($directive, $this->cacheControl)) {
            unset($this->cacheControl[$directive]);
            $this->rebuildCacheControlDirective();
        }

        return $this;
    }

    /**
     * Parse the Cache-Control HTTP header into an array
     */
    protected function parseCacheControlDirective()
    {
        $this->cacheControl = array();
        $cacheControl = $this->getHeader('Cache-Control');
        if ($cacheControl) {
            if (is_array($cacheControl)) {
                $cacheControl = implode(',', $cacheControl);
            }
            foreach (explode(',', $cacheControl) as $pieces) {
                $parts = array_map('trim', explode('=', $pieces));
                $this->cacheControl[$parts[0]] = isset($parts[1]) ? $parts[1] : true;
            }
        }
    }

    /**
     * Rebuild the Cache-Control HTTP header using the user-specified values
     */
    protected function rebuildCacheControlDirective()
    {
        $cacheControl = array();
        foreach ($this->cacheControl as $key => $value) {
            if ($value === true) {
                $cacheControl[] = $key;
            } else {
                $cacheControl[] = $key . '=' . $value;
            }
        }

        $this->headers->set('Cache-Control', implode(', ', $cacheControl));
    }

    /**
     * Check to see if the modified headers need to reset any of the managed
     * headers like cache-control
     *
     * @param string $action One of set or remove
     * @param string|array $keyOrArray Header or headers that changed
     */
    protected function changedHeader($action, $keyOrArray)
    {
        if (in_array('Cache-Control', (array)$keyOrArray)) {
            $this->parseCacheControlDirective();
        }
    }
}