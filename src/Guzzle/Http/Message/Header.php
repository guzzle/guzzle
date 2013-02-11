<?php

namespace Guzzle\Http\Message;

use Guzzle\Common\ToArrayInterface;

/**
 * Represents a header and all of the values stored by that header
 */
class Header implements ToArrayInterface, \IteratorAggregate, \Countable
{
    protected $values = array();
    protected $header;
    protected $glue;
    protected $stringCache;
    protected $arrayCache;

    /**
     * Construct a new header object
     *
     * @param string $header Name of the header
     * @param array  $values Values of the header
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
     * @param string $value  Value to add
     * @param string $header The exact header casing to add with. Defaults to the name of the header.
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

        // Ensure that the array cache is cleared
        $this->clearCache();

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
     * Normalize the header to be a single header with an array of values
     *
     * @param bool $explodeOnGlue Set to true to explode each header value on the glue of the header
     *
     * @return Header
     */
    public function normalize($explodeOnGlue = false)
    {
        $values = $this->toArray();
        $this->clearCache();

        // Explode each value on glue if needed
        if ($this->glue && $explodeOnGlue) {
            foreach ($values as $i => $value) {
                // Explode the value if the glue was found in the header
                if (strpos($value, $this->glue)) {
                    foreach (explode($this->glue, $value) as $v) {
                        $values[] = $v;
                    }
                    unset($values[$i]);
                }
            }
        }

        $this->values = array($this->getName() => $values);

        return $this;
    }

    /**
     * Check if a particular case variation is present in the header
     * Example: A header exists on a message for 'Foo', and 'foo'. The Header object will contain all of the values of
     * 'Foo' and all of the values of 'foo'.  You can use this method to check to see if a header was set using
     * 'foo' (true), 'Foo' (true), 'FOO' (false), etc.
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
     * @param string $searchValue     Value to search for
     * @param bool   $caseInsensitive Set to TRUE to use a case insensitive search
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
     * Remove a specific value from the header
     *
     * @param string $searchValue Value to remove
     *
     * @return self
     */
    public function removeValue($searchValue)
    {
        foreach ($this->values as $key => $values) {
            foreach ($values as $index => $value) {
                if ($value == $searchValue) {
                    unset($this->values[$key][$index]);
                    $this->clearCache();
                    break 2;
                }
            }
        }

        return $this;
    }

    /**
     * Get all of the header values as a flat array
     * {@inheritdoc}
     */
    public function toArray()
    {
        if ($this->arrayCache === null) {
            $this->arrayCache = array();
            foreach ($this->values as $values) {
                $this->arrayCache = array_merge($this->arrayCache, $values);
            }
        }

        return $this->arrayCache;
    }

    /**
     * Get the raw data array of the headers. This array is represented as an associative array of the various cases
     * that might be stored in the header and an array of values associated with each case variation.
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
     * @return \ArrayIterator
     */
    public function getIterator()
    {
        return new \ArrayIterator($this->toArray());
    }

    /**
     * Clear the internal header cache
     */
    private function clearCache()
    {
        $this->arrayCache = null;
        $this->stringCache = null;
    }
}
