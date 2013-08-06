<?php

namespace Guzzle\Http\Message\Post;

use Guzzle\Http\Message\RequestInterface;
use Guzzle\Stream\Stream;
use Guzzle\Stream\StreamInterface;
use Guzzle\Url\QueryAggregator\PhpAggregator;
use Guzzle\Url\QueryAggregator\QueryAggregatorInterface;

/**
 * Holds POST fields and files and creates a streaming body when read methods are called on the object.
 */
class PostBody implements StreamInterface
{
    private $body;
    private $fields = [];
    private $files = [];
    private $metadata = [];
    private $aggregator;
    private $size;

    /**
     * Apply headers to the request appropriate for the current state of the object
     *
     * @param RequestInterface $request Request
     */
    public function applyRequestHeaders(RequestInterface $request)
    {
        if ($this->files) {
            $request->setHeader('Content-Type', 'multipart/form-data; boundary=' . $this->getBody()->getBoundary());
        } elseif ($this->fields) {
            $request->setHeader('Content-Type', 'application/x-www-form-urlencoded');
        }

        if ($size = $this->getSize()) {
            $request->setHeader('Content-Length', $size);
        }
    }

    /**
     * Set the aggregation strategy that will be used to turn multi-valued fields into a string
     *
     * @param QueryAggregatorInterface $aggregator
     */
    final public function setAggregator(QueryAggregatorInterface $aggregator)
    {
        $this->aggregator = $aggregator;
    }

    /**
     * Set a specific field
     *
     * @param string       $name  Name of the field to set
     * @param string|array $value Value to set
     *
     * @return $this
     */
    public function setField($name, $value)
    {
        $this->fields[$name] = $value;
        $this->mutate();

        return $this;
    }

    /**
     * Get a specific field by name
     *
     * @param string $name Name of the POST field to retrieve
     *
     * @return string|null
     */
    public function getField($name)
    {
        return isset($this->fields[$name]) ? $this->fields[$name] : null;
    }

    /**
     * Remove a field by name
     *
     * @param string $name Name of the field to remove
     *
     * @return $this
     */
    public function removeField($name)
    {
        unset($this->fields[$name]);
        $this->mutate();

        return $this;
    }

    /**
     * Returns an associative array of names to values
     *
     * @return array
     */
    public function getFields()
    {
        return $this->fields;
    }

    /**
     * Returns true if a field is set
     *
     * @param string $name Name of the field to set
     *
     * @return bool
     */
    public function hasField($name)
    {
        return isset($this->fields[$name]);
    }

    /**
     * Get all of the files
     *
     * @return array Returns an array of PostFileInterface objects
     */
    public function getFiles()
    {
        return $this->files;
    }

    /**
     * Add a file to the POST
     *
     * @param PostFileInterface $file File to add
     *
     * @return $this
     */
    public function addFile(PostFileInterface $file)
    {
        $this->files[] = $file;
        $this->mutate();

        return $this;
    }

    /**
     * Remove all files from the collection
     *
     * @return $this
     */
    public function clearFiles()
    {
        $this->files = [];
        $this->mutate();

        return $this;
    }

    /**
     * Returns the numbers of fields + files
     *
     * @return int
     */
    public function count()
    {
        return count($this->files) + count($this->fields);
    }

    public function __toString()
    {
        return (string) $this->getBody();
    }

    public function close()
    {
        return $this->body ? $this->body->close : true;
    }

    public function getMetadata($key = null)
    {
        if ($key === null) {
            return $this->metadata;
        } else {
            return isset($this->metadata[$key]) ? $this->metadata[$key] : null;
        }
    }

    public function setMetadata($key, $value)
    {
        $this->metadata[$key] = $value;

        return $this;
    }

    public function getStream()
    {
        return $this->getBody()->getStream();
    }

    public function detachStream()
    {
        $this->body = null;

        return $this;
    }

    public function getUri()
    {
        return null;
    }

    public function eof()
    {
        if ($this->body) {
            return $this->body->eof;
        } else {
            return (bool) ($this->fields ?: $this->files);
        }
    }

    public function tell()
    {
        return $this->body ? $this->body->tell() : 0;
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
        return true;
    }

    public function setSize($size)
    {
        $this->size = $size;

        return $this;
    }

    public function getSize()
    {
        if (!$this->size) {
            $this->size = $this->getBody()->getSize();
        }

        return $this->size;
    }

    public function seek($offset, $whence = SEEK_SET)
    {
        return $this->getBody()->seek($offset, $whence);
    }

    public function rewind()
    {
        return $this->body ? $this->getBody()->rewind() : true;
    }

    public function read($length)
    {
        return $this->getBody()->read($length);
    }

    public function readLine($maxLength = null)
    {
        return $this->getBody()->readLine($maxLength);
    }

    public function write($string)
    {
        return false;
    }

    /**
     * Return a stream object that is built from the POST fields and files. If one has already been
     * created, the previously created stream will be returned.
     */
    protected function getBody()
    {
        if ($this->body) {
            return $this->body;
        } elseif ($this->files) {
            return $this->body = $this->createMultipart();
        } elseif ($this->fields) {
            return $this->body = $this->createUrlEncoded();
        } else {
            return $this->body = Stream::fromString('');
        }
    }

    /**
     * Get the aggregator used to join multi-valued field parameters
     *
     * @return QueryAggregatorInterface
     */
    final protected function getAggregator()
    {
        if (!$this->aggregator) {
            $this->aggregator = new PhpAggregator();
        }

        return $this->aggregator;
    }

    /**
     * Creates a multipart/form-data body stream
     *
     * @return MultipartBody
     */
    private function createMultipart()
    {
        // Account for fields with an array value
        $fields = $this->fields;
        foreach ($fields as &$field) {
            if (is_array($field)) {
                $field = urldecode($this->getAggregator()->aggregate($field, PHP_QUERY_RFC1738));
            }
        }

        return new MultipartBody($this->fields, $this->files);
    }

    /**
     * Creates an application/x-www-form-urlencoded stream body
     *
     * @return Stream
     */
    private function createUrlEncoded()
    {
        return Stream::fromString($this->getAggregator()->aggregate($this->fields, PHP_QUERY_RFC1738));
    }

    /**
     * Get rid of any cached data
     */
    private function mutate()
    {
        $this->size = null;
        $this->body = null;
    }
}
