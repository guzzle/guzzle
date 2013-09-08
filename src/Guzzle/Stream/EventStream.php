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
        $event = ['stream' => $this, 'length' => $length, 'data' => $this->stream->read($length)];
        $this->getEventDispatcher()->dispatch('stream.read', $event);

        if ($this->eof()) {
            $this->getEventDispatcher()->dispatch('stream.eof', ['stream' => $this]);
        }

        return $event['data'];
    }

    public function write($string)
    {
        $event = ['stream' => $this, 'length' => $this->stream->write($string), 'data' => $string];
        $this->getEventDispatcher()->dispatch('stream.write', $event);

        return $event['length'];
    }
}
