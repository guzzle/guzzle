<?php

namespace Guzzle\Stream\Php;

use Guzzle\Stream\StreamMetadataTrait;

/**
 * Trait implementing StreamInterface {@see \Guzzle\Stream\StreamInterface}
 */
trait StreamTrait
{
    use StreamMetadataTrait;

    /** @var resource Stream resource */
    private $stream;

    /** @var int Size of the stream contents in bytes */
    private $size;

    /** @var bool Whether or not the stream is seekable */
    private $seekable;

    /**
     * @param resource $stream   Stream resource to wrap
     * @param int      $size     Size of the stream in bytes. Only pass if the size cannot be obtained from the stream.
     * @param array    $metadata Stream metadata
     *
     * @throws \InvalidArgumentException if the stream is not a stream resource
     */
    public function __construct($stream, $size = null, $metadata = [])
    {
        if (!is_resource($stream)) {
            throw new \InvalidArgumentException('Stream must be a resource');
        }

        $this->size = $size;
        $this->stream = $stream;
        $this->meta = $metadata;
    }

    /**
     * Closes the stream when the destructed
     */
    public function __destruct()
    {
        $this->close();
    }

    /**
     * @see \Guzzle\Stream\StreamInterface::close
     */
    public function close()
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
        $this->detach();
    }

    /**
     * @see \Guzzle\Stream\StreamInterface::detach
     */
    public function detach()
    {
        $this->meta = [];
        $this->stream = null;
    }

    /**
     * @see \Guzzle\Stream\StreamInterface::getSize
     */
    public function getSize()
    {
        if ($this->size === null) {
            clearstatcache(true, $this->meta['uri']);
            $stats = fstat($this->stream);
            if (isset($stats['size'])) {
                $this->size = $stats['size'];
            }
        }

        return $this->size;
    }

    /**
     * @see \Guzzle\Stream\StreamInterface::isSeekable
     */
    public function isSeekable()
    {
        return $this->seekable;
    }

    /**
     * @see \Guzzle\Stream\StreamInterface::tell
     */
    public function tell()
    {
        return ftell($this->stream);
    }

    /**
     * @see \Guzzle\Stream\StreamInterface::setSize
     */
    public function setSize($size)
    {
        $this->size = $size;

        return $this;
    }

    /**
     * @see \Guzzle\Stream\StreamInterface::seek
     */
    public function seek($offset, $whence = SEEK_SET)
    {
        return $this->seekable
            ? fseek($this->stream, $offset, $whence) === 0
            : false;
    }
}
