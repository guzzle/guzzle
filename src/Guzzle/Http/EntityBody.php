<?php

namespace Guzzle\Http;

use Guzzle\Common\Stream;
use Guzzle\Common\Exception\InvalidArgumentException;

/**
 * Entity body used with an HTTP request or response
 */
class EntityBody extends Stream
{
    /**
     * @var bool Content-Encoding of the entity body if known
     */
    protected $contentEncoding = false;

    /**
     * Create a new EntityBody based on the input type
     *
     * @param resource|string|EntityBody $resource Entity body data
     * @param int                        $size     Size of the data contained in the resource
     *
     * @return EntityBody
     * @throws InvalidArgumentException if the $resource arg is not a resource or string
     */
    public static function factory($resource = '', $size = null)
    {
        if (is_resource($resource)) {
            return new static($resource, $size);
        } elseif (is_string($resource)) {
            $stream = fopen('php://temp', 'r+');
            fwrite($stream, $resource);
            rewind($stream);

            return new static($stream);
        } elseif ($resource instanceof self) {
            return $resource;
        } elseif (is_array($resource)) {
            return self::factory(http_build_query($resource));
        }

        throw new InvalidArgumentException('Invalid resource type');
    }

    /**
     * If the stream is readable, compress the data in the stream using deflate
     * compression.  The uncompressed stream is then closed, and the compressed
     * stream then becomes the wrapped stream.
     *
     * @param string $filter Compression filter
     *
     * @return bool Returns TRUE on success or FALSE on failure
     */
    public function compress($filter = 'zlib.deflate')
    {
        $result = $this->handleCompression($filter);
        $this->contentEncoding = $result ? $filter : false;

        return $result;
    }

    /**
     * Uncompress a deflated string.  Once uncompressed, the uncompressed
     * string is then used as the wrapped stream.
     *
     * @param string $filter De-compression filter
     *
     * @return bool Returns TRUE on success or FALSE on failure
     */
    public function uncompress($filter = 'zlib.inflate')
    {
        $offsetStart = 0;

        // When inflating gzipped data, the first 10 bytes must be stripped
        // if a gzip header is present
        if ($filter == 'zlib.inflate') {
            // @codeCoverageIgnoreStart
            if (!$this->isReadable() || ($this->isConsumed() && !$this->isSeekable())) {
                return false;
            }
            // @codeCoverageIgnoreEnd
            $this->seek(0);
            if (fread($this->stream, 3) == "\x1f\x8b\x08") {
                $offsetStart = 10;
            }
        }

        $this->contentEncoding = false;

        return $this->handleCompression($filter, $offsetStart);
    }

    /**
     * Get the Content-Length of the entity body if possible (alias of getSize)
     *
     * @return int|false
     */
    public function getContentLength()
    {
        return $this->getSize();
    }

    /**
     * Guess the Content-Type or return the default application/octet-stream
     *
     * @return string
     * @see http://www.php.net/manual/en/function.finfo-open.php
     */
    public function getContentType()
    {
        if (!class_exists('finfo', false) || !($this->isLocal() && $this->getWrapper() == 'plainfile' && file_exists($this->getUri()))) {
            return 'application/octet-stream';
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);

        return $finfo->file($this->getUri());
    }

    /**
     * Get an MD5 checksum of the stream's contents
     *
     * @param bool $rawOutput    Whether or not to use raw output
     * @param bool $base64Encode Whether or not to base64 encode raw output
     *                           (only if raw output is true)
     *
     * @return bool|string Returns an MD5 string on success or FALSE on failure
     */
    public function getContentMd5($rawOutput = false, $base64Encode = false)
    {
        if (!$this->seek(0)) {
            return false;
        }

        $ctx = hash_init('md5');
        while ($data = $this->read(1024)) {
            hash_update($ctx, $data);
        }

        $out = hash_final($ctx, (bool) $rawOutput);
        $this->seek(0);

        return ((bool) $base64Encode && (bool) $rawOutput) ? base64_encode($out) : $out;
    }

    /**
     * Set the type of encoding stream that was used on the entity body
     *
     * @param string $streamFilterContentEncoding Stream filter used
     *
     * @return EntityBody
     */
    public function setStreamFilterContentEncoding($streamFilterContentEncoding)
    {
        $this->contentEncoding = $streamFilterContentEncoding;

        return $this;
    }

    /**
     * Get the Content-Encoding of the EntityBody
     *
     * @return bool|string
     */
    public function getContentEncoding()
    {
        return strtr($this->contentEncoding, array(
            'zlib.deflate' => 'gzip',
            'bzip2.compress' => 'compress'
        )) ?: false;
    }

    /**
     * Handles compression or uncompression of stream data
     *
     * @param string $filter      Name of the filter to use (zlib.deflate or zlib.inflate)
     * @param int    $offsetStart Number of bytes to skip from start
     *
     * @return bool Returns TRUE on success or FALSE on failure
     */
    protected function handleCompression($filter, $offsetStart = null)
    {
        // @codeCoverageIgnoreStart
        if (!$this->isReadable() || ($this->isConsumed() && !$this->isSeekable())) {
            return false;
        }
        // @codeCoverageIgnoreEnd

        $handle = fopen('php://temp', 'r+');
        $filter = @stream_filter_append($handle, $filter, STREAM_FILTER_WRITE);
        if (!$filter) {
            return false;
        }

        // Seek to the beginning of the stream if possible
        $this->seek(0);

        if ($offsetStart) {
            fread($this->stream, $offsetStart);
        }

        while ($data = fread($this->stream, 8096)) {
            fwrite($handle, $data);
        }

        fclose($this->stream);
        $this->stream = $handle;
        stream_filter_remove($filter);
        $stat = fstat($this->stream);
        $this->size = $stat['size'];
        $this->rebuildCache();
        $this->seek(0);

        return true;
    }
}
