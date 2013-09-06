<?php

namespace Guzzle\Stream\Php;

/**
 * Trait implementing {@see \Guzzle\Stream\WritableStreamInterface}
 */
trait WritableTrait
{
    /**
     * @see \Guzzle\Stream\WritableStreamInterface::write
     */
    public function write($string)
    {
        // We can't know the size after writing anything
        $this->size = null;

        return fwrite($this->stream, $string);
    }
}
