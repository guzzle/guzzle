<?php

namespace Guzzle\Http\Message;

use Guzzle\Http\Mimetypes;
use Guzzle\Stream\HasMetadataStreamInterface;
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

    public function getProtocolVersion()
    {
        return $this->protocolVersion;
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
            if ($size !== null && $size !== false) {
                $this->setHeader('Content-Length', $size);
            }

            // Add the content-type if possible based on the stream URI
            if ($body instanceof HasMetadataStreamInterface && !$this->hasHeader('Content-Type')) {
                if ($uri = $body->getMetadata('uri')) {
                    if ($contentType = Mimetypes::getInstance()->fromFilename($uri)) {
                        $this->setHeader('Content-Type', $contentType);
                    }
                }
            }
        }

        return $this;
    }

    private function initializeMessage(array $options = [])
    {
        if (isset($options['protocol_version'])) {
            $this->protocolVersion = $options['protocol_version'];
        }
    }
}
