<?php

namespace Guzzle\Http;

use Guzzle\Common\Exception\RuntimeException;

/**
 * EntityBody decorator used to return only a subset of an entity body
 */
class ReadLimitEntityBody extends AbstractEntityBodyDecorator
{
    /**
     * @var int Limit the number of bytes that can be read
     */
    protected $limit;

    /**
     * @var int Offset to start reading from
     */
    protected $offset;

    /**
     * @param int $limit  Total number of bytes to allow to be read from the stream
     * @param int $offset Position to seek to before reading (only works on seekable streams)
     */
    public function __construct(EntityBodyInterface $body, $limit, $offset = 0)
    {
        parent::__construct($body);
        $this->setLimit($limit)->setOffset($offset);
        $this->body->seek($offset);
    }

    /**
     * {@inheritdoc}
     */
    public function isConsumed()
    {
        return (($this->offset + $this->limit) - $this->body->ftell()) <= 0;
    }

    /**
     * {@inheritdoc}
     */
    public function isSeekable()
    {
        return false;
    }

    /**
     * {@inheritdoc}
     * @throws RuntimeException
     */
    public function seek($offset, $whence = SEEK_SET)
    {
        throw new RuntimeException('Cannot call seek when using a ReadLimitEntityBody decorator');
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
        $this->body->seek($offset);
        $this->offset = $offset;

        return $this;
    }

    /**
     * Set the limit of bytes that the decorator allows to be read from the stream
     *
     * @param int $limit Total number of bytes to allow to be read from the stream
     *
     * @return self
     */
    public function setLimit($limit)
    {
        $this->limit = $limit;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function read($length)
    {
        // Check if the current position is less than the total allowed bytes + original offset
        $remaining = ($this->offset + $this->limit) - $this->body->ftell();
        if ($remaining > 0) {
            // Only return the amount of requested data, ensuring that the byte limit is not exceeded
            return $this->body->read(min($remaining, $length));
        } else {
            return false;
        }
    }
}
