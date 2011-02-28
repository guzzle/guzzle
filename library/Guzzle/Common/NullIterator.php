<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Common;

/**
 * Implements the NULL Object design pattern for iterators.
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class NullIterator extends NullObject implements \Iterator, \Countable
{
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

    public function count()
    {
        return null;
    }
}