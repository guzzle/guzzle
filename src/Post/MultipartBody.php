<?php
namespace GuzzleHttp\Post;

use GuzzleHttp\Stream\AppendStream;
use GuzzleHttp\Stream\Stream;
use GuzzleHttp\Stream\StreamDecoratorTrait;
use GuzzleHttp\Stream\StreamInterface;

/**
 * Stream that when read returns bytes for a streaming multipart/form-data body
 */
class MultipartBody implements StreamInterface
{
    use StreamDecoratorTrait;

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
        $boundary = sprintf("--%s\r\n", $this->boundary);

        if ($value instanceof PostFieldInterface) {
            $headers = $value->getHeaders();
            if ($name = $value->getName() && !isset($headers['Content-Disposition'])) {
                $value->addHeader('Content-Disposition', sprintf('form-data; name="%s"', $value->getName()));
            }

            return $this->getHeaders($value) . $value->getValue() . "\r\n";
        }

        // This is a form post data
        return sprintf(
            "%sContent-Disposition: form-data; name=\"%s\"\r\n\r\n%s\r\n",
            $boundary,
            $name,
            $value
        );
    }

    /**
     * Get the headers needed before transferring the content of a POST element
     */
    private function getHeaders(PostElementInterface $file)
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

        foreach ($files as $file) {

            if (!$file instanceof PostFileInterface) {
                throw new \InvalidArgumentException('All POST fields must '
                    . 'implement PostFieldInterface');
            }

            $stream->addStream(
                Stream::factory($this->getHeaders($file))
            );
            $stream->addStream($file->getContent());
            $stream->addStream(Stream::factory("\r\n"));
        }

        // Add the trailing boundary with CRLF
        $stream->addStream(Stream::factory("--{$this->boundary}--\r\n"));

        return $stream;
    }
}
