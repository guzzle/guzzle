<?php

namespace Guzzle\Stream;

/**
 * Base stream interface
 */
interface StreamInterface
{
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
     * Get stream metadata as an associative array or retrieve a specific key.
     *
     * Stream metadata should mimic PHP's stream_get_meta_data when appropriate.
     *
     * - timed_out (bool) - TRUE if the stream timed out while waiting for data on the
     *   last call to fread() or fgets().
     * - blocked (bool) - TRUE if the stream is in blocking IO mode. See
     *   stream_set_blocking().
     * - eof (bool) - TRUE if the stream has reached end-of-file.
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
