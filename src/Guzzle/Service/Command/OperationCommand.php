<?php

namespace Guzzle\Service\Command;

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
            $responseClass = $this->operation->getResponseClass();
            if ($responseClass && $this->operation->getServiceDescription()->getModel($responseClass)) {
                $this->responseParser = OperationResponseParser::getInstance();
            } else {
                $this->responseParser = DefaultResponseParser::getInstance();
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function process()
    {
        $this->result = $this->responseParser->parse($this);
    }
}
