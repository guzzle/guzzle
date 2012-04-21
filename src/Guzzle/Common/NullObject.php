<?php

namespace Guzzle\Common;

/**
 * Implements the NULL Object design pattern for generic objects.
 */
class NullObject implements \Iterator, \Countable, \ArrayAccess
{
    public function __call($method, $params) {}
    public function __get($variable) {}
    public function __set($variable, $value) {}
    public function __isset($variable) {}
    public function __unset($variable) {}
    public function offsetExists($offset) {}
    public function offsetUnset($offset) {}
    public function offsetSet($offset, $value) {}
    public function offsetGet($offset) {}
    public function count() {}
    public function current() {}
    public function key() {}
    public function next() {}
    public function rewind() {}
    public function valid() {}
}
