<?php

namespace Guzzle\Service\Command;

use Guzzle\Service\Description\OperationInterface;

/**
 * A command that creates requests based on {@see Guzzle\Service\Description\OperationInterface} objects, and if the
 * matching operation uses a service description model in the responseClass attribute, then this command will marshal
 * the response into an associative array based on the JSON schema of the model.
 */
class OperationCommand extends AbstractCommand
{
    /**
     * @var RequestSerializerInterface
     */
    protected $requestSerializer;

    /**
     * @var ResponseParserInterface Response parser
     */
    protected $responseParser;

    /**
     * Set the response parser used with the command
     *
     * @param ResponseParserInterface $parser Response parser
     *
     * @return self
     */
    public function setResponseParser(ResponseParserInterface $parser)
    {
        $this->responseParser = $parser;

        return $this;
    }

    /**
     * Set the request serializer used with the command
     *
     * @param RequestSerializerInterface $serializer Request serializer
     *
     * @return self
     */
    public function setRequestSerializer(RequestSerializerInterface $serializer)
    {
        $this->requestSerializer = $serializer;

        return $this;
    }

    /**
     * Get the request serializer used with the command
     *
     * @return RequestSerializerInterface
     */
    public function getRequestSerializer()
    {
        if (!$this->requestSerializer) {
            $this->requestSerializer = DefaultRequestSerializer::getInstance();
        }

        return $this->requestSerializer;
    }

    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        // Prepare and serialize the request
        $this->request = $this->getRequestSerializer()->prepare($this);

        // If no response parser is set, add the default parser if a model matching the responseClass is found
        if (!$this->responseParser) {
            $this->responseParser = $this->operation->getResponseType() == OperationInterface::TYPE_MODEL
                && $this->get(self::RESPONSE_PROCESSING) == self::TYPE_MODEL
                ? OperationResponseParser::getInstance()
                : DefaultResponseParser::getInstance();
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function process()
    {
        // Do not process the response if 'command.raw_response' is set
        $this->result = $this->get(self::RESPONSE_PROCESSING) != self::TYPE_RAW
            ? $this->responseParser->parse($this)
            : $this->request->getResponse();
    }
}
