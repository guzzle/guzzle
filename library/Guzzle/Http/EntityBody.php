<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Http;

use Guzzle\Common\Stream\StreamHelper;

/**
 * Entity body used with an HTTP request or response
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class EntityBody extends StreamHelper
{
    /**
     * @var bool Content-Encoding of the entity body if known
     */
    protected $contentEncoding = false;

    /**
     * @var bool File types and whether or not they should be Gzipped.
     */
    static protected $extensions = array(
        'asc' => array('text/plain', true),
        'avi' => array('video/x-msvideo', false),
        'bz' => array('application/x-bzip', false),
        'bz2' => array('application/x-bzip2', false),
        'flv' => array('video/x-flv', false),
        'gif' => array('image/gif', false),
        'gz' => array('application/x-gzip', false),
        'htm' => array('text/html', true),
        'html' => array('text/html', true),
        'ico' => array('image/x-icon', false),
        'jpg' => array('image/jpeg', false),
        'mov' => array('video/quicktime', false),
        'mp3' => array('audio/mpeg', false),
        'mpeg' => array('video/mpeg', false),
        'mpg' => array('video/mpeg', false),
        'ogg' => array('application/ogg', false),
        'pdf' => array('application/pdf', false),
        'php' => array('text/x-php', false),
        'png' => array('image/png', false),
        'swf' => array('application/x-shockwave-flash', false),
        'tar' => array('application/x-tar', false),
        'tif' => array('image/tiff', false),
        'tiff' => array('image/tiff', false),
        'txt' => array('text/plain', true),
        'wav' => array('audio/x-wav', false),
        'xml' => array('text/xml', true),
        'xsl' => array('application/xsl+xml', true),
        'zip' => array('application/zip', false),
    );

    /**
     * Create a new EntityBody based on the input type
     *
     * @param resource|string|EntityBody $resource Data for the entity body
     * @param int $size (optional) Size of the data contained in the resource
     *
     * @return EntityBody
     * @throws HttpException if the $resource arg is not a resource or string
     */
    public static function factory($resource, $size = null)
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
     * Get whether or not a file is a text-based file and should be compressed.
     *
     * @param string $filename Filename to check
     *
     * @return bool Returns TRUE if the file is text-based or FALSE if it is not
     */
    public static function shouldCompress($filename)
    {
        $ext = strtoLower(pathInfo($filename, PATHINFO_EXTENSION));

        return isset(self::$extensions[$ext]) ? self::$extensions[$ext][1] : false;
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
            $mimeType = isset(self::$extensions[$ext]) ? trim(self::$extensions[$ext][0]) : 'application/octet-stream';
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
        $data = (string) $this;
        $out = ($data !== false) ? md5($data, (bool) $rawOutput) : false;
        
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
     * Read a chunk of data from the EntityBody
     *
     * @param int $chunkLength (optional) Maximum chunk length to read
     * @param int $startPos (optional) Where the seek position was when the last
     *      chunk was read.  If the seek position is not there, then the stream
     *      will be seeked to that position before it starts reading.  You
     *      SHOULD always set the startPos when reading the body for a chunked
     *      Transfer-Encoding request so that other calls will not interfere
     *      with the data sent over the wire.
     *
     * @return string Returns the hex length of the chunk, followed by a CRLF,
     *      followed by the chunk of read data
     */
    public function readChunked($chunkLength = 4096, $startPos = null)
    {
        if (!is_null($startPos) && ftell($this->stream) != $startPos) {
            $this->seek($startPos);
        }

        $data = $this->read($chunkLength);

        return dechex(strlen($data)) . "\r\n" . $data;
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