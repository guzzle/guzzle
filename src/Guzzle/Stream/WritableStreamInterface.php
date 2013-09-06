<?php

namespace Guzzle\Stream;

/**
 * Streams that implement WritableStreamInterface may be written to.
 */
interface WritableStreamInterface extends StreamInterface
{
    /**
     * Check if the stream is writable
     *
     * @return bool
     */
    public function isWritable();

    /**
     * Write data to the stream
     *
     * @param string $string The string that is to be written.
     *
     * @return int|bool Returns the number of bytes written to the stream on
     *                  success or FALSE on failure.
     */
    public function write($string);
}
