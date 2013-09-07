<?php

namespace Guzzle\Stream\Php;

/**
 * Trait implementing {@see \Guzzle\Stream\ReadableStreamInterface}
 */
trait ReadableTrait
{
    /**
     * @see \Guzzle\Stream\ReadableStreamInterface::__toString
     */
    public function __toString()
    {
        if (!$this->isSeekable() && $this->eof()) {
            return '';
        }

        $originalPos = $this->tell();
        $body = (string) stream_get_contents($this->stream, -1, 0);
        $this->seek($originalPos);

        return $body;
    }

    /**
     * @see \Guzzle\Stream\ReadableStreamInterface::eof
     */
    public function eof()
    {
        return feof($this->stream);
    }

    /**
     * @see \Guzzle\Stream\ReadableStreamInterface::read
     */
    public function read($length)
    {
        return fread($this->stream, $length);
    }
}
