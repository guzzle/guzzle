<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Common;

/**
 * Implements the NULL Object design pattern for generic objects.
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class NullObject extends \ArrayIterator
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

    /**
     * Get an array value.  Allows access of missing array keys
     *
     * @param mixed $key Key to retrieve
     *
     * @return mixed|null Returns NULL if the key does not exist
     */
    public function offsetGet($key)
    {
        return (!$this->offsetExists($key)) ? null : parent::offsetGet($key);
    }
}