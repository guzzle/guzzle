<?php

namespace Guzzle\Http;

use Guzzle\Common\StreamInterface;

/**
 * Entity body used with an HTTP request or response
 */
interface EntityBodyInterface extends StreamInterface
{
    /**
     * If the stream is readable, compress the data in the stream using deflate
     * compression.  The uncompressed stream is then closed, and the compressed
     * stream then becomes the wrapped stream.
     *
     * @param string $filter Compression filter
     *
     * @return bool Returns TRUE on success or FALSE on failure
     */
    public function compress($filter = 'zlib.deflate');

    /**
     * Decompress a deflated string.  Once uncompressed, the uncompressed
     * string is then used as the wrapped stream.
     *
     * @param string $filter De-compression filter
     *
     * @return bool Returns TRUE on success or FALSE on failure
     */
    public function uncompress($filter = 'zlib.inflate');

    /**
     * Get the Content-Length of the entity body if possible (alias of getSize)
     *
     * @return int|bool Returns the Content-Length or false on failure
     */
    public function getContentLength();

    /**
     * Guess the Content-Type or return the default application/octet-stream
     *
     * @return string
     * @see http://www.php.net/manual/en/function.finfo-open.php
     */
    public function getContentType();

    /**
     * Get an MD5 checksum of the stream's contents
     *
     * @param bool $rawOutput    Whether or not to use raw output
     * @param bool $base64Encode Whether or not to base64 encode raw output (only if raw output is true)
     *
     * @return bool|string Returns an MD5 string on success or FALSE on failure
     */
    public function getContentMd5($rawOutput = false, $base64Encode = false);

    /**
     * Get the Content-Encoding of the EntityBody
     *
     * @return bool|string
     */
    public function getContentEncoding();
}
