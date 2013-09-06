<?php

namespace Guzzle\Stream\Php;

use Guzzle\Stream\WritableStreamInterface;

/**
 * Stream implementation that implements WritableStreamInterface
 */
class WritableStream implements WritableStreamInterface
{
    use StreamTrait, WritableTrait;
}
