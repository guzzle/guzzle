<?php

namespace Guzzle\Http\Message;

use Guzzle\Http\Message\Header\HeaderInterface;

/**
 * Represents a header and all of the values stored by that header
 */
class Header implements HeaderInterface
{
    protected $values = array();
    protected $header;
    protected $glue;

    /**
     * Construct a new header object
     *
     * @param string       $header Name of the header
     * @param array|string $values Values of the header as an array or a scalar
     * @param string       $glue   Glue used to combine multiple values into a string
     */
    public function __construct($header, $values = array(), $glue = ',')
    {
        $this->header = trim($header);
        $this->glue = $glue;

        foreach ((array) $values as $value) {
            foreach ((array) $value as $v) {
                $this->values[] = $v;
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function __toString()
    {
        return implode($this->glue . ' ', $this->toArray());
    }

    /**
     * {@inheritdoc}
     */
    public function add($value)
    {
        $this->values[] = $value;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return $this->header;
    }

    /**
     * {@inheritdoc}
     */
    public function setName($name)
    {
        $this->header = $name;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function setGlue($glue)
    {
        $this->glue = $glue;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getGlue()
    {
        return $this->glue;
    }

    /**
     * {@inheritdoc}
     */
    public function normalize()
    {
        $values = $this->toArray();

        for ($i = 0, $total = count($values); $i < $total; $i++) {
            if (strpos($values[$i], $this->glue) !== false) {
                foreach (explode($this->glue, $values[$i]) as $v) {
                    $values[] = trim($v);
                }
                unset($values[$i]);
            }
        }

        $this->values = array_values($values);

        return $this;
    }

    /**
     * @deprecated
     */
    public function hasExactHeader($header)
    {
        return $this->header == $header;
    }

    /**
     * {@inheritdoc}
     */
    public function hasValue($searchValue)
    {
        return in_array($searchValue, $this->toArray());
    }

    /**
     * {@inheritdoc}
     */
    public function removeValue($searchValue)
    {
        $this->values = array_values(array_filter($this->values, function ($value) use ($searchValue) {
            return $value != $searchValue;
        }));

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function toArray()
    {
        return $this->values;
    }

    /**
     * {@deprecated}
     */
    public function raw()
    {
        return $this->toArray();
    }

    /**
     * {@inheritdoc}
     */
    public function count()
    {
        return count($this->toArray());
    }

    /**
     * {@inheritdoc}
     */
    public function getIterator()
    {
        return new \ArrayIterator($this->toArray());
    }

    /**
     * {@inheritdoc}
     * @todo Do not split semicolons when enclosed in quotes (e.g. foo="baz;bar")
     */
    public function parseParams()
    {
        $params = array();
        $callback = array($this, 'trimHeader');

        // Normalize the header into a single array and iterate over all values
        foreach ($this->normalize()->toArray() as $val) {
            $part = array();
            foreach (explode(';', $val) as $kvp) {
                $pieces = array_map($callback, explode('=', $kvp, 2));
                $part[$pieces[0]] = isset($pieces[1]) ? $pieces[1] : '';
            }
            $params[] = $part;
        }

        return $params;
    }

    /**
     * Trim a header by removing excess spaces and wrapping quotes
     *
     * @param $str
     *
     * @return string
     */
    protected function trimHeader($str)
    {
        static $trimmed = "\"'  \n\t";

        return trim($str, $trimmed);
    }
}
