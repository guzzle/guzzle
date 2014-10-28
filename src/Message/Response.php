<?php
namespace GuzzleHttp\Message;

use GuzzleHttp\Exception\ParseException;
use GuzzleHttp\Exception\XmlParseException;
use GuzzleHttp\Stream\StreamInterface;
use GuzzleHttp\Utils;

/**
 * Guzzle HTTP response object
 */
class Response extends AbstractMessage implements ResponseInterface
{
    /** @var array Mapping of status codes to reason phrases */
    private static $statusTexts = [
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
    ];

    /** @var string The reason phrase of the response (human readable code) */
    private $reasonPhrase;

    /** @var string The status code of the response */
    private $statusCode;

    /** @var string The effective URL that returned this response */
    private $effectiveUrl;

    /**
     * @param int|string      $statusCode The response status code (e.g. 200)
     * @param array           $headers    The response headers
     * @param StreamInterface $body       The body of the response
     * @param array           $options    Response message options
     *     - reason_phrase: Set a custom reason phrase
     *     - protocol_version: Set a custom protocol version
     */
    public function __construct(
        $statusCode,
        array $headers = [],
        StreamInterface $body = null,
        array $options = []
    ) {
        $this->statusCode = (int) $statusCode;
        $this->handleOptions($options);

        // Assume a reason phrase if one was not applied as an option
        if (!$this->reasonPhrase &&
            isset(self::$statusTexts[$this->statusCode])
        ) {
            $this->reasonPhrase = self::$statusTexts[$this->statusCode];
        }

        if ($headers) {
            $this->setHeaders($headers);
        }

        if ($body) {
            $this->setBody($body);
        }
    }

    public function getStatusCode()
    {
        return $this->statusCode;
    }

    public function setStatusCode($code)
    {
        return $this->statusCode = (int) $code;
    }

    public function getReasonPhrase()
    {
        return $this->reasonPhrase;
    }

    public function setReasonPhrase($phrase)
    {
        return $this->reasonPhrase = $phrase;
    }

    public function json(array $config = [])
    {
        try {
            return Utils::jsonDecode(
                (string) $this->getBody(),
                isset($config['object']) ? !$config['object'] : true,
                512,
                isset($config['big_int_strings']) ? JSON_BIGINT_AS_STRING : 0
            );
        } catch (\InvalidArgumentException $e) {
            throw new ParseException(
                $e->getMessage(),
                $this
            );
        }
    }

    public function xml(array $config = [])
    {
        $disableEntities = libxml_disable_entity_loader(true);
        $internalErrors = libxml_use_internal_errors(true);

        try {
            // Allow XML to be retrieved even if there is no response body
            $xml = new \SimpleXMLElement(
                (string) $this->getBody() ?: '<root />',
                isset($config['libxml_options']) ? $config['libxml_options'] : LIBXML_NONET,
                false,
                isset($config['ns']) ? $config['ns'] : '',
                isset($config['ns_is_prefix']) ? $config['ns_is_prefix'] : false
            );
            libxml_disable_entity_loader($disableEntities);
            libxml_use_internal_errors($internalErrors);
        } catch (\Exception $e) {
            libxml_disable_entity_loader($disableEntities);
            libxml_use_internal_errors($internalErrors);
            throw new XmlParseException(
                'Unable to parse response body into XML: ' . $e->getMessage(),
                $this,
                $e,
                (libxml_get_last_error()) ?: null
            );
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
    }

    /**
     * Accepts and modifies the options provided to the response in the
     * constructor.
     *
     * @param array $options Options array passed by reference.
     */
    protected function handleOptions(array &$options = [])
    {
        parent::handleOptions($options);
        if (isset($options['reason_phrase'])) {
            $this->reasonPhrase = $options['reason_phrase'];
        }
    }
}
