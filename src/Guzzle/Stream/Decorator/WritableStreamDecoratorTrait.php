<?php

namespace Guzzle\Stream\Decorator;

/**
 * Stream decorator trait implementing {@see \Guzzle\Stream\WritableStreamInterface}
 */
trait WritableStreamDecoratorTrait
{
    use StreamDecoratorTrait;

    /**
     * @see \Guzzle\Stream\WritableStreamInterface::write
     */
    public function write($string)
    {
        return $this->stream->write($string);
    }
}
