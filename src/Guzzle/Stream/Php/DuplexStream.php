<?php

namespace Guzzle\Stream\Php;

use Guzzle\Stream\DuplexStreamInterface;

/**
 * Stream implementation that can be written to and read from.
 */
class DuplexStream implements DuplexStreamInterface
{
    use StreamTrait, ReadableTrait, WritableTrait;
}
