<?php

namespace Guzzle\Http\Message;

use Guzzle\Common\Collection;
use Guzzle\Http\Header\HeaderCollection;
use Guzzle\Http\Header\HeaderFactory;
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

    public function __construct(array $options = [])
    {
        $this->headerFactory = isset($options['header_factory'])
            ? $options['header_factory']
            : HeaderFactory::getDefaultFactory();

        $this->headers = new HeaderCollection();
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
            $this->removeHeader('Content-Length');
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
