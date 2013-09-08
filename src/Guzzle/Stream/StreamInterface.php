<?php

namespace Guzzle\Stream;

/**
 * Base stream interface
 */
interface StreamInterface
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
     * Close the stream
     */
    public function close();

    /**
     * Separate the underlying raw stream from the Stream.
     *
     * After the raw stream has been detached, the buffer is in an unusable state.
     */
    public function detach();

    /**
     * Get the size of the stream if known
     *
     * @return int|null Returns the size in bytes if known, or null if unknown
     */
    public function getSize();

    /**
     * Returns the current position of the file read/write pointer
     *
     * @return int|bool Returns the position of the file pointer or false on error
     */
    public function tell();

    /**
     * Returns true if the stream is at the end of the stream.
     *
     * @return bool
     */
    public function eof();

    /**
     * Returns whether or not the stream is seekable
     *
     * @return bool
     */
    public function isSeekable();

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
     * Returns whether or not the stream is writable
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

    /**
     * Returns whether or not the stream is readable
     *
     * @return bool
     */
    public function isReadable();

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
     * Get stream metadata as an associative array or retrieve a specific key.
     *
     * Stream metadata should mimic PHP's stream_get_meta_data when
     * appropriate. For example:
     * - stream_type (string) - a label describing the underlying implementation of
     *   the stream.
     * - wrapper_type (string) - a label describing the protocol wrapper
     *   implementation layered over the stream.
     * - wrapper_data (mixed) - wrapper specific data attached to this stream.
     * - filters (array) - and array containing the names of any filters that have
     *   been stacked onto this stream.
     * - mode (string) - the type of access required for this stream
     * - seekable (bool) - whether the current stream can be seeked.
     * - uri (string) - the URI/filename associated with this stream.
     *
     * @param string $key Specific metadata to retrieve.
     *
     * @return array|mixed|null Returns an associative array if no key is
     *                          no key is provided. Returns a specific key
     *                          value if a key is provided and the value is
     *                          found, or null if the key is not found.
     * @see http://php.net/manual/en/function.stream-get-meta-data.php
     */
    public function getMetadata($key = null);
}
