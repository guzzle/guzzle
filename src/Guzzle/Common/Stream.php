<?php

namespace Guzzle\Common;

use Guzzle\Common\Exception\InvalidArgumentException;

/**
 * PHP stream implementation
 */
class Stream implements StreamInterface
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
            'r' => true, 'w+' => true, 'r+' => true, 'x+' => true, 'c+' => true,
            'rb' => true, 'w+b' => true, 'r+b' => true, 'x+b' => true, 'c+b' => true,
            'rt' => true, 'w+t' => true, 'r+t' => true, 'x+t' => true, 'c+t' => true
        ),
        'write' => array(
            'w' => true, 'w+' => true, 'rw' => true, 'r+' => true, 'x+' => true, 'c+' => true,
            'w+b' => true, 'r+b' => true, 'x+b' => true, 'c+b' => true,
            'w+t' => true, 'r+t' => true, 'x+t' => true, 'c+t' => true
        )
    );

    /**
     * Construct a new Stream
     *
     * @param resource $stream Stream resource to wrap
     * @param int      $size   Size of the stream in bytes. Only pass if the size cannot be obtained from the stream.
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
     * {@inheritdoc}
     */
    public function __toString()
    {
        if (!$this->isReadable() || (!$this->isSeekable() && $this->isConsumed())) {
            return '';
        }

        $originalPos = $this->ftell();
        $body = stream_get_contents($this->stream, -1, 0);
        $this->seek($originalPos);

        return $body;
    }

    /**
     * {@inheritdoc}
     */
    public function getMetaData($key = null)
    {
        $meta = stream_get_meta_data($this->stream);

        return !$key ? $meta : (array_key_exists($key, $meta) ? $meta[$key] : null);
    }

    /**
     * {@inheritdoc}
     */
    public function getStream()
    {
        return $this->stream;
    }

    /**
     * {@inheritdoc}
     */
    public function getWrapper()
    {
        return $this->cache[self::WRAPPER_TYPE];
    }

    /**
     * {@inheritdoc}
     */
    public function getWrapperData()
    {
        return $this->getMetaData('wrapper_data') ?: array();
    }

    /**
     * {@inheritdoc}
     */
    public function getStreamType()
    {
        return $this->cache[self::STREAM_TYPE];
    }

    /**
     * {@inheritdoc}
     */
    public function getUri()
    {
        return $this->cache['uri'];
    }

    /**
     * {@inheritdoc}
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
            $pos = $this->ftell();
            $this->size = strlen((string) $this);
            $this->seek($pos);
            return $this->size;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isReadable()
    {
        return $this->cache[self::IS_READABLE];
    }

    /**
     * {@inheritdoc}
     */
    public function isWritable()
    {
        return $this->cache[self::IS_WRITABLE];
    }

    /**
     * {@inheritdoc}
     */
    public function isConsumed()
    {
        return feof($this->stream);
    }

    /**
     * {@inheritdoc}
     */
    public function isLocal()
    {
        return $this->cache[self::IS_LOCAL];
    }

    /**
     * {@inheritdoc}
     */
    public function isSeekable()
    {
        return $this->cache[self::SEEKABLE];
    }

    /**
     * {@inheritdoc}
     */
    public function setSize($size)
    {
        $this->size = $size;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function seek($offset, $whence = SEEK_SET)
    {
        return $this->cache[self::SEEKABLE] ? fseek($this->stream, $offset, $whence) === 0 : false;
    }

    /**
     * {@inheritdoc}
     */
    public function read($length)
    {
        return $this->cache[self::IS_READABLE] ? fread($this->stream, $length) : false;
    }

    /**
     * {@inheritdoc}
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

    /**
     * {@inheritdoc}
     */
    public function ftell()
    {
        return ftell($this->stream);
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
}
