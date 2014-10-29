<?php
namespace GuzzleHttp\Post;

use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Stream\Exception\CannotAttachException;
use GuzzleHttp\Stream\StreamInterface;
use GuzzleHttp\Stream\Stream;
use GuzzleHttp\Query;

/**
 * Holds POST fields and files and creates a streaming body when read methods
 * are called on the object.
 */
class PostBody implements PostBodyInterface
{
    /** @var StreamInterface */
    private $body;

    /** @var callable */
    private $aggregator;

    private $fields = [];

    /** @var PostFileInterface[] */
    private $files = [];
    private $forceMultipart = false;
    private $detached = false;

    /**
     * Applies request headers to a request based on the POST state
     *
     * @param RequestInterface $request Request to update
     */
    public function applyRequestHeaders(RequestInterface $request)
    {
        if ($this->files || $this->forceMultipart) {
            $request->setHeader(
                'Content-Type',
                'multipart/form-data; boundary=' . $this->getBody()->getBoundary()
            );
        } elseif ($this->fields && !$request->hasHeader('Content-Type')) {
            $request->setHeader(
                'Content-Type',
                'application/x-www-form-urlencoded'
            );
        }

        if ($size = $this->getSize()) {
            $request->setHeader('Content-Length', $size);
        }
    }

    public function forceMultipartUpload($force)
    {
        $this->forceMultipart = $force;
    }

    public function setAggregator(callable $aggregator)
    {
        $this->aggregator = $aggregator;
    }

    public function setField($name, $value)
    {
        $this->fields[$name] = $value;
        $this->mutate();
    }

    public function replaceFields(array $fields)
    {
        $this->fields = $fields;
        $this->mutate();
    }

    public function getField($name)
    {
        return isset($this->fields[$name]) ? $this->fields[$name] : null;
    }

    public function removeField($name)
    {
        unset($this->fields[$name]);
        $this->mutate();
    }

    public function getFields($asString = false)
    {
        if (!$asString) {
            return $this->fields;
        }

        $query = new Query($this->fields);
        $query->setEncodingType(Query::RFC1738);
        $query->setAggregator($this->getAggregator());

        return (string) $query;
    }

    public function hasField($name)
    {
        return isset($this->fields[$name]);
    }

    public function getFile($name)
    {
        foreach ($this->files as $file) {
            if ($file->getName() == $name) {
                return $file;
            }
        }

        return null;
    }

    public function getFiles()
    {
        return $this->files;
    }

    public function addFile(PostFileInterface $file)
    {
        $this->files[] = $file;
        $this->mutate();
    }

    public function clearFiles()
    {
        $this->files = [];
        $this->mutate();
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

    public function getContents($maxLength = -1)
    {
        return $this->getBody()->getContents();
    }

    public function close()
    {
        $this->detach();
    }

    public function detach()
    {
        $this->detached = true;
        $this->fields = $this->files = [];

        if ($this->body) {
            $this->body->close();
            $this->body = null;
        }
    }

    public function attach($stream)
    {
        throw new CannotAttachException();
    }

    public function eof()
    {
        return $this->getBody()->eof();
    }

    public function tell()
    {
        return $this->body ? $this->body->tell() : 0;
    }

    public function isSeekable()
    {
        return true;
    }

    public function isReadable()
    {
        return true;
    }

    public function isWritable()
    {
        return false;
    }

    public function getSize()
    {
        return $this->getBody()->getSize();
    }

    public function seek($offset, $whence = SEEK_SET)
    {
        return $this->getBody()->seek($offset, $whence);
    }

    public function read($length)
    {
        return $this->getBody()->read($length);
    }

    public function write($string)
    {
        return false;
    }

    public function getMetadata($key = null)
    {
        return $key ? null : [];
    }

    /**
     * Return a stream object that is built from the POST fields and files.
     *
     * If one has already been created, the previously created stream will be
     * returned.
     */
    private function getBody()
    {
        if ($this->body) {
            return $this->body;
        } elseif ($this->files || $this->forceMultipart) {
            return $this->body = $this->createMultipart();
        } elseif ($this->fields) {
            return $this->body = $this->createUrlEncoded();
        } else {
            return $this->body = Stream::factory();
        }
    }

    /**
     * Get the aggregator used to join multi-valued field parameters
     *
     * @return callable
     */
    final protected function getAggregator()
    {
        if (!$this->aggregator) {
            $this->aggregator = Query::phpAggregator();
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
        // Flatten the nested query string values using the correct aggregator
        return new MultipartBody(
            call_user_func($this->getAggregator(), $this->fields),
            $this->files
        );
    }

    /**
     * Creates an application/x-www-form-urlencoded stream body
     *
     * @return StreamInterface
     */
    private function createUrlEncoded()
    {
        return Stream::factory($this->getFields(true));
    }

    /**
     * Get rid of any cached data
     */
    private function mutate()
    {
        $this->body = null;
    }
}
