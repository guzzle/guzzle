<?php

namespace Guzzle\Http\Message\Post;

use Guzzle\Http\Message\HasHeadersTrait;
use Guzzle\Http\Mimetypes;
use Guzzle\Stream\MetadataStreamInterface;
use Guzzle\Stream\Stream;
use Guzzle\Stream\StreamInterface;

/**
 * Post file upload
 */
class PostFile implements PostFileInterface
{
    use HasHeadersTrait;

    private $name;
    private $filename;
    private $content;

    /**
     * @param null            $name     Name of the form field
     * @param mixed           $content  Data to send
     * @param null            $filename Filename content-disposition attribute
     * @param array           $headers  Array of headers to set on the file (can override any default headers)
     * @throws \RuntimeException if the filename is not passed or cannot be determined
     */
    public function __construct($name, $content, $filename = null, array $headers = [])
    {
        $this->setHeaders($headers);
        $this->name = $name;
        $this->content = $content;
        if (!($this->content instanceof StreamInterface)) {
            $this->content = Stream::factory($content, true);
        }

        $this->filename = $filename;
        if (!$this->filename && $this->content instanceof MetadataStreamInterface) {
            $this->filename = $this->content->getMetadata('uri');
        }

        if (!$this->filename || substr($this->filename, 0, 6) === 'php://') {
            $this->filename = $this->name;
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
            $disposition = 'form-data; filename="' . basename($this->filename) . '"; name="' . $name . '"';
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
