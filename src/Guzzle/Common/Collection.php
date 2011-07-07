<?php

namespace Guzzle\Common;

/**
 * Key value pair collection object
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class Collection implements \ArrayAccess, \IteratorAggregate, \Countable
{
    const MATCH_EXACT = 0;
    const MATCH_IGNORE_CASE = 1;
    const MATCH_REGEX = 2;

    /**
     * @var array Data associated with the object.
     */
    protected $data = array();

    /**
     * Constructor
     *
     * @param array $data Associative array of data to set
     */
    public function __construct(array $data = null)
    {
        if ($data) {
            $this->data = $data;
        }
    }

    /**
     * Convert the object to a string
     *
     * @return string
     */
    public function __toString()
    {
        return __CLASS__ . '@' . spl_object_hash($this);
    }

    /**
     * Add a value to a key.  If a key of the same name has
     * already been added, the key value will be converted into an array
     * and the new value will be pushed to the end of the array.
     *
     * @param string $key Key to add
     * @param mixed $value Value to add to the key
     *
     * @return Collection Returns a reference to the object.
     */
    public function add($key, $value)
    {
        if (!array_key_exists($key, $this->data)) {
            $this->data[$key] = $value;
        } else {
            if (!is_array($this->data[$key])) {
                $this->data[$key] = array(
                    $this->data[$key],
                    $value
                );
            } else {
                $this->data[$key][] = $value;
            }
        }

        return $this;
    }

    /**
     * Removes all key value pairs
     *
     * @return Collection
     */
    public function clear()
    {
        $this->data = array();

        return $this;
    }

    /**
     * Return the number of keys
     *
     * @return integer
     */
    public function count()
    {
        return count($this->data);
    }

    /**
     * Iterates over each key value pair in the collection passing them to the
     * Closure. If the  Closure function returns true, the current value from
     * input is returned into the result Collection.  The Closure must accept
     * three parameters: (string) $key, (string) $value and
     * return Boolean TRUE or FALSE for each value.
     *
     * @param Closure $closure Closure evaluation function
     * @param bool $static Set to TRUE to use the same class as the return
     *      rather than returning a Collection object
     *
     * @return Collection
     */
    public function filter(\Closure $closure, $static = true)
    {
        $collection = ($static) ? new static() : new self();
        foreach ($this->data as $key => $value) {
            if ($closure($key, $value)) {
                $collection->add($key, $value);
            }
        }

        return $collection;
    }

    /**
     * Get an iterator object
     *
     * @return array
     */
    public function getIterator()
    {
        return new \ArrayIterator($this->data);
    }

    /**
     * Get a specific key value.
     *
     * @param string $key Key to retrieve.  $key can be a string or a
     *      regular expression.
     * @param mixed $default (optional) If the key is not found, set this
     *      value to specify a default
     * @param int $match (optional) Bitwise match setting:
     *      0 - Exact match
     *      1 - Case insensitive match
     *      2 - Regular expression match
     *
     * @return mixed|null Value of the key or NULL
     */
    public function get($key, $default = null, $match = self::MATCH_EXACT)
    {
        if ($match == self::MATCH_EXACT) {
            if (array_key_exists($key, $this->data)) {
                return $this->data[$key];
            }
        } else if ($match == self::MATCH_IGNORE_CASE) {
            foreach ($this->data as $k => $value) {
                if (strcasecmp($k, $key) === 0) {
                    return $value;
                }
            }
        } else if ($match == self::MATCH_REGEX) {
            foreach ($this->data as $k => $value) {
                if (preg_match($key, $k)) {
                    return $value;
                }
            }
        }

        return $default;
    }

    /**
     * Get all or a subset of matching key value pairs
     *
     * @param array|string|int $keys (optional) Pass an array of keys to
     *      retrieve only a particular subset of kvp.
     * @param int $match (optional) Bitwise key match setting:
     *      0 - Exact match
     *      1 - Case insensitive match
     *      2 - Regular expression match
     *
     * @return array Returns an array of all key value pairs if no $keys array
     *      is specified, or an array of only the key value pairs matching the
     *      values in the $keys array.
     */
    public function getAll($keys = null, $match = false)
    {
        if (!$keys) {
            return $this->data;
        }
        $matches = array();
        $allKeys = $this->getKeys();
        foreach ((array) $keys as $expression) {
            if ($match == self::MATCH_EXACT) {
                if (in_array($expression, $allKeys)) {
                    $matches[$expression] = $this->data[$expression];
                }
            } else if ($match == self::MATCH_IGNORE_CASE) {
                foreach ($allKeys as $key) {
                    if (strcasecmp($expression, $key) === 0) {
                        $matches[$key] = $this->data[$key];
                    }
                }
            } else if ($match == self::MATCH_REGEX) {
                foreach ($allKeys as $key) {
                    if (preg_match($expression, $key)) {
                        $matches[$key] = $this->data[$key];
                    }
                }
            }
        }

        return $matches;
    }

    /**
     * Get all or a subset of matching keys
     *
     * @param string $regexp (optional) Pass a regular expression to return
     *      only keys matching the expression.
     *
     * @return array Returns an array of matching keys
     */
    public function getKeys($regexp = null)
    {
        $keys = array_keys($this->data);

        // If a regular expression was set, filter the keys
        if ($regexp) {
            $keys = array_filter($keys, function($key) use ($regexp) {
                return preg_match($regexp, $key);
            });
        }

        return $keys;
    }

    /**
     * Returns whether or not the specified key is present.
     *
     * @param string $key The key for which to check the existence.
     * @param int $match (optional) Bitwise key match setting:
     *      0 - Exact match
     *      1 - Case insensitive match
     *      2 - Regular expression match
     *
     * @return int|string Returns the key value if the key is present or FALSE
     *      if the key is not.  Use === matching to check if false.
     */
    public function hasKey($key, $match = self::MATCH_EXACT)
    {
        if ($match == self::MATCH_EXACT) {
            if (array_key_exists($key, $this->data)) {
                return $key;
            }
        } else if ($match == self::MATCH_IGNORE_CASE) {
            foreach (array_keys($this->data) as $k) {
                if (strcasecmp($k, $key) === 0) {
                    return $k;
                }
            }
        } else if ($match == self::MATCH_REGEX) {
            foreach (array_keys($this->data) as $k) {
                if (preg_match($key, $k)) {
                    return $k;
                }
            }
        }

        return false;
    }

    /**
     * Checks if any keys contains a certain value
     *
     * @param string $value Value to search for
     *
     * @return mixed Returns the key if the value was found FALSE if
     *      the value was not found.
     */
    public function hasValue($value)
    {
        return array_search($value, $this->data);
    }

    /**
     * Returns a Collection containing all the elements of the collection after
     * applying the callback function to each one. The Closure should accept
     * three parameters: (string) $key, (string) $value, (array) $context and
     * return a modified value
     *
     * @param Closure $closure Closure to apply
     * @param array $context (optional) Context to pass to the closure
     * @param bool $static Set to TRUE to use the same class as the return
     *      rather than returning a Collection object
     *
     * @return Collection
     */
    public function map(\Closure $closure, array $context = array(), $static = true)
    {
        $collection = ($static) ? new static() : new self();
        foreach ($this->getIterator() as $key => $value) {
            $collection->add($key, $closure($key, $value, $context));
        }

        return $collection;
    }

    /**
     * Add and merge in a Collection or array of key value pair data.
     *
     * Invalid $data arguments will silently fail.
     *
     * @param Collection|array $data Associative array of key value pair data
     *
     * @return Collection Returns a reference to the object.
     */
    public function merge($data)
    {
        if ($data instanceof self) {
            $data = $data->getAll();
        } else if (!is_array($data)) {
            return $this;
        }

        if (count($data)) {
            if (!count($this->data)) {
                $this->data = $data;
            } else {
                foreach ($data as $key => $value) {
                    $this->add($key, $value);
                }
            }
        }

        return $this;
    }

    /**
     * ArrayAccess implementation of offsetExists()
     *
     * @see hasKey()
     */
    public function offsetExists($offset)
    {
        return $this->hasKey($offset) !== false;
    }

    /**
     * ArrayAccess implementation of offsetGet()
     *
     * @see get()
     */
    public function offsetGet($offset)
    {
        return $this->get($offset);
    }

    /**
     * ArrayAccess implementation of offsetGet()
     *
     * @see set()
     */
    public function offsetSet($offset, $value)
    {
        return $this->set($offset, $value);
    }

    /**
     * ArrayAccess implementation of offsetUnset()
     *
     * @see remove()
     */
    public function offsetUnset($offset)
    {
        return $this->remove($offset);
    }

    /**
     * Remove a specific key value pair
     *
     * @param array|string $key A key, regexp, or array of keys to remove
     * @param int $match (optional) Bitwise key match setting:
     *     0 - Exact match
     *     1 - Case insensitive match
     *     2 - Regular expression match
     *
     * @return Collection Returns a reference to the object
     */
    public function remove($key, $match = self::MATCH_EXACT)
    {
        foreach ((array) $key as $k) {
            $matched = $this->hasKey($k, $match);
            if ($matched !== false) {
                unset($this->data[$matched]);
            }
        }

        return $this;
    }

    /**
     * Replace the data of the object with the value of an array
     *
     * @param array $data Associative array of data
     *
     * @return Collection Returns a reference to the object
     */
    public function replace(array $data)
    {
        $this->data = $data;

        return $this;
    }

    /**
     * Set a key value pair
     *
     * @param string $key Key to set
     * @param mixed $value Value to set
     *
     * @return Collection Returns a reference to the object
     */
    public function set($key, $value)
    {
        $this->data[$key] = $value;

        return $this;
    }
}