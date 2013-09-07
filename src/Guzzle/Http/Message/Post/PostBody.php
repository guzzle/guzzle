<?php

namespace Guzzle\Http\Message\Post;

use Guzzle\Http\Message\RequestInterface;
use Guzzle\Stream\StreamFactory;
use Guzzle\Stream\ReadableStreamInterface;
use Guzzle\Stream\StreamMetadataTrait;
use Guzzle\Url\QueryAggregator\PhpAggregator;
use Guzzle\Url\QueryAggregator\QueryAggregatorInterface;
use Guzzle\Url\QueryString;

/**
 * Holds POST fields and files and creates a streaming body when read methods are called on the object.
 */
class PostBody implements PostBodyInterface
{
    use StreamMetadataTrait;

    private $body;
    private $fields = [];
    private $files = [];
    private $aggregator;
    private $size;
    private $forceMultipart = false;

    /**
     * Applies request headers to a request based on the POST state
     *
     * @param RequestInterface $request Request to update
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
     * Set to true to force a multipart upload even if there are no files
     *
     * @param bool $force Set to true to force multipart uploads or false to remove this flag
     *
     * @return self
     */
    public function forceMultipartUpload($force)
    {
        $this->forceMultipart = $force;

        return $this;
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

    public function setField($name, $value)
    {
        $this->fields[$name] = $value;
        $this->mutate();

        return $this;
    }

    public function replaceFields(array $fields)
    {
        $this->fields = $fields;
        $this->mutate();

        return $this;
    }

    public function getField($name)
    {
        return isset($this->fields[$name]) ? $this->fields[$name] : null;
    }

    public function removeField($name)
    {
        unset($this->fields[$name]);
        $this->mutate();

        return $this;
    }

    public function getFields()
    {
        return $this->fields;
    }

    public function hasField($name)
    {
        return isset($this->fields[$name]);
    }

    public function getFiles()
    {
        return $this->files;
    }

    public function addFile(PostFileInterface $file)
    {
        $this->files[] = $file;
        $this->mutate();

        return $this;
    }

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

    public function detach()
    {
        $this->body = null;

        return $this;
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

    public function isSeekable()
    {
        return true;
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

    public function read($length)
    {
        return $this->getBody()->read($length);
    }

    /**
     * Return a stream object that is built from the POST fields and files. If one has already been
     * created, the previously created stream will be returned.
     */
    protected function getBody()
    {
        if ($this->body) {
            return $this->body;
        } elseif ($this->files || $this->forceMultipart) {
            return $this->body = $this->createMultipart();
        } elseif ($this->fields) {
            return $this->body = $this->createUrlEncoded();
        } else {
            return $this->body = StreamFactory::create('');
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
        $fields = $this->fields;
        $query = (new QueryString())
            ->setEncodingType(false)
            ->setAggregator($this->getAggregator());

        // Account for fields with an array value
        foreach ($fields as $name => &$field) {
            if (is_array($field)) {
                $field = (string) $query->replace([$name => $field]);
            }
        }

        return new MultipartBody($fields, $this->files);
    }

    /**
     * Creates an application/x-www-form-urlencoded stream body
     *
     * @return ReadableStreamInterface
     */
    private function createUrlEncoded()
    {
        $query = (new QueryString($this->fields))
            ->setAggregator($this->getAggregator())
            ->setEncodingType(QueryString::RFC1738);

        return StreamFactory::create($query);
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
