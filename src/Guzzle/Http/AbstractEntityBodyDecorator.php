<?php

namespace Guzzle\Http;

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
     * Wrap a entity body
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
     * @codeCoverageIgnore
     */
    public function __call($method, array $args = null)
    {
        return call_user_func_array(array($this->body, $method), $args);
    }

    /**
     * {@inheritdoc}
     * @codeCoverageIgnore
     */
    public function compress($filter = 'zlib.deflate')
    {
        return $this->body->compress($filter);
    }

    /**
     * {@inheritdoc}
     * @codeCoverageIgnore
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
        return $this->body->getContentLength();
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
        return $this->body->getContentMd5($rawOutput, $base64Encode);
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
     * @codeCoverageIgnore
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
     * @codeCoverageIgnore
     */
    public function setSize($size)
    {
        return $this->body->setSize($size);
    }

    /**
     * {@inheritdoc}
     * @codeCoverageIgnore
     */
    public function seek($offset, $whence = SEEK_SET)
    {
        return $this->body->seek($offset, $whence);
    }

    /**
     * {@inheritdoc}
     * @codeCoverageIgnore
     */
    public function read($length)
    {
        return $this->body->read($length);
    }

    /**
     * {@inheritdoc}
     * @codeCoverageIgnore
     */
    public function write($string)
    {
        return $this->body->write($string);
    }

    /**
     * {@inheritdoc}
     * @codeCoverageIgnore
     */
    public function ftell()
    {
        return $this->body->ftell();
    }
}
