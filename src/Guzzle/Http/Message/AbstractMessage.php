<?php

namespace Guzzle\Http\Message;

use Guzzle\Common\Collection;
use Guzzle\Common\Exception\InvalidArgumentException;

/**
 * HTTP messages consist of request messages that request data from a server,
 * and response messages that carry back data from the server to the client.
 */
abstract class AbstractMessage implements MessageInterface
{
    /**
     * @var array HTTP headers
     */
    protected $headers = array();

    /**
     * @var Collection Custom message parameters that are extendable by plugins
     */
    protected $params;

    /**
     * @var array Cache-Control directive information
     */
    private $cacheControl = array();

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
        return $this->params;
    }

    /**
     * Add a header to an existing collection of headers.
     *
     * @param string $header Header name to add
     * @param string $value  Value of the header
     *
     * @return AbstractMessage
     */
    public function addHeader($header, $value)
    {
        $key = strtolower($header);
        if (!isset($this->headers[$key])) {
            $this->headers[$key] = new Header($header, $value);
        } else {
            $this->headers[$key]->add($value, $header);
        }
        $this->changedHeader($key);

        return $this;
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
        foreach ($headers as $key => $value) {
            $this->addHeader($key, $value);
        }

        return $this;
    }

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
    public function getHeader($header, $string = false)
    {
        $key = strtolower($header);
        if (!isset($this->headers[$key])) {
            return null;
        }

        return $string ? (string) $this->headers[$key] : $this->headers[$key];
    }

    /**
     * Get all headers as a collection
     *
     * @param $asObjects Set to true to retrieve a collection of Header objects
     *
     * @return Collection Returns a {@see Collection} of all headers
     */
    public function getHeaders($asObjects = false)
    {
        if ($asObjects) {
            $result = $this->headers;
        } else {
            $result = array();
            // Convert all of the headers into a collection
            foreach ($this->headers as $header) {
                foreach ($header->raw() as $key => $value) {
                    $result[$key] = $value;
                }
            }
        }

        return new Collection($result);
    }

    /**
     * Get an array of message header lines
     *
     * @return array
     */
    public function getHeaderLines()
    {
        $headers = array();
        foreach ($this->headers as $value) {
            $glue = $value->getGlue();
            foreach ($value->raw() as $key => $v) {
                $headers[] = rtrim($key . ': ' . implode($glue, $v));
            }
        }

        return $headers;
    }

    /**
     * Set an HTTP header
     *
     * @param string $header Name of the header to set.
     * @param mixed  $value  Value to set.
     *
     * @return AbstractMessage
     */
    public function setHeader($header, $value)
    {
        // Remove any existing header
        $key = strtolower($header);
        unset($this->headers[$key]);

        if ($value instanceof Header) {
            $this->headers[$key] = $value;
        } else {
            // Allow for 0, '', and NULL to be set
            if (!$value) {
                $value = array($value);
            }
            $this->headers[$key] = new Header($header, $value);
        }
        $this->changedHeader($key);

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
        // Get the keys that are changing
        $changed = array_keys($this->headers);
        // Erase the old headers
        $this->headers = array();
        // Add the new headers
        foreach ($headers as $key => $value) {
            $changed[] = $key;
            $this->addHeader($key, $value);
        }

        // Notify of the changed headers
        foreach (array_unique($changed) as $header) {
            $this->changedHeader(strtolower($header));
        }

        return $this;
    }

    /**
     * Check if the specified header is present.
     *
     * @param string $header The header to check.
     *
     * @return bool Returns TRUE or FALSE if the header is present
     */
    public function hasHeader($header)
    {
        return array_key_exists(strtolower($header), $this->headers);
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
        $header = strtolower($header);
        unset($this->headers[$header]);
        $this->changedHeader($header);

        return $this;
    }

    /**
     * Get a tokenized header as a Collection
     *
     * @param string $header Header to retrieve
     * @param string $token  Token separator
     *
     * @return Collection|null
     */
    public function getTokenizedHeader($header, $token = ';')
    {
        if (!$this->hasHeader($header)) {
            return null;
        }

        $data = new Collection();

        foreach ($this->getHeader($header) as $singleValue) {
            foreach (explode($token, $singleValue) as $kvp) {
                $parts = explode('=', $kvp, 2);
                if (!isset($parts[1])) {
                    $data[count($data)] = trim($parts[0]);
                } else {
                    $data->add(trim($parts[0]), trim($parts[1]));
                }
            }
        }

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data->set($key, array_unique($value));
            }
        }

        return $data;
    }

    /**
     * Set a tokenized header on the request that implodes a Collection of data
     * into a string separated by a token
     *
     * @param string           $header Header to set
     * @param array|Collection $data   Header data
     * @param string           $token  Token delimiter
     *
     * @return AbstractMessage
     * @throws InvalidArgumentException if data is not an array or Collection
     */
    public function setTokenizedHeader($header, $data, $token = ';')
    {
        if (!($data instanceof Collection) && !is_array($data)) {
            throw new InvalidArgumentException('Data must be a Collection or array');
        }

        $values = array();
        foreach ($data as $key => $value) {
            foreach ((array) $value as $v) {
                $values[] = is_int($key) ? $v : $key . '=' . $v;
            }
        }

        return $this->setHeader($header, implode($token, $values));
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
     * @param string      $directive Directive to set
     * @param bool|string $value     Value to set
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
     * Check to see if the modified headers need to reset any of the managed
     * headers like cache-control
     *
     * @param string $header Header that changed
     */
    protected function changedHeader($header)
    {
        if ($header == 'cache-control') {
            $this->parseCacheControlDirective();
        }
    }

    /**
     * Parse the Cache-Control HTTP header into an array
     */
    private function parseCacheControlDirective()
    {
        $this->cacheControl = array();
        $tokenized = $this->getTokenizedHeader('Cache-Control', ',') ?: array();
        foreach ($tokenized as $key => $value) {
            if (is_numeric($key)) {
                $this->cacheControl[$value] = true;
            } else {
                $this->cacheControl[$key] = $value;
            }
        }
    }

    /**
     * Rebuild the Cache-Control HTTP header using the user-specified values
     */
    private function rebuildCacheControlDirective()
    {
        $cacheControl = array();
        foreach ($this->cacheControl as $key => $value) {
            $cacheControl[] = ($value === true) ? $key : ($key . '=' . $value);
        }
        $this->headers['cache-control'] = new Header('Cache-Control', $cacheControl, ', ');
    }
}
