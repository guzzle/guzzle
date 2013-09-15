<?php

namespace Guzzle\Http\Message\Post;

use Guzzle\Http\Message\HasHeadersTrait;
use Guzzle\Http\Mimetypes;
use Guzzle\Stream\HasMetadataStreamInterface;
use Guzzle\Stream\StreamInterface;

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
     * @param string                                            $name Name of the form field
     * @param PostFileInterface|StreamInterface|resource|string $data Data used to create the file
     *
     * @return self
     */
    public static function create($name, $data)
    {
        return $data instanceof self ? $data : new self($name, $data);
    }

    /**
     * @param null            $name     Name of the form field
     * @param StreamInterface $content  Data to send
     * @param null            $filename Filename content-disposition attribute
     * @param array           $headers  Array of headers to set on the file (can override any default headers)
     * @throws \RuntimeException if the filename is not passed or cannot be determined
     */
    public function __construct($name, StreamInterface $content, $filename = null, array $headers = [])
    {
        $this->content = $content;
        $this->name = $name;
        $this->filename = $filename;

        if (!$this->filename && $content instanceof HasMetadataStreamInterface) {
            $this->filename = $content->getMetadata('uri');
        }

        if (!$this->filename) {
            throw new \RuntimeException('Could not determine filename from arguments or stream');
        }

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
        if (!$this->hasHeader('content-disposition')) {
            $disposition = 'form-data; filename="' . $this->filename . '"; name="' . $name . '"';
            $this->setHeader('Content-Disposition', $disposition);
        }

        // Set a default Content-Type if one was not supplied
        if (!$this->hasHeader('Content-Type')) {
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
