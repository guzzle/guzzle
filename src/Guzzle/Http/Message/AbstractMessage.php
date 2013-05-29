<?php

namespace Guzzle\Http\Message;

use Guzzle\Common\Collection;
use Guzzle\Http\Message\Header\HeaderCollection;
use Guzzle\Http\Message\Header\HeaderFactory;
use Guzzle\Http\Message\Header\HeaderInterface;

/**
 * Abstract HTTP request/response message
 */
abstract class AbstractMessage implements MessageInterface
{
    /**
     * @var array HTTP header collection
     */
    protected $headers;

    /**
     * @var HeaderFactoryInterface $headerFactory
     */
    protected $headerFactory;

    /**
     * @var Collection Custom message parameters that are extendable by plugins
     */
    protected $params;

    /**
     * @var string HTTP protocol version of the message
     */
    protected $protocolVersion = '1.1';

    public function __construct()
    {
        $this->params = new Collection();
        $this->headerFactory = new HeaderFactory();
        $this->headers = new HeaderCollection();
    }

    /**
     * {@inheritdoc}
     */
    public function getParams()
    {
        return $this->params;
    }

    /**
     * {@inheritdoc}
     */
    public function addHeader($header, $value)
    {
        if (isset($this->headers[$header])) {
            $this->headers[$header]->add($value);
        } elseif ($value instanceof HeaderInterface) {
            $this->headers[$header] = $value;
        } else {
            $this->headers[$header] = $this->headerFactory->createHeader($header, $value);
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function addHeaders(array $headers)
    {
        foreach ($headers as $key => $value) {
            $this->addHeader($key, $value);
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getHeader($header)
    {
        return $this->headers[$header];
    }

    /**
     * {@inheritdoc}
     */
    public function getHeaders()
    {
        return $this->headers;
    }

    /**
     * {@inheritdoc}
     */
    public function getHeaderLines()
    {
        $headers = array();
        foreach ($this->headers as $value) {
            $headers[] = $value->getName() . ': ' . $value;
        }

        return $headers;
    }

    /**
     * {@inheritdoc}
     */
    public function setHeader($header, $value)
    {
        unset($this->headers[$header]);
        $this->addHeader($header, $value);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function setHeaders(array $headers)
    {
        $this->headers->clear();
        foreach ($headers as $key => $value) {
            $this->addHeader($key, $value);
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function hasHeader($header)
    {
        return isset($this->headers[$header]);
    }

    /**
     * {@inheritdoc}
     */
    public function removeHeader($header)
    {
        unset($this->headers[$header]);

        return $this;
    }

    /**
     * @deprecated Use $message->getHeader()->parseParams()
     */
    public function getTokenizedHeader($header, $token = ';')
    {
        return null;
    }

    /**
     * @deprecated
     */
    public function setTokenizedHeader($header, $data, $token = ';')
    {
        return $this;
    }

    /**
     * @deprecated
     */
    public function getCacheControlDirective($directive)
    {
        if (!($header = $this->getHeader('Cache-Control'))) {
            return null;
        }

        return $header->getDirective($directive);
    }

    /**
     * @deprecated
     */
    public function hasCacheControlDirective($directive)
    {
        if ($header = $this->getHeader('Cache-Control')) {
            return $header->hasDirective($directive);
        } else {
            return false;
        }
    }

    /**
     * @deprecated
     */
    public function addCacheControlDirective($directive, $value = true)
    {
        if (!($header = $this->getHeader('Cache-Control'))) {
            $this->addHeader('Cache-Control', '');
            $header = $this->getHeader('Cache-Control');
        }

        $header->addDirective($directive, $value);

        return $this;
    }

    /**
     * @deprecated
     */
    public function removeCacheControlDirective($directive)
    {
        if ($header = $this->getHeader('Cache-Control')) {
            $header->removeDirective($directive);
        }

        return $this;
    }
}
