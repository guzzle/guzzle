<?php

namespace Guzzle\Service\Command;

use Guzzle\Http\EntityBodyInterface;
use Guzzle\Http\Message\Response;
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
        $response = $command->getRequest()->getResponse();

        // Account for hard coded content-type values specified in service descriptions
        if ($contentType = $command->get('command.expects')) {
            $response->setHeader('Content-Type', $contentType);
        } else {
            $contentType = (string) $response->getHeader('Content-Type');
        }

        return $this->parseForContentType($command, $response, $contentType);
    }

    /**
     * {@inheritdoc}
     */
    public function parseForContentType(AbstractCommand $command, Response $response, $contentType)
    {
        $result = $response;

        if ($body = $result->getBody()) {
            if (stripos($contentType, 'json') !== false) {
                $result = $this->parseJson($body);
            } if (stripos($contentType, 'xml') !== false) {
                if ($body = (string) $body) {
                    $result = new \SimpleXMLElement($body);
                }
            }
        }

        return $result;
    }

    /**
     * Parse a JSON response into an array
     *
     * @param EntityBodyInterface $body Body to parse
     *
     * @return array
     */
    protected function parseJson(EntityBodyInterface $body)
    {
        $decoded = json_decode((string) $body, true);
        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new JsonException('The response body can not be decoded to JSON', json_last_error());
        }

        return $decoded;
    }
}
