<?php

namespace Guzzle\Stream;

use Guzzle\Common\Event;

/**
 * Event emitted from EventStream
 */
class IoEvent extends Event
{
    /** @var \Guzzle\Stream\StreamInterface */
    public $stream;

    /** @var string Event data being read/written */
    public $data;

    /** @var int Length of the data that was read/written */
    public $length;

    /**
     * @param StreamInterface $stream Stream
     * @param string          $data   Data that is being read/written
     * @param int             $length Length of the data that was read/written
     */
    public function __construct(
        StreamInterface $stream,
        $data = '',
        $length = 0
    ) {
        $this->stream = $stream;
        $this->data = $data;
        $this->length = $length;
    }
}
