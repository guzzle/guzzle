<?php

namespace Guzzle\Common;

use Guzzle\Common\Exception\InvalidArgumentException;

/**
 * OO interface to PHP streams
 */
class Stream
{
    const STREAM_TYPE = 'stream_type';
    const WRAPPER_TYPE = 'wrapper_type';
    const IS_LOCAL = 'is_local';
    const IS_READABLE = 'is_readable';
    const IS_WRITABLE = 'is_writable';
    const SEEKABLE = 'seekable';

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
     * @var array Hash table of readable and writeable stream types for fast lookups
     */
    protected static $readWriteHash = array(
        'read' => array(
            'r' => true, 'w+' => true, 'r+' => true, 'x+' => true, 'c+', 'x+' => true,
            'rb' => true, 'w+b' => true, 'r+b' => true, 'x+b' => true, 'c+b', 'x+' => true,
            'rt' => true, 'w+t' => true, 'r+t' => true, 'x+t' => true, 'c+t', 'x+' => true
        ),
        'write' => array(
            'w' => true, 'w+' => true, 'rw' => true, 'r+' => true, 'x+' => true, 'c+', 'x+' => true,
            'w+b' => true, 'r+b' => true, 'x+b' => true, 'c+b', 'x+' => true,
            'w+t' => true, 'r+t' => true, 'x+t' => true, 'c+t', 'x+' => true
        )
    );

    /**
     * Construct a new Stream
     *
     * @param resource $stream Stream resource to wrap
     * @param int      $size   Size of the stream in bytes.  Only pass this
     *                         parameter if the size cannot be obtained from
     *                         the stream.
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
        $this->cache[self::STREAM_TYPE] = strtolower($this->cache[self::STREAM_TYPE]);
        $this->cache[self::WRAPPER_TYPE] = strtolower($this->cache[self::WRAPPER_TYPE]);
        $this->cache[self::IS_LOCAL] = stream_is_local($this->stream);
        $this->cache[self::IS_READABLE] = isset(self::$readWriteHash['read'][$this->cache['mode']]);
        $this->cache[self::IS_WRITABLE] = isset(self::$readWriteHash['write'][$this->cache['mode']]);
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
     * @param string $key Specific metdata to retrieve
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
        return $this->cache[self::WRAPPER_TYPE];
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
        return $this->cache[self::STREAM_TYPE];
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
        if (!$this->cache[self::IS_READABLE] || !$this->cache[self::SEEKABLE]) {
            return false;
        } else {
            $this->size = strlen((string) $this);
            $this->seek(0);
            return $this->size;
        }
    }

    /**
     * Check if the stream is readable
     *
     * @return bool
     */
    public function isReadable()
    {
        return $this->cache[self::IS_READABLE];
    }

    /**
     * Check if the stream is writable
     *
     * @return bool
     */
    public function isWritable()
    {
        return $this->cache[self::IS_WRITABLE];
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
        return $this->cache[self::IS_LOCAL];
    }

    /**
     * Check if the string is repeatable
     *
     * @return bool
     */
    public function isSeekable()
    {
        return $this->cache[self::SEEKABLE];
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
     * @param int $whence Where the offset is applied
     *
     * @return bool Returns TRUE on success or FALSE on failure
     * @link http://www.php.net/manual/en/function.fseek.php
     */
    public function seek($offset, $whence = SEEK_SET)
    {
        return $this->cache[self::SEEKABLE] ? fseek($this->stream, $offset, $whence) === 0 : false;
    }

    /**
     * Read data from the stream
     *
     * @param int $length Up to length number of bytes read.
     *
     * @return string|bool Returns the data read from the stream or FALSE on
     *                     failure or EOF
     */
    public function read($length)
    {
        return $this->cache[self::IS_READABLE] ? fread($this->stream, $length) : false;
    }

    /**
     * Write data to the stream
     *
     * @param string $string The string that is to be written.
     *
     * @return int|bool Returns the number of bytes written to the stream on
     *                  success or FALSE on failure.
     */
    public function write($string)
    {
        if (!$this->cache[self::IS_WRITABLE]) {
            return 0;
        }

        $bytes = fwrite($this->stream, $string);
        $this->size += $bytes;

        return $bytes;
    }
}
