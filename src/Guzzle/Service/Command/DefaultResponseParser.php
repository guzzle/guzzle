<?php

namespace Guzzle\Service\Command;

use Guzzle\Http\Message\Response;

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

        return $this->handleParsing($command, $response, $contentType);
    }

    /**
     * {@inheritdoc}
     */
    protected function handleParsing(AbstractCommand $command, Response $response, $contentType)
    {
        $result = $response;
        if ($result->getBody()) {
            if (stripos($contentType, 'json') !== false) {
                $result = $result->json();
            } if (stripos($contentType, 'xml') !== false) {
                $result = $result->xml();
            }
        }

        return $result;
    }
}
