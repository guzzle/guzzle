<?php

namespace Guzzle\Http\Message;

use Guzzle\Http\Mimetypes;
use Guzzle\Stream\MetadataStreamInterface;
use Guzzle\Stream\StreamInterface;

abstract class AbstractMessage implements MessageInterface
{
    use HasHeadersTrait;

    /** @var StreamInterface Message body */
    private $body;

    /** @var string HTTP protocol version of the message */
    private $protocolVersion = '1.1';

    /**
     * Clones the message, ensuring that headers are cloned
     */
    public function __clone()
    {
        $this->headers = array_map(function ($header) {
            return clone $header;
        }, $this->headers);
    }

    public function __toString()
    {
        $result = $this->getStartLine();
        foreach ($this->getHeaders() as $name => $value) {
            $result .= "\r\n{$name}: {$value}";
        }

        return $result . "\r\n\r\n" . $this->body;
    }

    public function getProtocolVersion()
    {
        return $this->protocolVersion;
    }

    public function getBody()
    {
        return $this->body;
    }

    public function setBody(StreamInterface $body = null)
    {
        if ($body === null) {
            // Setting a null body will remove the body of the request
            $this->body = null;
            $this->removeHeader('Content-Length');
            $this->removeHeader('Transfer-Encoding');
        } else {

            $this->body = $body;

            // Set the Content-Length header if it can be determined
            $size = $this->body->getSize();
            if ($size !== null && !$this->hasHeader('Content-Length')) {
                $this->setHeader('Content-Length', $size);
            }

            // Add the content-type if possible based on the stream URI
            if ($body instanceof MetadataStreamInterface && !$this->hasHeader('Content-Type')) {
                if ($uri = $body->getMetadata('uri')) {
                    if ($contentType = Mimetypes::getInstance()->fromFilename($uri)) {
                        $this->setHeader('Content-Type', $contentType);
                    }
                }
            }
        }

        return $this;
    }

    /**
     * Returns the start line of a message.
     *
     * @return string
     */
    abstract protected function getStartLine();

    /**
     * Accepts and modifies the options provided to the message in the
     * constructor.
     *
     * Can be overridden in subclasses as necessary.
     *
     * @param array $options Options array passed by reference.
     */
    protected function handleOptions(array &$options)
    {
        if (isset($options['protocol_version'])) {
            $this->protocolVersion = $options['protocol_version'];
        }
    }
}
