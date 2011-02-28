<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

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
     * @param string $header The case-insensitive header to retrieve. Can be a
     *      regular expression
     * @param mixed $default (optional) If the header is not found, the passed
     *      $default value will be returned
     *
     * @return string|null Returns the matching HTTP header or NULL if the
     *      header is not found
     */
    public function getHeader($header, $default = null)
    {
        return $this->headers->get($header, $default);
    }

    /**
     * Get all or all matching headers.
     *
     * @param array $names (optional) Pass an array of header names to retrieve
     *      only a particular subset of headers.  Regular expressions are
     *      accepted in the $names array of values.
     *
     * @return Collection Returns a collection of all headers if no $names
     *      array is specified, or a Collection of only the headers matching
     *      the headers in the $names array.
     */
    public function getHeaders(array $headers = null)
    {
        if (!$headers) {
            return clone $this->headers;
        } else {
            return new Collection($this->headers->getAll($headers, true));
        }
    }

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
    public function hasHeader($header, $caseInsensitive = false)
    {
        return $this->headers->hasKey($header, $caseInsensitive);
    }

    /**
     * Remove a specific HTTP header.
     *
     * @param string $header HTTP header to remove.
     *
     * @return AbstractMessage
     */
    public function removeHeader($header)
    {
        $this->headers->remove($header);
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