<?php

namespace Guzzle\Stream;
use Guzzle\Http\Mimetypes;
use Guzzle\Url\QueryString;

/**
 * Stream that when read returns bytes for a multipart/form-data body
 */
class MultipartBody implements StreamInterface
{
    private $files = [];
    private $fields = [];
    private $metadata = ['mode' => 'r'];
    private $size;
    private $currentField = 0;
    private $currentFile = 0;
    private $pos = 0;

    public function __toString()
    {
        if ($this->pos !== 0) {
            $this->rewind();
        }

        $buffer = '';
        while (!$this->eof()) {
            $buffer .= $this->read(1048576);
        }

        return $buffer;
    }

    /**
     * Set a specific field value
     *
     * @param string $name  Field name
     * @param string $value Field value
     *
     * @return self
     * @throws \InvalidArgumentException for invalid field values
     */
    public function setField($name, $value)
    {
        $this->size = null;
        $type = gettype($value);
        if ($type != 'string' && $type != 'array') {
            throw new \InvalidArgumentException('Field values must be a string or array');
        }

        $this->fields[$name] = $value;

        return $this;
    }

    /**
     * Replace all existing POST fields with an associative array of fields
     *
     * @param array $fields Associative array of POST fields to send
     *
     * @return self
     */
    public function setFields(array $fields)
    {
        $this->fields = [];
        foreach ($fields as $key => $value) {
            $this->setField($key, $value);
        }

        return $this;
    }

    /**
     * Replace all existing POST files with an associative array of POST files
     *
     * @param array $files Associative array of POST files
     *
     * @return self
     * @throws \InvalidArgumentException for invalid POST file values
     */
    public function setFiles(array $files)
    {
        foreach ($files as $name => $file) {
            if (!is_array($file)) {
                throw new \InvalidArgumentException('Each POST file must be the data, content-type, and post name');
            }
            call_user_func_array(array($this, 'setFile'), array_merge(array($name), $file));
        }

        return $this;
    }

    /**
     * Set a specific field value
     *
     * @param string                                     $name         Name of the file
     * @param string|resource|StreamInterface|\Generator $data         File data to send
     * @param string                                     $contentType  Content-type to set
     * @param string                                     $postFileName Name of the POST file
     *
     * @return self
     */
    public function setFile($name, $data, $contentType = null, $postFileName = null)
    {
        $this->size = null;
        $data = Stream::factory($data);

        // Gleam the POST file name from the URI if it isn't set and there is a URI
        if (!$postFileName && $data->getUri()) {
            $postFileName = basename($data->getUri());
        }

        // Guess the Content-Type if one was not provided
        if (!$contentType) {
            $contentType = Mimetypes::getInstance()->fromFilename($postFileName);
        }

        $this->fields[$name] = [$data, $contentType, $postFileName];

        return $this;
    }

    public function close()
    {
        $this->fields = $this->files = [];
    }

    public function getMetadata($key = null)
    {
        return isset($this->metadata[$key]) ? $this->metadata[$key] : null;
    }

    /**
     * @throws \InvalidArgumentException When trying to change the value of "mode"
     */
    public function setMetadata($key, $value)
    {
        if ($key == 'mode') {
            throw new \InvalidArgumentException("Cannot change immutable value of stream: {$key}");
        }

        $this->metadata[$key] = $value;

        return $this;
    }

    /**
     * Casts the body to a string, then returns a PHP temp stream representation of the body
     *
     * @return resource
     */
    public function getStream()
    {
        return Stream::factory((string) $this)->getStream();
    }

    public function detachStream() {}

    public function getUri()
    {
        return false;
    }

    /**
     * The stream has reached an EOF when all of the fields and files have been read
     * {@inheritdoc}
     */
    public function eof()
    {
        return $this->currentField == count($this->fields)
            && $this->currentFile == count($this->files)
            && (!$this->currentFile || $this->files[$this->currentFile - 1]->eof());
    }

    public function tell()
    {
        return $this->pos;
    }

    public function isReadable()
    {
        return true;
    }

    public function isWritable()
    {
        return false;
    }

    public function isLocal()
    {
        return false;
    }

    public function isSeekable()
    {
        foreach ($this->files as $file) {
            if (!$file->isSeekable()) {
                return false;
            }
        }

        return true;
    }

    public function setSize($size)
    {
        $this->size = $size;
    }

    public function getSize()
    {
        return null;
    }

    public function read($length)
    {
        
    }

    public function rewind()
    {
        return $this->seek(0);
    }

    /**
     * @throws \BadMethodCallException
     */
    public function seek($offset, $whence = SEEK_SET)
    {
        if ($offset != 0 || $whence != SEEK_SET) {
            throw new \BadMethodCallException(__CLASS__ . ' only supports seeking to byte 0');
        }

        if (!$this->isSeekable()) {
            return false;
        }

        foreach ($this->files as $file) {
            if (!$file->rewind()) {
                throw new \RuntimeException('Rewind on multipart file failed even though it shouldn\'t have');
            }
        }

        $this->pos = 0;

        return true;
    }

    /**
     * @throws \BadMethodCallException
     */
    public function readLine($maxLength = null)
    {
        throw new \BadMethodCallException(__CLASS__ . ' does not support ' . __METHOD__);
    }

    /**
     * @throws \BadMethodCallException
     */
    public function write($string)
    {
        throw new \BadMethodCallException(__CLASS__ . ' does not support ' . __METHOD__);
    }
}
