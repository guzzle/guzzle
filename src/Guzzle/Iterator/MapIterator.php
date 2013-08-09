<?php

namespace Guzzle\Iterator;

/**
 * Maps values before yielding
 */
class MapIterator extends \IteratorIterator
{
    /** @var mixed Callback */
    protected $callback;

    /**
     * @param \Traversable $iterator Traversable iterator
     * @param callable     $callback Callback used for iterating
     *
     * @throws \InvalidArgumentException if the callback if not callable
     */
    public function __construct(\Traversable $iterator, callable $callback)
    {
        parent::__construct($iterator);
        $this->callback = $callback;
    }

    public function current()
    {
        return call_user_func($this->callback, parent::current());
    }
}
