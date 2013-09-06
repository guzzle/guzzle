<?php

namespace Guzzle\Stream\Php;

use Guzzle\Stream\ReadableStreamInterface;

/**
 * Stream implementation that implements ReadableStreamInterface
 */
class ReadableStream implements ReadableStreamInterface
{
    use StreamTrait, ReadableTrait;
}
