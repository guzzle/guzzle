<?php

namespace Guzzle\Http\Pool;

/**
 * Send FutureRequest objects in parallel
 */
interface PoolInterface
{
    /**
     * Sends each request (batchable and non-batchable) and yields
     * RequestInterface objects as the key and ResponseInterface
     * objects as the value.
     *
     * You can convert the result of this method to an array
     * using iterator_to_array():
     *
     *     $responses = iterator_to_array($pool->send($requestArray), false);
     *
     * @param \Traversable $requests Array, iterator, or Generator that
     *                               returns RequestInterface objects
     * @return \Iterator
     */
    public function send($requests);
}
