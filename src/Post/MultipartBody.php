<?php

namespace GuzzleHttp\Post;

use GuzzleHttp\Stream;

/**
 * Stream that when read returns bytes for a streaming multipart/form-data body
 */
class MultipartBody implements Stream\StreamInterface
{
    use Stream\StreamDecoratorTrait;

    private $boundary;

    /**
     * @param array  $fields   Associative array of field names to values where
     *                         each value is a string or array of strings.
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
        $stream = new Stream\AppendStream();

        foreach ($fields as $name => $fieldValues) {
            foreach ((array) $fieldValues as $value) {
                $stream->addStream(
                    Stream\create($this->getFieldString($name, $value))
                );
            }
        }

        foreach ($files as $file) {

            if (!$file instanceof PostFileInterface) {
                throw new \InvalidArgumentException('All POST fields must '
                    . 'implement PostFieldInterface');
            }

            $stream->addStream(
                Stream\create($this->getFileHeaders($file))
            );
            $stream->addStream($file->getContent());
            $stream->addStream(Stream\create("\r\n"));
        }

        // Add the trailing boundary
        $stream->addStream(Stream\create("--{$this->boundary}--"));

        return $stream;
    }
}
