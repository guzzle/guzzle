<?php

namespace Guzzle\Stream;

/**
 * Decorator used to return only a subset of a stream
 */
class LimitStream implements StreamInterface
{
    use StreamDecorator;

    /** @var int Offset to start reading from */
    protected $offset;

    /** @var int Limit the number of bytes that can be read */
    protected $limit;

    /**
     * @param StreamInterface $stream Stream to wrap
     * @param int             $offset Position to seek to before reading (only works on seekable streams)
     * @param int             $limit  Total number of bytes to allow to be read from the stream. Pass -1 for no limit.
     */
    public function __construct(StreamInterface $stream, $offset = 0, $limit = -1)
    {
        $this->stream = $stream;
        $this->setOffset($offset);
        $this->setLimit($limit);
    }

    /**
     * Returns only a subset of the decorated stream when cast as a string
     * {@inheritdoc}
     */
    public function __toString()
    {
        return substr((string) $this->stream, $this->offset, $this->limit) ?: '';
    }

    public function feof()
    {
        if ($this->limit == -1) {
            return $this->stream->feof();
        } else {
            return (($this->offset + $this->limit) - $this->stream->ftell()) <= 0;
        }
    }

    /**
     * Returns the size of the limited subset of data
     * {@inheritdoc}
     */
    public function getSize()
    {
        if (false === ($length = $this->stream->getSize())) {
            return false;
        } elseif ($this->limit == -1) {
            return $length - $this->offset;
        } else {
            return min($this->limit, $length - $this->offset);
        }
    }

    /**
     * Allow for a bounded seek on the read limited stream
     * {@inheritdoc}
     */
    public function seek($offset, $whence = SEEK_SET)
    {
        if ($whence != SEEK_SET) {
            return false;
        } elseif ($this->limit == -1) {
            return $offset == 0 ? $this->stream->seek(0) : false;
        } else {
            return $this->stream->seek(max($this->offset, min($this->offset + $this->limit, $offset)));
        }
    }

    /**
     * Set the offset to start limiting from
     *
     * @param int $offset Offset to seek to and begin byte limiting from
     *
     * @return self
     */
    public function setOffset($offset)
    {
        $this->offset = $offset;
        $current = $this->stream->ftell();
        if ($current !== $offset) {
            // If the stream cannot seek to the offset position, then read to it
            if (!$this->stream->seek($offset)) {
                if ($current > $offset) {
                    throw new \RuntimeException("Cannot seek to stream offset {$offset}");
                } else {
                    $this->stream->read($offset - $current);
                }
            }
        }

        return $this;
    }

    /**
     * Set the limit of bytes that the decorator allows to be read from the stream
     *
     * @param int $limit Number of bytes to allow to be read from the stream. Use -1 for no limit.
     *
     * @return self
     */
    public function setLimit($limit)
    {
        $this->limit = $limit;

        return $this;
    }

    public function read($length)
    {
        if ($this->limit == -1) {
            return $this->stream->read($length);
        }

        // Check if the current position is less than the total allowed bytes + original offset
        $remaining = ($this->offset + $this->limit) - $this->stream->ftell();
        if ($remaining > 0) {
            // Only return the amount of requested data, ensuring that the byte limit is not exceeded
            return $this->stream->read(min($remaining, $length));
        } else {
            return false;
        }
    }
}
