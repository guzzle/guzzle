<?php

namespace Guzzle\Http\Message;

use Guzzle\Common\ToArrayInterface;

/**
 * Provides a case-insensitive collection of headers
 */
class HeaderCollection implements \IteratorAggregate, \Countable, \ArrayAccess, ToArrayInterface
{
    /** @var array */
    private $headers;

    /** @var array Normalized headers */
    private $normalized = [];

    /** @var array String headers */
    private $strings = [];

    public function __construct($headers = [])
    {
        $this->headers = $headers;
        foreach ($headers as $key => $value) {
            $this->normalized[strtolower($key)] = $value;
        }
    }

    public function __toString()
    {
        $result = '';
        foreach ($this->headers as $name => $headers) {
            $result .= $name . ': ' . implode(', ', $headers) . "\r\n";
        }

        return rtrim($result);
    }

    /**
     * Clears the header collection
     */
    public function clear()
    {
        $this->headers = $this->normalized = $this->strings = [];
    }

    /**
     * Set a header on the collection
     *
     * @param string $name  Name of the header
     * @param string $value Value of the header
     *
     * @return self
     */
    public function add($name, $value)
    {
        $value = trim($value);
        $key = strtolower($name);

        if (!isset($this->normalized[$key])) {
            $this->strings[$key] = $value;
            $this->normalized[$key] = [$value];
            $this->headers[$name] = [$value];
        } else {
            $this->strings[$key] .= ', ' . $value;
            $this->normalized[$key][] = $value;
            if (!isset($this->headers[$name])) {
                $this->headers[$name] = [$value];
            } else {
                $this->headers[$name][] = $value;
            }
        }

        return $this;
    }

    public function count()
    {
        return count($this->headers);
    }

    public function offsetExists($offset)
    {
        return isset($this->normalized[strtolower($offset)]);
    }

    public function offsetGet($offset)
    {
        $l = strtolower($offset);

        return isset($this->normalized[$l]) ? $this->normalized[$l] : null;
    }

    public function offsetSet($offset, $value)
    {
        $this->add($offset, $value);
    }

    public function offsetUnset($offset)
    {
        $lower = strtolower($offset);

        // Remove from the normalized headers
        unset($this->normalized[$lower]);
        unset($this->strings[$lower]);

        // Remove from the cased headers
        foreach ($this->headers as $key => $value) {
            if (!strcasecmp($key, $offset)) {
                unset($this->headers[$key]);
            }
        }
    }

    public function getIterator()
    {
        return new \ArrayIterator($this->headers);
    }

    public function toArray()
    {
        return $this->headers;
    }

    /**
     * Gets the string representation of a header. Multiple values are
     * concatentated using commas.
     *
     * @param string $name Name of the header to retrieve
     *
     * @return string|null
     */
    public function getHeaderString($name)
    {
        $l = strtolower($name);

        return isset($this->strings[$l]) ? $this->strings[$l] : null;
    }

    /**
     * Gets an array of header names mapping to a string of comma separated
     * values
     *
     * @return array
     */
    public function getHeaderStrings()
    {
        $result = [];
        foreach ($this->headers as $name => $values) {
            $result[$name] = implode(', ', $values);
        }

        return $result;
    }

    /**
     * Parse a header into an array key-value pairs
     *
     * @param string $name Name of the header to parse
     *
     * @return array
     */
    public function parseHeader($name)
    {
        static $trimmed = "\"'  \n\t\r";
        $params = $matches = [];

        foreach ($this->normalizeHeader($name) as $val) {
            $part = array();
            foreach (preg_split('/;(?=([^"]*"[^"]*")*[^"]*$)/', $val) as $kvp) {
                preg_match_all('/<[^>]+>|[^=]+/', $kvp, $matches);
                $pieces = $matches[0];
                if (isset($pieces[1])) {
                    $part[trim($pieces[0], $trimmed)] = trim($pieces[1], $trimmed);
                } else {
                    $part[] = trim($pieces[0], $trimmed);
                }
            }
            $params[] = $part;
        }

        return $params;
    }

    /**
     * Converts an array of header values that may contain comma separated
     * headers into an array of headers.
     *
     * @param string $name Header to normalized
     *
     * @return array
     */
    private function normalizeHeader($name)
    {
        if (!($values = $this[$name])) {
            return [];
        }

        for ($i = 0, $total = count($values); $i < $total; $i++) {
            if (strpos($values[$i], ',') !== false) {
                foreach (preg_split('/,(?=([^"]*"[^"]*")*[^"]*$)/', $values[$i]) as $v) {
                    $values[] = trim($v);
                }
                unset($values[$i]);
            }
        }

        return $values;
    }
}
