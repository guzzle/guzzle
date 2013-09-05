<?php

namespace Guzzle\Stream;

/**
 * Marker interface that tells if a stream can detach its inner resource
 */
interface DetachableStreamInterface
{
    /**
     * Get the underlying stream resource (if available)
     *
     * @return resource|null
     */
    public function getStream();

    /**
     * Detach the current stream resource
     */
    public function detachStream();
}
