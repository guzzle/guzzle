<?php

namespace Guzzle\Http\Message;

use Guzzle\Stream\StreamInterface;

/**
 * Request and response message interface
 */
interface MessageInterface extends HasHeadersInterface
{
    /**
     * Get a string represenation of the message
     *
     * @return string
     */
    public function __toString();

    /**
     * Get the HTTP protocol version of the message
     *
     * @return string
     */
    public function getProtocolVersion();

    /**
     * Set the body of the message
     *
     * @param null|string|resource|StreamInterface  $body        Body of the message
     * @param string                                $contentType Content-Type to set. Leave null to use an existing
     *                                                           Content-Type or to guess the Content-Type
     * @return self
     * @throws \LogicException when set on a request if the protocol is < 1.1 and Content-Length cannot be determined
     */
    public function setBody($body, $contentType = null);

    /**
     * Get the body of the message
     *
     * @return StreamInterface|null
     */
    public function getBody();
}
