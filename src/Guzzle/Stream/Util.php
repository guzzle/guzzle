<?php

namespace Guzzle\Stream;

/**
 * Stream utility functions
 */
class Util
{
    /**
     * Calculate a hash of a Stream
     *
     * @param ReadableStreamInterface $stream    Stream to calculate the hash for
     * @param string                  $algo      Hash algorithm (e.g. md5, crc32, etc)
     * @param bool                    $rawOutput Whether or not to use raw output
     *
     * @return bool|string Returns false on failure or a hash string on success
     */
    public static function getHash(
        ReadableStreamInterface $stream,
        $algo,
        $rawOutput = false
    ) {
        $pos = $stream->tell();
        if (!$stream->seek(0)) {
            return false;
        }

        $ctx = hash_init($algo);
        while ($data = $stream->read(8192)) {
            hash_update($ctx, $data);
        }

        $out = hash_final($ctx, (bool) $rawOutput);
        $stream->seek($pos);

        return $out;
    }

    /**
     * Read a line from the stream up to the maximum allowed buffer length
     *
     * @param ReadableStreamInterface $stream    Stream to read from
     * @param int                     $maxLength Maximum buffer length
     *
     * @return string|bool
     */
    public static function readLine(
        ReadableStreamInterface $stream,
        $maxLength = null
    ) {
        $buffer = '';
        $size = 0;

        while (!$stream->eof()) {
            if (false === ($byte = $stream->read(1))) {
                return $buffer;
            }
            $buffer .= $byte;
            // Break when a new line is found or the max length - 1 is reached
            if ($byte == PHP_EOL || ++$size == $maxLength - 1) {
                break;
            }
        }

        return $buffer;
    }
}
