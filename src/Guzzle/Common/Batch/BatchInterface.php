<?php

namespace Guzzle\Common\Batch;

use Guzzle\Common\HasDispatcherInterface;
use Guzzle\Common\Exception\InvalidArgumentException;

/**
 * Interface for efficiently transferring items in a queue using batches
 */
interface BatchInterface extends \Countable
{
    /**
     * Add an item to the queue
     *
     * @param mixed $item Item to add
     *
     * @return self
     */
    function add($item);

    /**
     * Flush the batch and transfer the items
     *
     * @return array Returns an array flushed items
     */
    function flush();
}
