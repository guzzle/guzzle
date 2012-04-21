<?php

namespace Guzzle\Common;

use Guzzle\Common\Exception\InvalidArgumentException;

/**
 * OO interface to PHP streams
 */
class Stream
{
    /**
     * @var resource Stream resource
     */
    protected $stream;

    /**
     * @var int Size of the stream contents in bytes
     */
    protected $size;

    /**
     * @var array Stream cached data
     */
    protected $cache = array();

    /**
     * Construct a new Stream
     *
     * @param resource $stream Stream resource to wrap
     * @param int $size (optional) Size of the stream in bytes.  Only pass this
     *      parameter if the size cannot be obtained from the stream.
     *
     * @throws InvalidArgumentException if the stream is not a stream resource
     */
    public function __construct($stream, $size = null)
    {
        if (!is_resource($stream)) {
            throw new InvalidArgumentException('Stream must be a resource');
        }

        $this->size = $size;
        $this->stream = $stream;
        $this->rebuildCache();
    }

    /**
     * Closes the stream when the helper is destructed
     */
    public function __destruct()
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    /**
     * Reprocess stream metadata
     */
    protected function rebuildCache()
    {
        $this->cache = stream_get_meta_data($this->stream);
        $this->cache['stream_type'] = strtolower($this->cache['stream_type']);
        $this->cache['wrapper_type'] = strtolower($this->cache['wrapper_type']);
        $this->cache['is_local'] = stream_is_local($this->stream);
        $this->cache['is_readable'] = in_array(str_replace('b', '', $this->cache['mode']), array('r', 'w+', 'r+', 'x+', 'c+'));
        $this->cache['is_writable'] = str_replace('b', '', $this->cache['mode']) != 'r';
    }

    /**
     * Convert the stream to a string if the stream is readable and the stream
     * is seekable.  This logic is enforced to ensure that outputting the stream
     * as a string does not affect an actual cURL request using non-repeatable
     * streams.
     *
     * @return string
     */
    public function __toString()
    {
        if (!$this->isReadable() || (!$this->isSeekable() && $this->isConsumed())) {
            return '';
        }

        $body = stream_get_contents($this->stream, -1, 0);
        $this->seek(0);

        return $body;
    }

    /**
     * Get stream metadata
     *
     * @param string $key (optional) Specific metdata to retrieve
     *
     * @return array|mixed|null
     */
    public function getMetaData($key = null)
    {
        $meta = stream_get_meta_data($this->stream);

        return !$key ? $meta : (array_key_exists($key, $meta) ? $meta[$key] : null);
    }

    /**
     * Get the stream resource
     *
     * @return resource
     */
    public function getStream()
    {
        return $this->stream;
    }

    /**
     * Get the stream wrapper type
     *
     * @return string
     */
    public function getWrapper()
    {
        return $this->cache['wrapper_type'];
    }

    /**
     * Wrapper specific data attached to this stream.
     *
     * @return string
     */
    public function getWrapperData()
    {
        return $this->getMetaData('wrapper_data') ?: array();
    }

    /**
     * Get a label describing the underlying implementation of the stream
     *
     * @return string
     */
    public function getStreamType()
    {
        return $this->cache['stream_type'];
    }

    /**
     * Get the URI/filename associated with this stream
     *
     * @return string
     */
    public function getUri()
    {
        return $this->cache['uri'];
    }

    /**
     * Get the size of the stream if able
     *
     * @return int|false
     */
    public function getSize()
    {
        if ($this->size !== null) {
            return $this->size;
        }

        // If the stream is a file based stream and local, then check the filesize
        if ($this->isLocal() && $this->getWrapper() == 'plainfile' && $this->getUri() && file_exists($this->getUri())) {
            return filesize($this->getUri());
        }

        // Only get the size based on the content if the the stream is readable
        // and seekable so as to not interfere with actually reading the data
        if (!$this->isReadable() || !$this->isSeekable()) {
            return false;
        } else {
            $size = strlen((string) $this);
            $this->seek(0);
            return $size;
        }
    }

    /**
     * Check if the stream is readable
     *
     * @return bool
     */
    public function isReadable()
    {
        return $this->cache['is_readable'];
    }

    /**
     * Check if the stream is writable
     *
     * @return bool
     */
    public function isWritable()
    {
        return $this->cache['is_writable'];
    }

    /**
     * Check if the stream has been consumed
     *
     * @return bool
     */
    public function isConsumed()
    {
        return feof($this->stream);
    }

    /**
     * Check if the stream is a local stream vs a remote stream
     *
     * @return bool
     */
    public function isLocal()
    {
        return $this->cache['is_local'];
    }

    /**
     * Check if the string is repeatable
     *
     * @return bool
     */
    public function isSeekable()
    {
        return $this->cache['seekable'];
    }

    /**
     * Specify the size of the stream in bytes
     *
     * @param int $size Size of the stream contents in bytes
     *
     * @return Stream
     */
    public function setSize($size)
    {
        $this->size = $size;

        return $this;
    }

    /**
     * Seek to a position in the stream
     *
     * @param int $offset Stream offset
     * @param int $whence (optional) Where the offset is applied
     *
     * @return bool Returns TRUE on success or FALSE on failure
     * @see http://www.php.net/manual/en/function.fseek.php
     */
    public function seek($offset, $whence = SEEK_SET)
    {
        return $this->isSeekable() ? fseek($this->stream, $offset, $whence) === 0 : false;
    }

    /**
     * Read data from the stream
     *
     * @param int $length Up to length number of bytes read.
     *
     * @return string|bool Returns the data read from the stream or FALSE on
     *      failure or EOF
     */
    public function read($length)
    {
        return $this->isReadable() ? fread($this->stream, $length) : false;
    }

    /**
     * Write data to the stream
     *
     * @param string $string The string that is to be written.
     *
     * @return int|bool Returns the number of bytes written to the stream on
     *      success or FALSE on failure.
     */
    public function write($string)
    {
        return $this->isWritable() ? fwrite($this->stream, $string) : false;
    }
}
