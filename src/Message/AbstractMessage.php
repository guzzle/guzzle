<?php

namespace GuzzleHttp\Message;

use GuzzleHttp\Mimetypes;
use GuzzleHttp\Stream\MetadataStreamInterface;
use GuzzleHttp\Stream\StreamInterface;

abstract class AbstractMessage implements MessageInterface
{
    use HasHeadersTrait;

    /** @var StreamInterface Message body */
    private $body;

    /** @var string HTTP protocol version of the message */
    private $protocolVersion = '1.1';

    public function __toString()
    {
        $result = $this->getStartLine();
        foreach ($this->getHeaders() as $name => $values) {
            $result .= "\r\n{$name}: " . implode(', ', $values);
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
     * Parse an array of header values containing ";" separated data into an
     * array of associative arrays representing the header key value pair
     * data of the header. When a parameter does not contain a value, but just
     * contains a key, this function will inject a key with a '' string value.
     *
     * @param HasHeadersInterface $message That contains the header
     * @param string              $header  Header to retrieve from the message
     *
     * @return array Returns the parsed header values.
     */
    public static function parseHeader(HasHeadersInterface $message, $header)
    {
        static $trimmed = "\"'  \n\t\r";
        $params = $matches = [];

        foreach (self::normalizeHeader($message, $header) as $val) {
            $part = [];
            foreach (preg_split('/;(?=([^"]*"[^"]*")*[^"]*$)/', $val) as $kvp) {
                if (preg_match_all('/<[^>]+>|[^=]+/', $kvp, $matches)) {
                    $pieces = $matches[0];
                    if (isset($pieces[1])) {
                        $part[trim($pieces[0], $trimmed)] = trim($pieces[1], $trimmed);
                    } else {
                        $part[] = trim($pieces[0], $trimmed);
                    }
                }
            }
            if ($part) {
                $params[] = $part;
            }
        }

        return $params;
    }

    /**
     * Converts an array of header values that may contain comma separated
     * headers into an array of headers with no comma separated values.
     *
     * @param HasHeadersInterface $message That contains the header
     * @param string              $header  Header to retrieve from the message
     *
     * @return array Returns the normalized header field values.
     */
    public static function normalizeHeader(HasHeadersInterface $message, $header)
    {
        $values = $message->getHeader($header, true);
        for ($i = 0, $total = count($values); $i < $total; $i++) {
            if (strpos($values[$i], ',') !== false) {
                foreach (preg_split('/,(?=([^"]*"[^"]*")*[^"]*$)/', $values[$i]) as $v) {
                    $values[] = trim($v);
                }
                unset($values[$i]);
            }
        }

        return $values;
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
