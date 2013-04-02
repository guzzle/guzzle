<?php

namespace Guzzle\Http;

use Guzzle\Common\Exception\RuntimeException;

/**
 * EntityBody decorator that can cache previously read bytes from a sequentially read tstream
 */
class CachingEntityBody extends AbstractEntityBodyDecorator
{
    /**
     * @var EntityBody Remote stream used to actually pull data onto the buffer
     */
    protected $remoteStream;

    /**
     * We will treat the buffer object as the body of the entity body
     * {@inheritdoc}
     */
    public function __construct(EntityBodyInterface $body)
    {
        $this->remoteStream = $body;
        $this->body = new EntityBody(fopen('php://temp', 'r+'));
        // Use the specifically set size of the remote stream if it is available
        $remoteSize = $this->remoteStream->getSize();
        if (false !== $remoteSize) {
            $this->body->setSize($remoteSize);
        }
    }

    /**
     * {@inheritdoc}
     * @throws RuntimeException When seeking with SEEK_END or when seeking past the total size of the buffer stream
     */
    public function seek($offset, $whence = SEEK_SET)
    {
        if ($whence == SEEK_SET) {
            $byte = $offset;
        } elseif ($whence == SEEK_CUR) {
            $byte = $offset + $this->ftell();
        } else {
            throw new RuntimeException(__CLASS__ . ' supports only SEEK_SET and SEEK_CUR seek operations');
        }

        // You cannot skip ahead past where you've read from the remote stream
        if ($byte > $this->getSize()) {
            throw new RuntimeException(
                "Cannot seek to byte {$byte} when the buffered stream only contains {$this->getSize()} bytes"
            );
        }

        return $this->body->seek($offset);
    }

    /**
     * {@inheritdoc}
     */
    public function rewind()
    {
        return $this->seek(0);
    }

    /**
     * Does not support custom rewind functions
     *
     * @throws RuntimeException
     */
    public function setRewindFunction($callable)
    {
        throw new RuntimeException(__CLASS__ . ' does not support custom stream rewind functions');
    }

    /**
     * {@inheritdoc}
     */
    public function read($length)
    {
        $data = '';
        $remaining = $length;

        if ($this->ftell() < $this->body->getSize()) {
            $data = $this->body->read($length);
            $remaining -= strlen($data);
        }

        if ($remaining) {
            $remoteData = $this->remoteStream->read($remaining);
            $data .= $remoteData;
            $this->body->write($remoteData);
        }

        return $data;
    }

    /**
     * {@inheritdoc}
     */
    public function write($string)
    {
        return $this->body->write($string);
    }

    /**
     * {@inheritdoc}
     */
    public function readLine($maxLength = null)
    {
        return $this->body->readLine($maxLength);
    }

    /**
     * Close both the remote stream and buffer stream
     */
    public function close()
    {
        return $this->remoteStream->close() && parent::close();
    }

    /**
     * You cannot change the underlying stream of a buffered stream
     *
     * @throws RuntimeException
     */
    public function setStream($stream, $size = 0)
    {
        throw new RuntimeException('Cannot change the underlying stream of a CachingEntityBody');
    }

    /**
     * {@inheritdoc}
     */
    public function getContentType()
    {
        return $this->remoteStream->getContentType();
    }

    /**
     * {@inheritdoc}
     */
    public function getContentEncoding()
    {
        return $this->remoteStream->getContentEncoding();
    }

    /**
     * {@inheritdoc}
     */
    public function getMetaData($key = null)
    {
        return $this->remoteStream->getMetaData($key);
    }

    /**
     * {@inheritdoc}
     */
    public function getStream()
    {
        return $this->remoteStream->getStream();
    }

    /**
     * {@inheritdoc}
     */
    public function getWrapper()
    {
        return $this->remoteStream->getWrapper();
    }

    /**
     * {@inheritdoc}
     */
    public function getWrapperData()
    {
        return $this->remoteStream->getWrapperData();
    }

    /**
     * {@inheritdoc}
     */
    public function getStreamType()
    {
        return $this->remoteStream->getStreamType();
    }

    /**
     * {@inheritdoc}
     */
    public function getUri()
    {
        return $this->remoteStream->getUri();
    }

    /**
     * Always retrieve custom data from the remote stream
     *
     * {@inheritdoc}
     */
    public function getCustomData($key)
    {
        return $this->remoteStream->getCustomData($key);
    }

    /**
     * Always set custom data on the remote stream
     *
     * {@inheritdoc}
     */
    public function setCustomData($key, $value)
    {
        return $this->remoteStream->setCustomData($key, $value);
    }
}
