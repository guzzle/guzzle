<?php

namespace GuzzleHttp;

/**
 * Trait implementing ToArrayInterface, \ArrayAccess, \Countable,
 * \IteratorAggregate, and some path style methods.
 */
trait HasDataTrait
{
    /** @var array */
    protected $data;

    public function getIterator()
    {
        return new \ArrayIterator($this->data);
    }

    public function offsetGet($offset)
    {
        return isset($this->data[$offset]) ? $this->data[$offset] : null;
    }

    public function offsetSet($offset, $value)
    {
        $this->data[$offset] = $value;
    }

    public function offsetExists($offset)
    {
        return isset($this->data[$offset]);
    }

    public function offsetUnset($offset)
    {
        unset($this->data[$offset]);
    }

    public function toArray()
    {
        return $this->data;
    }

    public function count()
    {
        return count($this->data);
    }

    /**
     * Gets a value from the collection using an array path.
     *
     * This method does not allow for keys that contain "/". You must traverse
     * the array manually or using something more advanced like JMESPath to
     * work with keys that contain "/".
     *
     *     // Get the bar key of a set of nested arrays.
     *     // This is equivalent to $collection['foo']['baz']['bar'] but won't
     *     // throw warnings for missing keys.
     *     $collection->getPath('foo/baz/bar');
     *
     * @param string $path Path to traverse and retrieve a value from
     *
     * @return mixed|null
     */
    public function getPath($path)
    {
        $data =& $this->data;
        $path = explode('/', $path);

        while (null !== ($part = array_shift($path))) {
            if (!is_array($data) || !isset($data[$part])) {
                return null;
            }
            $data =& $data[$part];
        }

        return $data;
    }

    /**
     * Set a value into a nested array key. Keys will be created as needed to
     * set the value.
     *
     * This function does not support keys that contain "/" or "[]" characters
     * because these are special tokens used when traversing the data structure.
     * A value may be prepended to an existing array by using "[]" as the final
     * key of a path.
     *
     *     $collection->getPath('foo/baz'); // null
     *     $collection->setPath('foo/baz/[]', 'a');
     *     $collection->setPath('foo/baz/[]', 'b');
     *     $collection->getPath('foo/baz');
     *     // Returns ['a', 'b']
     *
     * @param string $path  Path to set
     * @param mixed  $value Value to set at the key
     *
     * @return self
     * @throws \RuntimeException when trying to setPath using a nested path
     *     that travels through a scalar value
     */
    public function setPath($path, $value)
    {
        $current =& $this->data;
        $queue = explode('/', $path);
        while (null !== ($key = array_shift($queue))) {
            if (!is_array($current)) {
                throw new \RuntimeException("Trying to setPath {$path}, but "
                    . "{$key} is set and is not an array");
            } elseif (!$queue) {
                if ($key == '[]') {
                    $current[] = $value;
                } else {
                    $current[$key] = $value;
                }
            } elseif (isset($current[$key])) {
                $current =& $current[$key];
            } else {
                $current[$key] = [];
                $current =& $current[$key];
            }
        }

        return $this;
    }
}
