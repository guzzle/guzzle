<?php

namespace Guzzle\Http\Message;

use Guzzle\Common\Collection;
use Guzzle\Http\Header\HeaderCollection;
use Guzzle\Http\Header\HeaderFactory;
use Guzzle\Http\Mimetypes;
use Guzzle\Stream\Stream;
use Guzzle\Stream\StreamInterface;

/**
 * HTTP request/response message trait
 */
trait MessageTrait
{
    use HasHeadersTrait;

    /** @var StreamInterface Message body */
    private $body;

    /** @var string HTTP protocol version of the message */
    private $protocolVersion = '1.1';

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

    private function initializeMessage(array $options = [])
    {
        $this->headerFactory = isset($options['header_factory'])
            ? $options['header_factory']
            : HeaderFactory::getDefaultFactory();

        $this->headers = new HeaderCollection();
    }
}
