<?php

namespace Guzzle\Common;

/**
 * Implements the NULL Object design pattern for generic objects.
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class NullObject implements \Iterator, \Countable, \ArrayAccess
{
    public function __call($method, $params)
    {
        return null;
    }

    public function __get($variable)
    {
        return null;
    }

    public function __set($variable, $value)
    {
        return null;
    }

    public function __isset($variable)
    {
        return null;
    }

    public function __unset($variable)
    {
        return null;
    }

    public function offsetExists($offset)
    {
        return false;
    }

    public function offsetUnset($offset)
    {
        return null;
    }

    public function offsetSet($offset, $value)
    {
        return null;
    }

    public function offsetGet($offset)
    {
        return null;
    }

    public function count()
    {
        return null;
    }

    public function current()
    {
        return null;
    }

    public function key()
    {
        return null;
    }

    public function next()
    {
        return null;
    }

    public function rewind()
    {
        return null;
    }

    public function valid()
    {
        return null;
    }
}