<?php

namespace Guzzle\Stream;

use Guzzle\Common\HasDispatcherTrait;
use Guzzle\Common\HasDispatcherInterface;

/**
 * Stream decorator that emits events for read and write methods
 */
class EventStream implements StreamInterface, HasDispatcherInterface
{
    use StreamDecoratorTrait, HasDispatcherTrait;

    public function read($length)
    {
        $data = $this->stream->read($length);
        $this->getEventDispatcher()->dispatch(
            'stream.read',
            new IoEvent($this, $data, $length)
        );

        if ($this->eof()) {
            $this->getEventDispatcher()->dispatch(
                'stream.eof',
                new IoEvent($this)
            );
        }

        return $data;
    }

    public function write($string)
    {
        $length = $this->stream->write($string);
        $this->getEventDispatcher()->dispatch(
            'stream.write',
            new IoEvent($this, $string, $length)
        );

        return $length;
    }
}
