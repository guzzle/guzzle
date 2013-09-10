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

    /** @var array */
    private $headerNames = [];

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
        foreach ($this->normalized as $name => $str) {
            $result .= "{$this->headerNames[$name]}: {$str}\r\n";
        }

        return substr($result, 0, -2);
    }

    public function getIterator()
    {
        return new \ArrayIterator($this->toArray());
    }

    public function toArray()
    {
        return array_combine($this->headerNames, $this->headers);
    }

    public function clear()
    {
        $this->headers = $this->normalized = $this->headerNames = [];
    }

    public function add($name, $value)
    {
        $value = trim($value);
        $name = trim($name);
        $key = strtolower($name);
        $this->headerNames[$key] = $name;

        if (!isset($this->normalized[$key])) {
            $this->normalized[$key] = $value;
            $this->headers[$key] = [$value];
        } else {
            $this->normalized[$key] .= ', ' . $value;
            $this->headers[$key][] = $value;
        }
    }

    public function offsetExists($offset)
    {
        return isset($this->normalized[strtolower($offset)]);
    }

    public function offsetGet($offset)
    {
        return isset($this->headers[$offset]) ? $this->headers[$offset] : null;
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

        if (isset($this->normalized[$lower])) {
            unset($this->normalized[$lower]);
            unset($this->headers[$lower]);
            unset($this->headerNames[$lower]);
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
            $part = [];
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
