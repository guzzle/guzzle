<?php

namespace Guzzle\Stream;

interface StreamInterface
{
    /**
     * Convert the stream to a string
     *
     * @return string
     */
    public function __toString();

    /**
     * Close the stream
     */
    public function close();

    /**
     * Get stream metadata
     *
     * @param string $key Specific metadata to retrieve
     *
     * @return array|mixed|null
     */
    public function getMetadata($key = null);

    /**
     * Get the URI/filename associated with this stream
     *
     * @return string
     */
    public function getUri();

    /**
     * Returns the current position of the file read/write pointer
     *
     * @return int|bool Returns the position of the file pointer or false on error
     */
    public function tell();

    /**
     * Get the size of the stream if able
     *
     * @return int|bool
     */
    public function getSize();
}
