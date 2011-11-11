<?php

namespace Guzzle\Http;

use Guzzle\Common\Stream;

/**
 * Entity body used with an HTTP request or response
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class EntityBody extends Stream
{
    /**
     * @var bool Content-Encoding of the entity body if known
     */
    protected $contentEncoding = false;

    /**
     * @var bool File types and whether or not they should be Gzipped.
     */
    static protected $extensions = array(
        'ai' => 'application/postscript',
        'asc' => 'text/plain',
        'avi' => 'video/x-msvideo',
        'bmp' => 'image/bmp',
        'bz' => 'application/x-bzip',
        'bz2' => 'application/x-bzip2',
        'cab' => 'application/vnd.ms-cab-compressed',
        'css' => 'text/css',
        'doc' => 'application/msword',
        'eps' => 'application/postscript',
        'exe' => 'application/x-msdownload',
        'flv' => 'video/x-flv',
        'gif' => 'image/gif',
        'gz' => 'application/x-gzip',
        'htm' => 'text/html',
        'html' => 'text/html',
        'ico' => 'image/vnd.microsoft.icon',
        'ico' => 'image/x-icon',
        'jpe' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'jpg' => 'image/jpeg',
        'js' => 'application/javascript',
        'json' => 'application/json',
        'mov' => 'video/quicktime',
        'mp3' => 'audio/mpeg',
        'mpeg' => 'video/mpeg',
        'mpg' => 'video/mpeg',
        'msi' => 'application/x-msdownload',
        'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
        'odt' => 'application/vnd.oasis.opendocument.text',
        'ogg' => 'application/ogg',
        'pdf' => 'application/pdf',
        'php' => 'text/html',
        'php' => 'text/x-php',
        'png' => 'image/png',
        'ppt' => 'application/vnd.ms-powerpoint',
        'ps' => 'application/postscript',
        'psd' => 'image/vnd.adobe.photoshop',
        'qt' => 'video/quicktime',
        'rar' => 'application/x-rar-compressed',
        'rtf' => 'application/rtf',
        'svg' => 'image/svg+xml',
        'svgz' => 'image/svg+xml',
        'swf' => 'application/x-shockwave-flash',
        'tar' => 'application/x-tar',
        'tif' => 'image/tiff',
        'tiff' => 'image/tiff',
        'txt' => 'text/plain',
        'wav' => 'audio/x-wav',
        'xls' => 'application/vnd.ms-excel',
        'xml' => 'application/xml',
        'xsl' => 'application/xsl+xml',
        'zip' => 'application/zip'
    );

    /**
     * Create a new EntityBody based on the input type
     *
     * @param resource|string|EntityBody $resource (optional) Entity body data
     * @param int $size (optional) Size of the data contained in the resource
     *
     * @return EntityBody
     * @throws HttpException if the $resource arg is not a resource or string
     */
    public static function factory($resource = '', $size = null)
    {
        if (is_resource($resource)) {
            
            return new self($resource, $size);
        } else if (is_string($resource)) {
            $stream = fopen('php://temp', 'r+');
            fwrite($stream, $resource);
            rewind($stream);
            
            return new self($stream);
        } else if ($resource instanceof self) {
            return $resource;
        } else {
            throw new HttpException('Invalid data sent to ' . __METHOD__);
        }
    }

    /**
     * If the stream is readable, compress the data in the stream using deflate 
     * compression.  The uncompressed stream is then closed, and the compressed
     * stream then becomes the wrapped stream.
     *
     * @param string $filter (optional) Compression filter
     *
     * @return bool Returns TRUE on success or FALSE on failure
     */
    public function compress($filter = 'zlib.deflate')
    {
        $result = $this->handleCompression($filter);
        if ($result) {
            $this->contentEncoding = $filter;
        }

        return $result;
    }
    
    /**
     * Uncompress a deflated string.  Once uncompressed, the uncompressed
     * string is then used as the wrapped stream.
     *
     * @param string $filter (optional) De-compression filter
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
     * Guess the Content-Type of the stream based on the file extension of a
     * file-based stream
     *
     * @return string
     */
    public function getContentType()
    {
        // If the file exists, then detect the mime type using Fileinfo
        if ($this->isLocal() && $this->getWrapper() == 'plainfile' && file_exists($this->getUri())) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $this->getUri());
            finfo_close($finfo);
        } else {
            $ext = strtoLower(pathInfo($this->getUri(), PATHINFO_EXTENSION));
            $mimeType = isset(self::$extensions[$ext]) ? self::$extensions[$ext] : 'application/octet-stream';
        }

        return $mimeType;
    }

    /**
     * Get an MD5 checksum of the stream's contents
     *
     * @param bool $rawOutput (optional) Whether or not to use raw output
     * @param bool $base64Encode (optional) whether or not to base64 encode
     *      raw output (only if raw output is true)
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
        $encoding = $this->contentEncoding;
        if ($this->contentEncoding == 'zlib.deflate') {
            $encoding = 'gzip';
        } else if ($this->contentEncoding == 'bzip2.compress') {
            $encoding = 'compress';
        }

        return $encoding;
    }

    /**
     * Handles compression or uncompression of stream data
     *
     * @param $filter Name of the filter to use (zlib.deflate or zlib.inflate)
     * @param int $offsetStart (optional) Number of bytes to skip from start
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
        if (!$handle) {
            // @codeCoverageIgnoreStart
            return false;
            // @codeCoverageIgnoreEnd
        }

        $filter = @stream_filter_append($handle, $filter, STREAM_FILTER_WRITE);
        if (!$filter) {
            return false;
        }

        // @codeCoverageIgnoreStart
        if ($filter === false) {
            return false;
        }
        // @codeCoverageIgnoreEnd

        // Seek to the beginning of the stream if possible
        $this->seek(0);

        if ($offsetStart) {
            fread($this->stream, $offsetStart);
        }

        while ($data = fread($this->stream, 8096)) {
            fwrite($handle, $data);
        }

        fclose($this->stream);

        if ($filter) {
            stream_filter_remove($filter);
            $this->stream = $handle;
        }

        $stat = fstat($this->stream);
        $this->size = $stat['size'];

        return true;
    }
}