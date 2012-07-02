<?php

namespace Guzzle\Common\Batch;

use Guzzle\Common\HasDispatcherInterface;
use Guzzle\Common\Exception\InvalidArgumentException;

/**
 * Interface for efficiently transferring items in a queue using batches
 */
interface BatchInterface
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

    /**
     * Check if the batch is empty and has further items to transfer
     *
     * @return bool
     */
    function isEmpty();
}
