<?php

namespace Guzzle\Stream;

interface StreamInterface
{
    /**
     * Convert the stream to a string if the stream is readable and the stream is seekable.
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
     * Set stream metadata
     *
     * @param string $key   Metadata name to set
     * @param mixed  $value Value to set
     *
     * @return self
     */
    public function setMetadata($key, $value);

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

    /**
     * Get the URI/filename associated with this stream
     *
     * @return string
     */
    public function getUri();

    /**
     * Get the size of the stream if able
     *
     * @return int|bool
     */
    public function getSize();

    /**
     * Returns true if the stream is at the end of the stream
     *
     * @return bool
     */
    public function feof();

    /**
     * Returns the current position of the file read/write pointer
     *
     * @return int|bool Returns the position of the file pointer or false on error
     */
    public function ftell();

    /**
     * Check if the stream is readable
     *
     * @return bool
     */
    public function isReadable();

    /**
     * Check if the stream is writable
     *
     * @return bool
     */
    public function isWritable();

    /**
     * Check if the stream is a local stream vs a remote stream
     *
     * @return bool
     */
    public function isLocal();

    /**
     * Check if the string is repeatable
     *
     * @return bool
     */
    public function isSeekable();

    /**
     * Specify the size of the stream in bytes
     *
     * @param int $size Size of the stream contents in bytes
     *
     * @return self
     */
    public function setSize($size);

    /**
     * Seek to a position in the stream
     *
     * @param int $offset Stream offset
     * @param int $whence Where the offset is applied
     *
     * @return bool Returns TRUE on success or FALSE on failure
     * @link   http://www.php.net/manual/en/function.fseek.php
     */
    public function seek($offset, $whence = SEEK_SET);

    /**
     * Rewind to the beginning of the stream
     *
     * @return bool Returns true on success or false on failure
     */
    public function rewind();

    /**
     * Read data from the stream
     *
     * @param int $length Up to length number of bytes read.
     *
     * @return string|bool Returns the data read from the stream or FALSE on failure or EOF
     */
    public function read($length);

    /**
     * Read a line from the stream up to the maximum allowed buffer length
     *
     * @param int $maxLength Maximum buffer length
     *
     * @return string|bool
     */
    public function readLine($maxLength = null);

    /**
     * Write data to the stream
     *
     * @param string $string The string that is to be written.
     *
     * @return int|bool Returns the number of bytes written to the stream on success or FALSE on failure.
     */
    public function write($string);
}
