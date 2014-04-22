<?php

namespace GuzzleHttp\Post;

use GuzzleHttp\Stream;

/**
 * Stream that when read returns bytes for a streaming multipart/form-data body
 */
class MultipartBody implements Stream\StreamInterface
{
    /** @var Stream\StreamInterface */
    private $files;
    private $fields;
    private $size;
    private $buffer;
    private $bufferedHeaders = [];
    private $pos = 0;
    private $currentFile = 0;
    private $currentField = 0;
    private $sentLast;
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
        $this->fields = $fields;
        $this->files = $files;

        // Ensure each file is a PostFileInterface
        foreach ($this->files as $file) {
            if (!$file instanceof PostFileInterface) {
                throw new \InvalidArgumentException('All POST fields must '
                    . 'implement PostFieldInterface');
            }
        }
    }

    public function __toString()
    {
        $this->seek(0);

        return $this->getContents();
    }

    public function getContents($maxLength = -1)
    {
        $buffer = '';

        while (!$this->eof()) {
            if ($maxLength === -1) {
                $read = 1048576;
            } else {
                $len = strlen($buffer);
                if ($len == $maxLength) {
                    break;
                }
                $read = min(1048576, $maxLength - $len);
            }
            $buffer .= $this->read($read);
        }

        return $buffer;
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
        $this->detach();
    }

    public function detach()
    {
        $this->fields = $this->files = [];
    }

    /**
     * The stream has reached an EOF when all of the fields and files have been
     * read.
     * {@inheritdoc}
     */
    public function eof()
    {
        return $this->currentField == count($this->fields) &&
            $this->currentFile == count($this->files);
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

    /**
     * The steam is seekable by default, but all attached files must be
     * seekable too.
     * {@inheritdoc}
     */
    public function isSeekable()
    {
        foreach ($this->files as $file) {
            if (!$file->getContent()->isSeekable()) {
                return false;
            }
        }

        return true;
    }

    public function getSize()
    {
        if ($this->size === null) {
            foreach ($this->files as $file) {
                // We must be able to ascertain the size of each attached file
                if (null === ($size = $file->getContent()->getSize())) {
                    return null;
                }
                $this->size += strlen($this->getFileHeaders($file)) + $size;
            }
            foreach (array_keys($this->fields) as $key) {
                $this->size += strlen($this->getFieldString($key));
            }
            $this->size += strlen("\r\n--{$this->boundary}--");
        }

        return $this->size;
    }

    public function read($length)
    {
        $content = '';
        if ($this->buffer && !$this->buffer->eof()) {
            $content .= $this->buffer->read($length);
        }
        if ($delta = $length - strlen($content)) {
            $content .= $this->readData($delta);
        }

        if ($content === '' && !$this->sentLast) {
            $this->sentLast = true;
            $content = "\r\n--{$this->boundary}--";
        }

        return $content;
    }

    public function seek($offset, $whence = SEEK_SET)
    {
        if ($offset != 0 || $whence != SEEK_SET || !$this->isSeekable()) {
            return false;
        }

        foreach ($this->files as $file) {
            if (!$file->getContent()->seek(0)) {
                throw new \RuntimeException('Rewind on multipart file failed '
                    . 'even though it shouldn\'t have');
            }
        }

        $this->buffer = $this->sentLast = null;
        $this->pos = $this->currentField = $this->currentFile = 0;
        $this->bufferedHeaders = [];

        return true;
    }

    public function write($string)
    {
        return false;
    }

    /**
     * No data is in the read buffer, so more needs to be pulled in from fields
     * and files.
     *
     * @param int $length Amount of data to read
     *
     * @return string
     */
    private function readData($length)
    {
        $result = '';

        if ($this->currentField < count($this->fields)) {
            $result = $this->readField($length);
        }

        if ($result === '' && $this->currentFile < count($this->files)) {
            $result = $this->readFile($length);
        }

        return $result;
    }

    /**
     * Create a new stream buffer and inject form-data
     *
     * @param int $length Amount of data to read from the stream buffer
     *
     * @return string
     */
    private function readField($length)
    {
        $name = array_keys($this->fields)[++$this->currentField - 1];
        $this->buffer = Stream\create($this->getFieldString($name));

        return $this->buffer->read($length);
    }

    /**
     * Read data from a POST file, fill the read buffer with any overflow
     *
     * @param int $length Amount of data to read from the file
     *
     * @return string
     */
    private function readFile($length)
    {
        $current = $this->files[$this->currentFile];

        // Got to the next file and recursively return the read value, or bail
        // if no more data can be read.
        if ($current->getContent()->eof()) {
            return ++$this->currentFile == count($this->files)
                ? ''
                : $this->readFile($length);
        }

        // If this is the start of a file, then send the headers to the read
        // buffer.
        if (!isset($this->bufferedHeaders[$this->currentFile])) {
            $this->buffer = Stream\create($this->getFileHeaders($current));
            $this->bufferedHeaders[$this->currentFile] = true;
        }

        // More data needs to be read to meet the limit, so pull from the file
        $content = $this->buffer ? $this->buffer->read($length) : '';
        if (($remaining = $length - strlen($content)) > 0) {
            $content .= $current->getContent()->read($remaining);
        }

        return $content;
    }

    private function getFieldString($key)
    {
        return sprintf(
            "--%s\r\nContent-Disposition: form-data; name=\"%s\"\r\n\r\n%s\r\n",
            $this->boundary,
            $key,
            $this->fields[$key]
        );
    }

    private function getFileHeaders(PostFileInterface $file)
    {
        $headers = '';
        foreach ($file->getHeaders() as $key => $value) {
            $headers .= "{$key}: {$value}\r\n";
        }

        return "--{$this->boundary}\r\n" . trim($headers) . "\r\n\r\n";
    }
}
