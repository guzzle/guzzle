<?php

namespace Guzzle\Http\Message;

class HeaderValues implements \IteratorAggregate, HeaderValuesInterface
{
    /** @var array Header values*/
    protected $values = [];

    /**
     * @param array $values Values of the header
     */
    public function __construct(array $values = [])
    {
        foreach ($values as $value) {
            $this->values[] = trim($value);
        }
    }

    public function __toString()
    {
        return implode(', ', $this->values);
    }

    public function offsetExists($offset)
    {
        return isset($this->values[$offset]);
    }

    public function offsetGet($offset)
    {
        return isset($this->values[$offset])
            ? $this->values[$offset] : null;
    }

    public function offsetSet($offset, $value)
    {
        if (null === $offset) {
            $this->values[] = trim($value);
        } else {
            $this->values[$offset] = trim($value);
        }
    }

    public function offsetUnset($offset)
    {
        unset($this->values[$offset]);
    }

    public function count()
    {
        return count($this->values);
    }

    public function getIterator()
    {
        return new \ArrayIterator($this->values);
    }

    public function parseParams()
    {
        static $trimmed = "\"'  \n\t\r";
        $params = $matches = [];

        foreach ($this->normalize() as $val) {
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
     * @return array
     */
    private function normalize()
    {
        $values = $this->values;
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
