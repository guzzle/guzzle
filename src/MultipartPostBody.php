<?php
namespace GuzzleHttp;

use GuzzleHttp\Psr7;
use Psr\Http\Message\StreamInterface;

/**
 * Stream that when read returns bytes for a streaming multipart/form-data body
 */
class MultipartPostBody implements StreamInterface
{
    use Psr7\StreamDecoratorTrait;

    private $boundary;

    /**
     * @param array  $fields   Associative array of field names to values where
     *                         each value is a string or array of strings.
     * @param array  $files    Array of associative arrays, each containing a
     *                         required "name" key mapping to the form field,
     *                         name, a required "contents" key mapping to a
     *                         StreamInterface/resource/string, an optional
     *                         "headers" associative array of custom headers,
     *                         and an optional "filename" key mapping to a
     *                         string to send as the filename in the part.
     * @param string $boundary You can optionally provide a specific boundary
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(
        array $fields = [],
        array $files = [],
        $boundary = null
    ) {
        $this->boundary = $boundary ?: uniqid();
        $this->stream = $this->createStream($fields, $files);
    }

    /**
     * Get the boundary
     *
     * @return string
     */
    public function getBoundary()
    {
        return $this->boundary;
    }

    public function isWritable()
    {
        return false;
    }

    /**
     * Get the string needed to transfer a POST field
     */
    private function getFieldString($name, $value)
    {
        return sprintf(
            "--%s\r\nContent-Disposition: form-data; name=\"%s\"\r\n\r\n%s\r\n",
            $this->boundary,
            $name,
            $value
        );
    }

    /**
     * Get the headers needed before transferring the content of a POST file
     */
    private function getFileHeaders(array $headers)
    {
        $str = '';
        foreach ($headers as $key => $value) {
            $str .= "{$key}: {$value}\r\n";
        }

        return "--{$this->boundary}\r\n" . trim($str) . "\r\n\r\n";
    }

    /**
     * Create the aggregate stream that will be used to upload the POST data
     */
    protected function createStream(array $fields, array $files)
    {
        $stream = new Psr7\AppendStream();

        foreach ($fields as $name => $fieldValues) {
            foreach ((array) $fieldValues as $value) {
                $stream->addStream(
                    Psr7\stream_for($this->getFieldString($name, $value))
                );
            }
        }

        foreach ($files as $file) {
            $this->addFile($stream, $file);
        }

        // Add the trailing boundary with CRLF
        $stream->addStream(Psr7\stream_for("--{$this->boundary}--\r\n"));

        return $stream;
    }

    private function addFile(Psr7\AppendStream $stream, array $file)
    {
        if (!array_key_exists('contents', $file)) {
            throw new \InvalidArgumentException('A "contents" key is required');
        }

        if (!isset($file['name'])) {
            throw new \InvalidArgumentException('A "name" key is required');
        }

        list($body, $headers) = $this->createPostFile(
            $file['name'],
            $file['contents'],
            isset($file['headers']) ? $file['headers'] : []
        );

        $stream->addStream(Psr7\stream_for($this->getFileHeaders($headers)));
        $stream->addStream($body);
        $stream->addStream(Psr7\stream_for("\r\n"));
    }

    /**
     * @return array
     */
    private function createPostFile($name, $stream, array $headers = [])
    {
        $stream = Psr7\stream_for($stream);
        $filename = $name;

        if ($uri = $stream->getMetadata('uri')) {
            if (substr($uri, 0, 6) !== 'php://') {
                $filename = $uri;
            }
        }

        // Set a default content-disposition header if one was no provided
        $disposition = $this->getHeader($headers, 'content-disposition');
        if (!$disposition) {
            $headers['Content-Disposition'] = sprintf(
                'form-data; name="%s"; filename="%s"',
                $name,
                basename($filename)
            );
        }

        // Set a default content-length header if one was no provided
        $length = $this->getHeader($headers, 'content-length');
        if (!$length) {
            if ($length = $stream->getSize()) {
                $headers['Content-Length'] = (string) $length;
            }
        }

        // Set a default Content-Type if one was not supplied
        $type = $this->getHeader($headers, 'content-type');
        if (!$type) {
            if ($type = Psr7\mimetype_from_filename($filename)) {
                $headers['Content-Type'] = $type;
            }
        }

        return [$stream, $headers];
    }

    private function getHeader(array $headers, $key)
    {
        foreach ($headers as $k => $v) {
            if ($k === $key) {
                return $v;
            }
        }

        return null;
    }
}
