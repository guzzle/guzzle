<?php

namespace Guzzle\Http\Message;

/**
 * Represents a header and all of the values stored by that header
 */
class Header implements \IteratorAggregate, \Countable
{
    protected $values = array();
    protected $header;
    protected $glue = ', ';
    protected $stringCache;

    /**
     * Construct a new header object
     *
     * @param string $header Name of the header
     * @param string $values Values of the header
     * @param string $glue   Glue used to combine multiple values into a string
     */
    public function __construct($header, $values = array(), $glue = ', ')
    {
        $this->header = $header;
        $this->glue = $glue;

        if (null !== $values) {
            foreach ((array) $values as $key => $value) {
                if (is_numeric($key)) {
                    $key = $header;
                }
                if ($value === null) {
                    $this->add($value, $key);
                } else {
                    foreach ((array) $value as $v) {
                        $this->add($v, $key);
                    }
                }
            }
        }
    }

    /**
     * Convert the header to a string
     *
     * @return string
     */
    public function __toString()
    {
        if (!$this->stringCache) {
            $this->stringCache = implode($this->glue, $this->toArray());
        }

        return $this->stringCache;
    }

    /**
     * Add a value to the list of header values
     *
     * @param string $value Value to add
     * @param string $header (optional) The exact header casing to add with.
     *     Defaults to the name of the header.
     *
     * @return Header
     */
    public function add($value, $header = null)
    {
        if (!$header) {
            $header = $this->getName();
        }

        if (!array_key_exists($header, $this->values)) {
            $this->values[$header] = array($value);
        } else {
            $this->values[$header][] = $value;
        }

        $this->stringCache = null;

        return $this;
    }

    /**
     * Get the name of the header
     *
     * @return string
     */
    public function getName()
    {
        return $this->header;
    }

    /**
     * Change the glue used to implode the values
     *
     * @param string $glue Glue used to implode multiple values
     *
     * @return Header
     */
    public function setGlue($glue)
    {
        $this->glue = $glue;
        $this->stringCache = null;

        return $this;
    }

    /**
     * Get the glue used to implode multiple values into a string
     *
     * @return string
     */
    public function getGlue()
    {
        return $this->glue;
    }

    /**
     * Normalize the header into a single standard header with an array of values
     *
     * @return Header
     */
    public function normalize()
    {
        $this->stringCache = null;
        $this->values = array(
            $this->getName() => $this->toArray()
        );

        return $this;
    }

    /**
     * Check if a particular case variation is present in the header
     * Example: A header exists on a message for 'Foo', and 'foo'.  The Header
     * object will contain all of the values of 'Foo' and all of the values of
     * 'foo'.  You can use this method to check to see if a header was set
     * using 'foo' (true), 'Foo' (true), 'FOO' (false), etc.
     *
     * @param string $header Exact header to check for
     *
     * @return bool
     */
    public function hasExactHeader($header)
    {
        return array_key_exists($header, $this->values);
    }

    /**
     * Check if the collection of headers has a particular value
     *
     * @param string $searchValue Value to search for
     * @param bool   $caseInsensitive (optional) Set to TRUE to use a case
     *     insensitive search
     *
     * @return bool
     */
    public function hasValue($searchValue, $caseInsensitive = false)
    {
        foreach ($this->toArray() as $value) {
            if ($value == $searchValue) {
                return true;
            } elseif ($caseInsensitive && !strcasecmp($value, $searchValue)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get all of the header values as a flat array
     *
     * @return array
     */
    public function toArray()
    {
        $result = array();
        foreach ($this->values as $values) {
            foreach ($values as $value) {
                $result[] = $value;
            }
        }

        return $result;
    }

    /**
     * Get the raw data array of the headers.  This array is represented as an
     * associative array of the various cases that might be stored in the
     * header and an array of values associated with each case variation.
     *
     * @return array
     */
    public function raw()
    {
        return $this->values;
    }

    /**
     * Returns the total number of header values
     *
     * @return int
     */
    public function count()
    {
        return count($this->toArray());
    }

    /**
     * Get an iterator that can be used to easily iterate over each header value
     *
     * @return ArrayIterator
     */
    public function getIterator()
    {
        return new \ArrayIterator($this->toArray());
    }
}
