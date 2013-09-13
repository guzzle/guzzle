<?php

namespace Guzzle\Http\Message;

use Guzzle\Stream\StreamInterface;

/**
 * Request and response message interface
 */
interface MessageInterface extends HasHeadersInterface
{
    /**
     * Get a string representation of the message
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
     * @param null|string|resource|StreamInterface  $body Body of the message
     *
     * @return self
     * @throws \LogicException When the protocol version is < 1.1 and the
     *                         Content-Length cannot be determined.
     */
    public function setBody($body);

    /**
     * Get the body of the message
     *
     * @return StreamInterface|null
     */
    public function getBody();
}
