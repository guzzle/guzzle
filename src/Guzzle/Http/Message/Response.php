<?php

namespace Guzzle\Http\Message;

use Guzzle\Stream\Stream;
use Guzzle\Stream\StreamInterface;

/**
 * Guzzle HTTP response object
 */
class Response implements ResponseInterface
{
    use MessageTrait;

    /** @var array Array of reason phrases and their corresponding status codes */
    private static $statusTexts = array(
        100 => 'Continue',
        101 => 'Switching Protocols',
        102 => 'Processing',
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        207 => 'Multi-Status',
        208 => 'Already Reported',
        226 => 'IM Used',
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        307 => 'Temporary Redirect',
        308 => 'Permanent Redirect',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Request Entity Too Large',
        414 => 'Request-URI Too Long',
        415 => 'Unsupported Media Type',
        416 => 'Requested Range Not Satisfiable',
        417 => 'Expectation Failed',
        422 => 'Unprocessable Entity',
        423 => 'Locked',
        424 => 'Failed Dependency',
        425 => 'Reserved for WebDAV advanced collections expired proposal',
        426 => 'Upgrade required',
        428 => 'Precondition Required',
        429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported',
        506 => 'Variant Also Negotiates (Experimental)',
        507 => 'Insufficient Storage',
        508 => 'Loop Detected',
        510 => 'Not Extended',
        511 => 'Network Authentication Required',
    );

    /** @var string The reason phrase of the response (human readable code) */
    private $reasonPhrase;

    /** @var string The status code of the response */
    private $statusCode;

    /** @var string The effective URL that returned this response */
    private $effectiveUrl;

    /**
     * Create a new Response based on a message string
     *
     * @param string $message Response message
     *
     * @return self
     * @throws \InvalidArgumentException if the message cannot be parsed
     */
    public static function fromMessage($message)
    {
        $parser = new MessageParser();
        if (!($data = $parser->parseResponse($message))) {
            throw new \InvalidArgumentException('Unable to parse response message');
        }

        $response = new static();
        $response->setStatus($data['code'])
            ->setHeaders($data['headers'])
            ->setProtocolVersion($data['version'])
            ->setStatus($data['code'], $data['reason_phrase']);

        if (strlen($data['body']) > 0) {
            $response->setBody($data['body']);
        }

        return $response;
    }

    /**
     * @param string                          $statusCode The response status code (e.g. 200, 404, etc)
     * @param array                           $headers    The response headers
     * @param string|resource|StreamInterface $body       The body of the response
     * @param array                           $options    Response message options
     *                                                    - header_factory: Factory used to create headers
     */
    public function __construct($statusCode = null, array $headers = null, $body = null, array $options = [])
    {
        $this->initializeMessage($options);
        if ($statusCode) {
            $this->setStatus($statusCode);
        }
        if ($headers) {
            $this->setHeaders($headers);
        }
        if ($body !== null) {
            $this->setBody($body);
        }
    }

    public function __toString()
    {
        $startLine = sprintf('HTTP/%s %d %s', $this->getProtocolVersion(), $this->statusCode, $this->reasonPhrase);

        return sprintf("%s\r\n%s\r\n\r\n%s", $startLine, $this->headers, $this->body);
    }

    public function getBody()
    {
        if (!$this->body) {
            $this->body = Stream::factory();
        }

        return $this->body;
    }

    public function setStatus($statusCode, $reasonPhrase = '')
    {
        $this->statusCode = (string) $statusCode;

        if (!$reasonPhrase && isset(self::$statusTexts[$this->statusCode])) {
            $this->reasonPhrase = self::$statusTexts[$this->statusCode];
        } else {
            $this->reasonPhrase = $reasonPhrase;
        }

        return $this;
    }

    public function getStatusCode()
    {
        return $this->statusCode;
    }

    public function getReasonPhrase()
    {
        return $this->reasonPhrase;
    }

    public function json()
    {
        $data = json_decode((string) $this->body, true);
        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new \RuntimeException('Unable to parse response body into JSON: ' . json_last_error());
        }

        return $data === null ? array() : $data;
    }

    /**
     * Parse the XML response body and return a \SimpleXMLElement.
     *
     * In order to prevent XXE attacks, this method disables loading external
     * entities. If you rely on external entities, then you must parse the
     * XML response manually by accessing the response body directly.
     *
     * @return \SimpleXMLElement
     * @throws RuntimeException if the response body is not in XML format
     * @link http://websec.io/2012/08/27/Preventing-XXE-in-PHP.html
     */
    public function xml()
    {
        $disableEntities = libxml_disable_entity_loader(true);

        try {
            // Allow XML to be retrieved even if there is no response body
            $xml = new \SimpleXMLElement((string) $this->body ?: '<root />');
            libxml_disable_entity_loader($disableEntities);
        } catch (\Exception $e) {
            libxml_disable_entity_loader($disableEntities);
            throw new \RuntimeException('Unable to parse response body into XML: ' . $e->getMessage());
        }

        return $xml;
    }

    public function getEffectiveUrl()
    {
        return $this->effectiveUrl;
    }

    public function setEffectiveUrl($url)
    {
        $this->effectiveUrl = $url;

        return $this;
    }
}
