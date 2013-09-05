<?php

namespace Guzzle\Stream;

interface ReadableStreamInterface
{
    /**
     * Read data from the stream
     *
     * @param int $length Up to length number of bytes read.
     *
     * @return string|bool Returns the data read from the stream or FALSE on failure or EOF
     */
    public function read($length);

    /**
     * Check if the stream is readable
     *
     * @return bool
     */
    public function isReadable();

    /**
     * Returns true if the stream is at the end of the stream
     *
     * @return bool
     */
    public function eof();
}
