<?php

namespace Guzzle\Http\Message;

use Guzzle\Common\ToArrayInterface;

class HeaderCollection implements
    \IteratorAggregate,
    HeaderCollectionInterface,
    ToArrayInterface
{
    /** @var array */
    private $headers = [];

    /** @var array String headers */
    private $normalized = [];

    /**
     * @param array $headers Associative array of header names to an array of string values
     */
    public function __construct($headers = [])
    {
        foreach ($headers as $name => $value) {
            $this->add($name, $value);
        }
    }

    public function __toString()
    {
        $result = '';
        foreach ($this->headers as $name => $headers) {
            $result .= $name . ': ' . implode(', ', $headers) . "\r\n";
        }

        return substr($result, 0, -2);
    }

    public function getIterator()
    {
        return new \ArrayIterator($this->headers);
    }

    public function toArray()
    {
        return $this->headers;
    }

    public function clear()
    {
        $this->headers = $this->normalized = [];
    }

    public function add($name, $value)
    {
        $value = trim($value);
        $name = trim($name);
        $key = strtolower($name);

        if (!isset($this->normalized[$key])) {
            $this->normalized[$key] = $value;
            $this->headers[$name] = [$value];
        } else {
            $this->normalized[$key] .= ', ' . $value;
            if (!isset($this->headers[$name])) {
                $this->headers[$name] = [$value];
            } else {
                $this->headers[$name][] = $value;
            }
        }
    }

    public function offsetExists($offset)
    {
        return isset($this->normalized[strtolower($offset)]);
    }

    public function offsetGet($offset)
    {
        $values = [];
        foreach ($this->headers as $name => $value) {
            if (!strcasecmp($name, $offset)) {
                $values = array_merge($values, $value);
            }
        }

        return $values ?: null;
    }

    public function offsetSet($offset, $value)
    {
        unset($this[$offset]);
        foreach ((array) $value as $v) {
            $this->add($offset, $v);
        }
    }

    public function offsetUnset($offset)
    {
        $lower = strtolower($offset);

        // Only perform the case-insensitive checks if needed
        if (isset($this->normalized[$lower])) {
            unset($this->normalized[$lower]);
            // Remove from the cased headers
            foreach ($this->headers as $key => $value) {
                if (strtolower($key) === $lower) {
                    unset($this->headers[$key]);
                }
            }
        }
    }

    public function getHeaderString($name)
    {
        $l = strtolower($name);

        return isset($this->normalized[$l]) ? $this->normalized[$l] : null;
    }

    public function parseParams($name)
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
