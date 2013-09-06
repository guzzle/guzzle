<?php

namespace Guzzle\Stream;

/**
 * Streams that implement ReadableStreamInterface may be read from and
 * converted to a string.
 */
interface ReadableStreamInterface extends StreamInterface
{
    /**
     * Convert the stream to a string by calling read until the end of the
     * stream is reached.
     *
     * Warning: This will attempt to load the entire stream into memory.
     *
     * @return string
     */
    public function __toString();

    /**
     * Read data from the stream
     *
     * @param int $length Read up to $length bytes from the object and return
     *                    them. Fewer than $length bytes may be returned if
     *                    underlying stream call returns fewer bytes.
     *
     * @return string|bool Returns the data read from the stream or false on
     *                     failure or when the end of the stream is reached.
     */
    public function read($length);

    /**
     * Returns true if the stream is at the end of the stream.
     *
     * @return bool
     */
    public function eof();
}
