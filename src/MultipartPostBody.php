<?php
namespace GuzzleHttp;

use GuzzleHttp\Psr7\AppendStream;
use GuzzleHttp\Psr7\Stream;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamableInterface;

/**
 * Stream that when read returns bytes for a streaming multipart/form-data body
 */
class MultipartPostBody implements StreamableInterface
{
    use StreamDecoratorTrait;

    private $boundary;

    /**
     * @param array  $fields   Associative array of field names to values where
     *                         each value is a string or array of strings.
     * @param array  $files    Associative array of POST field names to either
     *                         an fopen resource, StreamableInterface, or an
     *                         array containing a StreamableInterface/resource
     *                         followed by an array of headers for the file.
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
        $stream = new AppendStream();

        foreach ($fields as $name => $fieldValues) {
            foreach ((array) $fieldValues as $value) {
                $stream->addStream(
                    Stream::factory($this->getFieldString($name, $value))
                );
            }
        }

        foreach ($files as $name => $file) {
            if ($file instanceof StreamableInterface || is_resource($file)) {
                $file = $this->createPostFile($name, $file);
            } elseif (is_array($file)) {
                $file = $this->createPostFile($name, $file[0], $file[1]);
            } else {
                throw new \InvalidArgumentException('All POST files must be '
                    . 'an array or StreamableInterface');
            }
            $stream->addStream(Stream::factory($this->getFileHeaders($file[1])));
            $stream->addStream($file[0]);
            $stream->addStream(Stream::factory("\r\n"));
        }

        // Add the trailing boundary with CRLF
        $stream->addStream(Stream::factory("--{$this->boundary}--\r\n"));

        return $stream;
    }

    /**
     * @return array
     */
    private function createPostFile($name, $stream, array $headers = [])
    {
        $stream = Stream::factory($stream);
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
            $mimes = Mimetypes::getInstance();
            $type = $mimes->fromFilename($filename);
            if ($type) {
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
