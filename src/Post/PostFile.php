<?php
namespace GuzzleHttp\Post;

use GuzzleHttp\Mimetypes;
use GuzzleHttp\Stream\StreamInterface;
use GuzzleHttp\Stream\Stream;

/**
 * Post file upload
 */
class PostFile implements PostFileInterface
{
    private $name;
    private $filename;
    private $content;
    private $headers = [];

    /**
     * @param string          $name     Name of the form field
     * @param mixed           $content  Data to send
     * @param string|null     $filename Filename content-disposition attribute
     * @param array           $headers  Array of headers to set on the file
     *                                  (can override any default headers)
     * @throws \RuntimeException when filename is not passed or can't be determined
     */
    public function __construct(
        $name,
        $content,
        $filename = null,
        array $headers = []
    ) {
        $this->headers = $headers;
        $this->name = $name;
        $this->prepareContent($content);
        $this->prepareFilename($filename);
        $this->prepareDefaultHeaders();
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

    public function getHeaders()
    {
        return $this->headers;
    }

    /**
     * Prepares the contents of a POST file.
     *
     * @param mixed $content Content of the POST file
     */
    private function prepareContent($content)
    {
        $this->content = $content;

        if (!($this->content instanceof StreamInterface)) {
            $this->content = Stream::factory($this->content);
        } elseif ($this->content instanceof MultipartBody) {
            if (!$this->hasHeader('Content-Disposition')) {
                $disposition = 'form-data; name="' . $this->name .'"';
                $this->headers['Content-Disposition'] = $disposition;
            }

            if (!$this->hasHeader('Content-Type')) {
                $this->headers['Content-Type'] = sprintf(
                    "multipart/form-data; boundary=%s",
                    $this->content->getBoundary()
                );
            }
        }
    }

    /**
     * Applies a file name to the POST file based on various checks.
     *
     * @param string|null $filename Filename to apply (or null to guess)
     */
    private function prepareFilename($filename)
    {
        $this->filename = $filename;

        if (!$this->filename) {
            $this->filename = $this->content->getMetadata('uri');
        }

        if (!$this->filename || substr($this->filename, 0, 6) === 'php://') {
            $this->filename = $this->name;
        }
    }

    /**
     * Applies default Content-Disposition and Content-Type headers if needed.
     */
    private function prepareDefaultHeaders()
    {
        // Set a default content-disposition header if one was no provided
        if (!$this->hasHeader('Content-Disposition')) {
            $this->headers['Content-Disposition'] = sprintf(
                'form-data; name="%s"; filename="%s"',
                $this->name,
                basename($this->filename)
            );
        }

        // Set a default Content-Type if one was not supplied
        if (!$this->hasHeader('Content-Type')) {
            $this->headers['Content-Type'] = Mimetypes::getInstance()
                ->fromFilename($this->filename) ?: 'text/plain';
        }
    }

    /**
     * Check if a specific header exists on the POST file by name.
     *
     * @param string $name Case-insensitive header to check
     *
     * @return bool
     */
    private function hasHeader($name)
    {
        return isset(array_change_key_case($this->headers)[strtolower($name)]);
    }
}
