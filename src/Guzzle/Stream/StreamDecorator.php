<?php

namespace Guzzle\Stream;

/**
 * Stream decorator trait
 */
trait StreamDecorator
{
    /** @var StreamInterface Decorated stream */
    protected $stream;

    /**
     * @param StreamInterface $stream Stream to decorate
     */
    public function __construct(StreamInterface $stream)
    {
        $this->stream = $stream;
    }

    public function __toString()
    {
        return (string) $this->stream;
    }

    /**
     * Allow decorators to implement custom methods
     *
     * @param string $method Missing method name
     * @param array  $args   Method arguments
     *
     * @return mixed
     */
    public function __call($method, array $args)
    {
        return call_user_func_array(array($this->stream, $method), $args);
    }

    public function close()
    {
        return $this->stream->close();
    }

    public function getMetadata($key = null)
    {
        return $this->stream->getMetadata($key);
    }

    public function setMetadata($key, $value)
    {
        $this->stream->setMetadata($key, $value);

        return $this;
    }

    public function getStream()
    {
        return $this->stream->getStream();
    }

    public function detachStream()
    {
        $this->stream->detachStream();

        return $this;
    }

    public function getWrapper()
    {
        return $this->stream->getWrapper();
    }

    public function getWrapperData()
    {
        return $this->stream->getWrapperData();
    }

    public function getStreamType()
    {
        return $this->stream->getStreamType();
    }

    public function getUri()
    {
        return $this->stream->getUri();
    }

    public function getSize()
    {
        return $this->stream->getSize();
    }

    public function feof()
    {
        return $this->stream->feof();
    }

    public function ftell()
    {
        return $this->stream->ftell();
    }

    public function isReadable()
    {
        return $this->stream->isReadable();
    }

    public function isWritable()
    {
        return $this->stream->isWritable();
    }

    public function isLocal()
    {
        return $this->stream->isLocal();
    }

    public function isSeekable()
    {
        return $this->stream->isSeekable();
    }

    public function setSize($size)
    {
        $this->stream->setSize($size);

        return $this;
    }

    public function seek($offset, $whence = SEEK_SET)
    {
        return $this->stream->seek($offset, $whence);
    }

    public function rewind()
    {
        return $this->stream->rewind();
    }

    public function read($length)
    {
        return $this->stream->read($length);
    }

    public function readLine($maxLength = null)
    {
        return $this->stream->readLine($maxLength);
    }

    public function write($string)
    {
        return $this->stream->write($string);
    }
}
