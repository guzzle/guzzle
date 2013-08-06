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
        $buffer = '';
        if ($this->rewind()) {
            while (!$this->eof()) {
                $buffer .= $this->read(32768);
            }
            $this->rewind();
        }

        return $buffer;
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

    public function getUri()
    {
        return $this->stream->getUri();
    }

    public function getSize()
    {
        return $this->stream->getSize();
    }

    public function eof()
    {
        return $this->stream->eof();
    }

    public function tell()
    {
        return $this->stream->tell();
    }

    public function isReadable()
    {
        return $this->stream->isReadable();
    }

    public function isWritable()
    {
        return $this->stream->isWritable();
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
        $buffer = '';
        $size = 0;
        while (!$this->eof() && strlen($buffer) < $maxLength) {
            if (false === ($byte = $this->read(1))) {
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

    public function write($string)
    {
        return $this->stream->write($string);
    }
}
