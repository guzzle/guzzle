<?php
namespace GuzzleHttp\Message;

use GuzzleHttp\Stream\StreamInterface;

abstract class AbstractMessage implements MessageInterface
{
    /** @var array HTTP header collection */
    private $headers = [];

    /** @var array mapping a lowercase header name to its name over the wire */
    private $headerNames = [];

    /** @var StreamInterface Message body */
    private $body;

    /** @var string HTTP protocol version of the message */
    private $protocolVersion = '1.1';

    public function __toString()
    {
        return static::getStartLineAndHeaders($this)
            . "\r\n\r\n" . $this->getBody();
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
            $this->removeHeader('Content-Length')
                ->removeHeader('Transfer-Encoding');
        }

        $this->body = $body;

        return $this;
    }

    public function addHeader($header, $value)
    {
        static $valid = ['string' => true, 'integer' => true,
            'double' => true, 'array' => true];

        $type = gettype($value);
        if (!isset($valid[$type])) {
            throw new \InvalidArgumentException('Invalid header value');
        }

        if ($type == 'array') {
            $current = array_merge($this->getHeader($header, true), $value);
        } else {
            $current = $this->getHeader($header, true);
            $current[] = $value;
        }

        return $this->setHeader($header, $current);
    }

    public function addHeaders(array $headers)
    {
        foreach ($headers as $name => $header) {
            $this->addHeader($name, $header);
        }
    }

    public function getHeader($header, $asArray = false)
    {
        $name = strtolower($header);

        if (!isset($this->headers[$name])) {
            return $asArray ? [] : '';
        }

        return $asArray
            ? $this->headers[$name]
            : implode(', ', $this->headers[$name]);
    }

    public function getHeaders()
    {
        $headers = [];
        foreach ($this->headers as $name => $values) {
            $headers[$this->headerNames[$name]] = $values;
        }

        return $headers;
    }

    public function setHeader($header, $value)
    {
        $header = trim($header);
        $name = strtolower($header);
        $this->headerNames[$name] = $header;

        switch (gettype($value)) {
            case 'string':
                $this->headers[$name] = [trim($value)];
                break;
            case 'integer':
            case 'double':
                $this->headers[$name] = [(string) $value];
                break;
            case 'array':
                foreach ($value as &$v) {
                    $v = trim($v);
                }
                $this->headers[$name] = $value;
                break;
            default:
                throw new \InvalidArgumentException('Invalid header value '
                    . 'provided: ' . var_export($value, true));
        }

        return $this;
    }

    public function setHeaders(array $headers)
    {
        $this->headers = $this->headerNames = [];
        foreach ($headers as $key => $value) {
            $this->setHeader($key, $value);
        }

        return $this;
    }

    public function hasHeader($header)
    {
        return isset($this->headers[strtolower($header)]);
    }

    public function removeHeader($header)
    {
        $name = strtolower($header);
        unset($this->headers[$name], $this->headerNames[$name]);

        return $this;
    }

    /**
     * Parse an array of header values containing ";" separated data into an
     * array of associative arrays representing the header key value pair
     * data of the header. When a parameter does not contain a value, but just
     * contains a key, this function will inject a key with a '' string value.
     *
     * @param MessageInterface $message That contains the header
     * @param string           $header  Header to retrieve from the message
     *
     * @return array Returns the parsed header values.
     */
    public static function parseHeader(MessageInterface $message, $header)
    {
        static $trimmed = "\"'  \n\t\r";
        $params = $matches = [];

        foreach (self::normalizeHeader($message, $header) as $val) {
            $part = [];
            foreach (preg_split('/;(?=([^"]*"[^"]*")*[^"]*$)/', $val) as $kvp) {
                if (preg_match_all('/<[^>]+>|[^=]+/', $kvp, $matches)) {
                    $m = $matches[0];
                    if (isset($m[1])) {
                        $part[trim($m[0], $trimmed)] = trim($m[1], $trimmed);
                    } else {
                        $part[] = trim($m[0], $trimmed);
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
     * @param MessageInterface $message That contains the header
     * @param string              $header  Header to retrieve from the message
     *
     * @return array Returns the normalized header field values.
     */
    public static function normalizeHeader(MessageInterface $message, $header)
    {
        $h = $message->getHeader($header, true);
        for ($i = 0, $total = count($h); $i < $total; $i++) {
            if (strpos($h[$i], ',') === false) {
                continue;
            }
            foreach (preg_split('/,(?=([^"]*"[^"]*")*[^"]*$)/', $h[$i]) as $v) {
                $h[] = trim($v);
            }
            unset($h[$i]);
        }

        return $h;
    }

    /**
     * Gets the start-line and headers of a message as a string
     *
     * @param MessageInterface $message
     *
     * @return string
     */
    public static function getStartLineAndHeaders(MessageInterface $message)
    {
        return static::getStartLine($message)
            . self::getHeadersAsString($message);
    }

    /**
     * Gets the headers of a message as a string
     *
     * @param MessageInterface $message
     *
     * @return string
     */
    public static function getHeadersAsString(MessageInterface $message)
    {
        $result  = '';
        foreach ($message->getHeaders() as $name => $values) {
            $result .= "\r\n{$name}: " . implode(', ', $values);
        }

        return $result;
    }

    /**
     * Gets the start line of a message
     *
     * @param MessageInterface $message
     *
     * @return string
     * @throws \InvalidArgumentException
     */
    public static function getStartLine(MessageInterface $message)
    {
        if ($message instanceof RequestInterface) {
            return trim($message->getMethod() . ' '
                . $message->getResource())
                . ' HTTP/' . $message->getProtocolVersion();
        } elseif ($message instanceof ResponseInterface) {
            return 'HTTP/' . $message->getProtocolVersion() . ' '
                . $message->getStatusCode() . ' '
                . $message->getReasonPhrase();
        } else {
            throw new \InvalidArgumentException('Unknown message type');
        }
    }

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
