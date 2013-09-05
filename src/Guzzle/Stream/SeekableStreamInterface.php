<?php

namespace Guzzle\Stream;

interface SeekableStreamInterface
{
    /**
     * Seek to a position in the stream
     *
     * @param int $offset Stream offset
     * @param int $whence Where the offset is applied
     *
     * @return bool Returns TRUE on success or FALSE on failure
     * @link   http://www.php.net/manual/en/function.fseek.php
     */
    public function seek($offset, $whence = SEEK_SET);
}
