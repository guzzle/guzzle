<?php

namespace Guzzle\Service\Command;

use Guzzle\Service\Exception\JsonException;

/**
 * Default HTTP response parser used to marshal JSON responses into arrays and XML responses into SimpleXMLElement
 */
class DefaultResponseParser implements ResponseParserInterface
{
    /**
     * @var self
     */
    protected static $instance;

    /**
     * Get a cached instance of the default response parser
     *
     * @return self
     * @codeCoverageIgnore
     */
    public static function getInstance()
    {
        if (!self::$instance) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    /**
     * {@inheritdoc}
     */
    public function parse(CommandInterface $command)
    {
        // Uses the response object by default
        $result = $command->getRequest()->getResponse();

        if ($contentType = $result->getContentType()) {
            // Is the body an JSON document?  If so, set the result to be an array
            if (stripos($contentType, 'json') !== false) {
                if ($body = trim($result->getBody(true))) {
                    $decoded = json_decode($body, true);
                    if (JSON_ERROR_NONE !== json_last_error()) {
                        throw new JsonException('The response body can not be decoded to JSON', json_last_error());
                    }
                    $result = $decoded;
                }
            } if (stripos($contentType, 'xml') !== false) {
                // Is the body an XML document?  If so, set the result to be a SimpleXMLElement
                if ($body = trim($result->getBody(true))) {
                    $result = new \SimpleXMLElement($body);
                }
            }
        }

        return $result;
    }
}
