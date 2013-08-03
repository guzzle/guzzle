<?php

namespace Guzzle\Http\Message;

use Guzzle\Common\Collection;
use Guzzle\Http\Header\HeaderFactory;
use Guzzle\Http\Header\HeaderFactoryInterface;
use Guzzle\Http\Header\HeaderInterface;
use Guzzle\Http\Mimetypes;
use Guzzle\Stream\Stream;
use Guzzle\Stream\StreamInterface;

/**
 * Abstract HTTP request/response message
 */
abstract class AbstractMessage implements MessageInterface
{
    use HasHeaders;

    /** @var StreamInterface Message body */
    protected $body;

    /** @var string HTTP protocol version of the message */
    private $protocolVersion = '1.1';

    public function __construct()
    {
        $this->initHeaders();
    }

    public function __toString()
    {
        return sprintf("%s\r\n%s\r\n\r\n%s", $this->getStartLine(), $this->headers, $this->body);
    }

    /**
     * Get a string representation of the start line and headers
     *
     * @return string
     */
    public function getRawHeaders()
    {
        return $this->getStartLine() . "\r\n" . $this->getHeaders();
    }

    /**
     * Set the header factory to use to create headers
     *
     * @param HeaderFactoryInterface $factory
     *
     * @return self
     */
    public function setHeaderFactory(HeaderFactoryInterface $factory)
    {
        $this->headerFactory = $factory;

        return $this;
    }

    public function addHeader($header, $value = null)
    {
        if (isset($this->headers[$header])) {
            return $this->headers[$header]->add($value);
        } elseif ($value instanceof HeaderInterface) {
            return $this->headers[$header] = $value;
        } else {
            return $this->headers[$header] = $this->headerFactory->createHeader($header, $value);
        }
    }

    public function addHeaders(array $headers)
    {
        foreach ($headers as $key => $value) {
            $this->addHeader($key, $value);
        }

        return $this;
    }

    public function getHeader($header)
    {
        return $this->headers[$header];
    }

    public function getHeaders()
    {
        return $this->headers;
    }

    public function setHeader($header, $value = null)
    {
        unset($this->headers[$header]);

        return $this->addHeader($header, $value);
    }

    public function setHeaders(array $headers)
    {
        $this->headers->clear();
        foreach ($headers as $key => $value) {
            $this->addHeader($key, $value);
        }

        return $this;
    }

    public function hasHeader($header)
    {
        return isset($this->headers[$header]);
    }

    public function removeHeader($header)
    {
        unset($this->headers[$header]);

        return $this;
    }

    public function setProtocolVersion($protocol)
    {
        $this->protocolVersion = $protocol;

        return $this;
    }

    public function getProtocolVersion()
    {
        return $this->protocolVersion;
    }

    public function setBody($body, $contentType = null)
    {
        if ($body === null) {
            // Setting a null body will remove the body of the request
            $this->body = null;
            $this->setHeader('Content-Length', 0);
            $this->removeHeader('Transfer-Encoding');
        } else {
            $this->body = Stream::factory($body);
            // Auto detect the Content-Type from the path of the request if possible
            if ($contentType === null && !$this->hasHeader('Content-Type')) {
                $contentType = Mimetypes::getInstance()->fromFilename($this->body->getUri());
            }
            if ($contentType) {
                $this->setHeader('Content-Type', $contentType);
            }
            // Set the Content-Length header if it can be determined
            $size = $this->body->getSize();
            if ($size !== null && $size !== false) {
                $this->setHeader('Content-Length', $size);
            }
        }

        return $this;
    }
}
