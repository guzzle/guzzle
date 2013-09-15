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
     * Sets the body of the message.
     *
     * The body MUST be a StreamInterface object. Setting the body to null MUST
     * remove the existing body.
     *
     * @param StreamInterface|null $body Body.
     *
     * @return self Returns the message.
     */
    public function setBody(StreamInterface $body = null);

    /**
     * Get the body of the message
     *
     * @return StreamInterface|null
     */
    public function getBody();
}
