<?php

namespace Guzzle\Stream;

/**
 * Stream decorator trait
 */
trait StreamDecoratorTrait
{
    /** @var StreamInterface Decorated stream */
    private $stream;

    /**
     * @param StreamInterface $stream Stream to decorate
     */
    public function __construct(StreamInterface $stream)
    {
        $this->stream = $stream;
    }

    public function __toString()
    {
        try {
            $this->seek(0);
            return $this->getContents();
        } catch (\Exception $e) {
            // Really, PHP? https://bugs.php.net/bug.php?id=53648
            trigger_error('StreamDecorator::__toString exception: ' . (string) $e, E_USER_ERROR);
            return '';
        }
    }

    public function getContents($maxLength = -1)
    {
        $buffer = '';
        if ($maxLength == 0) {
            return $buffer;
        }

        while (!$this->eof()) {
            if ($maxLength == -1) {
                $buffer .= $this->read(32768);
            } elseif (strlen($buffer) < $maxLength) {
                $buffer .= $this->read(max(1, min($maxLength, $maxLength - strlen($buffer))));
            } else {
                break;
            }
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
        $result = call_user_func_array(array($this->stream, $method), $args);

        // Always return the wrapped object if the result is a return $this
        return $result === $this->stream ? $this : $result;
    }

    public function close()
    {
        return $this->stream->close();
    }

    public function getMetadata($key = null)
    {
        return $this->stream instanceof MetadataStreamInterface
            ? $this->stream->getMetadata($key)
            : null;
    }

    public function detach()
    {
        $this->stream->detach();

        return $this;
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

    public function seek($offset, $whence = SEEK_SET)
    {
        return $this->stream->seek($offset, $whence);
    }

    public function read($length)
    {
        return $this->stream->read($length);
    }

    public function write($string)
    {
        return $this->stream->write($string);
    }
}
