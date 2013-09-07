<?php

namespace Guzzle\Http\Message\Post;

use Guzzle\Http\Header\HeaderCollection;
use Guzzle\Http\Message\HasHeadersTrait;
use Guzzle\Http\Mimetypes;
use Guzzle\Stream\StreamFactory;
use Guzzle\Stream\ReadableStreamInterface;

/**
 * Post file upload
 */
class PostFile implements PostFileInterface
{
    use HasHeadersTrait;

    private $name;
    private $filename;

    /**
     * Factory method used to create a PostFile from a number of different types
     *
     * @param string                                                    $name Name of the form field
     * @param PostFileInterface|ReadableStreamInterface|resource|string $data Data used to create the file
     *
     * @return self
     */
    public static function create($name, $data)
    {
        return $data instanceof self ? $data : new self($name, $data);
    }

    /**
     * @param null                    $name     Name of the form field
     * @param ReadableStreamInterface $content  Data to send
     * @param null                    $filename Filename content-disposition attribute
     * @param array                   $headers  Array of headers to set on the file (can override any default headers)
     */
    public function __construct($name, ReadableStreamInterface $content, $filename = null, array $headers = [])
    {
        $this->headers = new HeaderCollection($headers);
        $this->content = $content;
        $this->name = $name;
        $this->filename = $filename ?: basename($this->content->getMetadata('uri'));

        // Account for nested MultipartBody objects
        if ($content instanceof MultipartBody) {
            $boundary = $content->getBoundary();
            if (!$this->hasHeader('Content-Disposition')) {
                $this->setHeader('Content-Disposition', 'form-data; name="' . $name .'"');
            }
            if (!$this->hasHeader('Content-Type')) {
                $this->setHeader('Content-Type', "multipart/form-data; boundary={$boundary}");
            }
            return;
        }

        // Set a default content-disposition header if one was no provided
        if (!isset($this->headers['content-disposition'])) {
            $disposition = 'form-data; filename="' . $this->filename . '"; name="' . $name . '"';
            $this->setHeader('Content-Disposition', $disposition);
        }

        // Set a default Content-Type if one was not supplied
        if (!isset($this->headers['Content-Type'])) {
            $this->setHeader('Content-Type', Mimetypes::getInstance()->fromFilename($this->filename) ?: 'text/plain');
        }
    }

    public function getName()
    {
        return $this->name;
    }

    public function getFilename()
    {
        return $this->filename;
    }

    public function getContent()
    {
        return $this->content;
    }
}
