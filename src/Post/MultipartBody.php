<?php

namespace GuzzleHttp\Post;

use GuzzleHttp\Stream;

/**
 * Stream that when read returns bytes for a streaming multipart/form-data body
 */
class MultipartBody implements Stream\StreamInterface
{
    /** @var Stream\StreamInterface */
    private $stream;
    private $boundary;

    /**
     * @param array  $fields   Associative array of field names to values where
     *                         each value is a string.
     * @param array  $files    Associative array of PostFileInterface objects
     * @param string $boundary You can optionally provide a specific boundary
     * @throws \InvalidArgumentException
     */
    public function __construct(
        array $fields = [],
        array $files = [],
        $boundary = null
    ) {
        $this->boundary = $boundary ?: uniqid();
        $this->createStream($fields, $files);
    }

    public function __toString()
    {
        return (string) $this->stream;
    }

    public function getContents($maxLength = -1)
    {
        return $this->stream->getContents($maxLength);
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

    public function close()
    {
        $this->stream->close();
        $this->detach();
    }

    public function detach()
    {
        $this->stream->detach();
        $this->size = 0;
    }

    public function eof()
    {
        return $this->stream->eof();
    }

    public function tell()
    {
        return $this->stream->tell();
    }

    public function isReadable()
    {
        return true;
    }

    public function isWritable()
    {
        return false;
    }

    public function isSeekable()
    {
        return $this->stream->isSeekable();
    }

    public function getSize()
    {
        return $this->stream->getSize();
    }

    public function read($length)
    {
        return $this->stream->read($length);
    }

    public function seek($offset, $whence = SEEK_SET)
    {
        return $this->stream->seek($offset, $whence);
    }

    public function write($string)
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
    private function getFileHeaders(PostFileInterface $file)
    {
        $headers = '';
        foreach ($file->getHeaders() as $key => $value) {
            $headers .= "{$key}: {$value}\r\n";
        }

        return "--{$this->boundary}\r\n" . trim($headers) . "\r\n\r\n";
    }

    /**
     * Create the aggregate stream that will be used to upload the POST data
     */
    private function createStream(array $fields, array $files)
    {
        $this->stream = new Stream\AppendStream();

        foreach ($fields as $name => $field) {
            $this->stream->addStream(
                Stream\create($this->getFieldString($name, $field))
            );
        }

        foreach ($files as $file) {

            if (!$file instanceof PostFileInterface) {
                throw new \InvalidArgumentException('All POST fields must '
                    . 'implement PostFieldInterface');
            }

            $this->stream->addStream(
                Stream\create($this->getFileHeaders($file))
            );
            $this->stream->addStream($file->getContent());
            $this->stream->addStream(Stream\create("\r\n"));
        }

        // Add the trailing boundary
        $this->stream->addStream(Stream\create("--{$this->boundary}--"));
    }
}
