<?php

namespace Guzzle\Http;

use Guzzle\Stream\Stream;

/**
 * Abstract decorator used to wrap entity bodies
 */
class AbstractEntityBodyDecorator implements EntityBodyInterface
{
    /**
     * @var EntityBodyInterface Decorated entity body
     */
    protected $body;

    /**
     * Wrap an entity body
     *
     * @param EntityBodyInterface $body Entity body to decorate
     */
    public function __construct(EntityBodyInterface $body)
    {
        $this->body = $body;
    }

    /**
     * {@inheritdoc}
     */
    public function __toString()
    {
        return (string) $this->body;
    }

    /**
     * Allow decorators to implement custom methods
     *
     * @param string $method Missing method name
     * @param array  $args   Method arguments
     *
     * @return mixed
     */
    public function __call($method, array $args)
    {
        return call_user_func_array(array($this->body, $method), $args);
    }

    /**
     * {@inheritdoc}
     */
    public function close()
    {
        return $this->body->close();
    }

    /**
     * {@inheritdoc}
     */
    public function setRewindFunction($callable)
    {
        $this->body->setRewindFunction($callable);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function rewind()
    {
        return $this->body->rewind();
    }

    /**
     * {@inheritdoc}
     */
    public function compress($filter = 'zlib.deflate')
    {
        return $this->body->compress($filter);
    }

    /**
     * {@inheritdoc}
     */
    public function uncompress($filter = 'zlib.inflate')
    {
        return $this->body->uncompress($filter);
    }

    /**
     * {@inheritdoc}
     */
    public function getContentLength()
    {
        return $this->getSize();
    }

    /**
     * {@inheritdoc}
     */
    public function getContentType()
    {
        return $this->body->getContentType();
    }

    /**
     * {@inheritdoc}
     */
    public function getContentMd5($rawOutput = false, $base64Encode = false)
    {
        $hash = Stream::getHash($this, 'md5', $rawOutput);

        return $hash && $base64Encode ? base64_encode($hash) : $hash;
    }

    /**
     * {@inheritdoc}
     */
    public function getContentEncoding()
    {
        return $this->body->getContentEncoding();
    }

    /**
     * {@inheritdoc}
     */
    public function getMetaData($key = null)
    {
        return $this->body->getMetaData($key);
    }

    /**
     * {@inheritdoc}
     */
    public function getStream()
    {
        return $this->body->getStream();
    }

    /**
     * {@inheritdoc}
     */
    public function setStream($stream, $size = 0)
    {
        $this->body->setStream($stream, $size);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getWrapper()
    {
        return $this->body->getWrapper();
    }

    /**
     * {@inheritdoc}
     */
    public function getWrapperData()
    {
        return $this->body->getWrapperData();
    }

    /**
     * {@inheritdoc}
     */
    public function getStreamType()
    {
        return $this->body->getStreamType();
    }

    /**
     * {@inheritdoc}
     */
    public function getUri()
    {
        return $this->body->getUri();
    }

    /**
     * {@inheritdoc}
     */
    public function getSize()
    {
        return $this->body->getSize();
    }

    /**
     * {@inheritdoc}
     */
    public function isReadable()
    {
        return $this->body->isReadable();
    }

    /**
     * {@inheritdoc}
     */
    public function isWritable()
    {
        return $this->body->isWritable();
    }

    /**
     * {@inheritdoc}
     */
    public function isConsumed()
    {
        return $this->body->isConsumed();
    }

    /**
     * Alias of isConsumed()
     * {@inheritdoc}
     */
    public function feof()
    {
        return $this->isConsumed();
    }

    /**
     * {@inheritdoc}
     */
    public function isLocal()
    {
        return $this->body->isLocal();
    }

    /**
     * {@inheritdoc}
     */
    public function isSeekable()
    {
        return $this->body->isSeekable();
    }

    /**
     * {@inheritdoc}
     */
    public function setSize($size)
    {
        $this->body->setSize($size);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function seek($offset, $whence = SEEK_SET)
    {
        return $this->body->seek($offset, $whence);
    }

    /**
     * {@inheritdoc}
     */
    public function read($length)
    {
        return $this->body->read($length);
    }

    /**
     * {@inheritdoc}
     */
    public function write($string)
    {
        return $this->body->write($string);
    }

    /**
     * {@inheritdoc}
     */
    public function readLine($maxLength = null)
    {
        return $this->body->readLine($maxLength);
    }

    /**
     * {@inheritdoc}
     */
    public function ftell()
    {
        return $this->body->ftell();
    }

    /**
     * {@inheritdoc}
     */
    public function getCustomData($key)
    {
        return $this->body->getCustomData($key);
    }

    /**
     * {@inheritdoc}
     */
    public function setCustomData($key, $value)
    {
        $this->body->setCustomData($key, $value);

        return $this;
    }
}
