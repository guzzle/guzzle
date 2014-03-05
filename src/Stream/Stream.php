<?php

namespace GuzzleHttp\Stream;

/**
 * PHP stream implementation
 */
class Stream implements MetadataStreamInterface
{
    /** @var resource Stream resource */
    private $stream;

    /** @var int Size of the stream contents in bytes */
    private $size;

    /** @var bool */
    private $seekable;
    private $readable;
    private $writable;

    /** @var array Stream metadata */
    private $meta = [];

    /** @var array Hash table of readable and writeable stream types for fast lookups */
    private static $readWriteHash = array(
        'read' => array(
            'r' => true, 'w+' => true, 'r+' => true, 'x+' => true, 'c+' => true,
            'rb' => true, 'w+b' => true, 'r+b' => true, 'x+b' => true, 'c+b' => true,
            'rt' => true, 'w+t' => true, 'r+t' => true, 'x+t' => true, 'c+t' => true, 'a+' => true
        ),
        'write' => array(
            'w' => true, 'w+' => true, 'rw' => true, 'r+' => true, 'x+' => true, 'c+' => true,
            'wb' => true, 'w+b' => true, 'r+b' => true, 'x+b' => true, 'c+b' => true,
            'w+t' => true, 'r+t' => true, 'x+t' => true, 'c+t' => true, 'a' => true, 'a+' => true
        )
    );

    /**
     * Create a new stream based on the input type
     *
     * @param resource|string|StreamInterface $resource Entity body data
     * @param int                             $size     Size of the data contained in the resource
     *
     * @return StreamInterface
     * @throws \InvalidArgumentException if the $resource arg is not a resource or string
     */
    public static function factory($resource = '', $size = null)
    {
        switch (gettype($resource)) {
            case 'string':
                return self::fromString($resource);
            case 'resource':
                $result = new static($resource, $size);
                break;
            case 'object':
                if ($resource instanceof StreamInterface) {
                    $result = $resource;
                    break;
                } elseif (method_exists($resource, '__toString')) {
                    return self::fromString((string) $resource);
                }
            default:
                throw new \InvalidArgumentException('Invalid resource type');
        }

        return $result;
    }

    /**
     * Create a new stream from a string
     *
     * @param string $string String of data
     *
     * @return StreamInterface
     */
    public static function fromString($string)
    {
        $stream = fopen('php://temp', 'r+');
        if ($string !== '') {
            fwrite($stream, $string);
            fseek($stream, 0);
        }

        return new static($stream);
    }

    /**
     * @param resource $stream Stream resource to wrap
     * @param int      $size   Size of the stream in bytes. Only pass if the size cannot be obtained from the stream.
     *
     * @throws \InvalidArgumentException if the stream is not a stream resource
     */
    public function __construct($stream, $size = null)
    {
        if (!is_resource($stream)) {
            throw new \InvalidArgumentException('Stream must be a resource');
        }

        $this->size = $size;
        $this->stream = $stream;
        $this->meta = stream_get_meta_data($this->stream);
        $this->seekable = $this->meta['seekable'];
        $this->readable = isset(self::$readWriteHash['read'][$this->meta['mode']]);
        $this->writable = isset(self::$readWriteHash['write'][$this->meta['mode']]);
    }

    /**
     * Closes the stream when the destructed
     */
    public function __destruct()
    {
        $this->close();
    }

    public function __toString()
    {
        $this->seek(0);

        return (string) stream_get_contents($this->stream);
    }

    public function getContents($maxLength = -1)
    {
        return stream_get_contents($this->stream, $maxLength);
    }

    public function close()
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
        $this->meta = [];
        $this->stream = null;
    }

    public function detach()
    {
        $this->stream = null;
    }

    public function getSize()
    {
        if ($this->size !== null) {
            return $this->size;
        }

        // If the stream is a file based stream and local, then use fstat
        clearstatcache(true, $this->meta['uri']);
        $stats = fstat($this->stream);
        if (isset($stats['size'])) {
            $this->size = $stats['size'];
            return $this->size;
        }

        return null;
    }

    public function isReadable()
    {
        return $this->stream && $this->readable;
    }

    public function isWritable()
    {
        return $this->stream && $this->writable;
    }

    public function isSeekable()
    {
        return $this->stream && $this->seekable;
    }

    public function eof()
    {
        return feof($this->stream);
    }

    public function tell()
    {
        return ftell($this->stream);
    }

    public function setSize($size)
    {
        $this->size = $size;

        return $this;
    }

    public function seek($offset, $whence = SEEK_SET)
    {
        return $this->seekable ? fseek($this->stream, $offset, $whence) === 0 : false;
    }

    public function read($length)
    {
        return fread($this->stream, $length);
    }

    public function write($string)
    {
        // We can't know the size after writing anything
        $this->size = null;

        return fwrite($this->stream, $string);
    }

    /**
     * Get stream metadata as an associative array or retrieve a specific key.
     *
     * The keys returned are identical to the keys returned from PHP's
     * stream_get_meta_data() function.
     *
     * @param string $key Specific metadata to retrieve.
     *
     * @return array|mixed|null Returns an associative array if no key is
     *                          no key is provided. Returns a specific key
     *                          value if a key is provided and the value is
     *                          found, or null if the key is not found.
     * @see http://php.net/manual/en/function.stream-get-meta-data.php
     */
    public function getMetadata($key = null)
    {
        return !$key ? $this->meta : (isset($this->meta[$key]) ? $this->meta[$key] : null);
    }

    /**
     * Calculate a hash of a Stream
     *
     * @param StreamInterface $stream    Stream to calculate the hash for
     * @param string          $algo      Hash algorithm (e.g. md5, crc32, etc)
     * @param bool            $rawOutput Whether or not to use raw output
     *
     * @return bool|string Returns false on failure or a hash string on success
     */
    public static function getHash(
        StreamInterface $stream,
        $algo,
        $rawOutput = false
    ) {
        $pos = $stream->tell();
        if (!$stream->seek(0)) {
            return false;
        }

        $ctx = hash_init($algo);
        while ($data = $stream->read(1048576)) {
            hash_update($ctx, $data);
        }

        $out = hash_final($ctx, (bool) $rawOutput);
        $stream->seek($pos);

        return $out;
    }

    /**
     * Read a line from the stream up to the maximum allowed buffer length
     *
     * @param StreamInterface $stream    Stream to read from
     * @param int             $maxLength Maximum buffer length
     *
     * @return string|bool
     */
    public static function readLine(StreamInterface $stream, $maxLength = null)
    {
        $buffer = '';
        $size = 0;

        while (!$stream->eof()) {
            if (false === ($byte = $stream->read(1))) {
                return $buffer;
            }
            $buffer .= $byte;
            // Break when a new line is found or the max length - 1 is reached
            if ($byte == PHP_EOL || ++$size == $maxLength - 1) {
                break;
            }
        }

        return $buffer;
    }
}
