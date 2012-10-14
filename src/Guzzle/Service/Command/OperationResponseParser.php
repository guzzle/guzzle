<?php

namespace Guzzle\Service\Command;

use Guzzle\Http\Message\Response;
use Guzzle\Service\Command\LocationVisitor\Response\HeaderVisitor;
use Guzzle\Service\Command\LocationVisitor\Response\StatusCodeVisitor;
use Guzzle\Service\Command\LocationVisitor\Response\ReasonPhraseVisitor;
use Guzzle\Service\Command\LocationVisitor\Response\BodyVisitor;
use Guzzle\Service\Command\LocationVisitor\Response\JsonVisitor;
use Guzzle\Service\Command\LocationVisitor\Response\XmlVisitor;
use Guzzle\Service\Command\LocationVisitor\Response\ResponseVisitorInterface;
use Guzzle\Service\Description\Parameter;
use Guzzle\Service\Resource\Model;

/**
 * Response parser that attempts to marshal responses into an associative array based on models in a service description
 */
class OperationResponseParser extends DefaultResponseParser
{
    /**
     * @var array Location visitors attached to the command
     */
    protected $visitors = array();

    /**
     * @var array Cached instance with default visitors
     */
    protected static $instance;

    /**
     * Get a default instance that includes that default location visitors
     *
     * @return self
     * @codeCoverageIgnore
     */
    public static function getInstance()
    {
        if (!static::$instance) {
            static::$instance = new static(array(
                'statusCode'   => new StatusCodeVisitor(),
                'reasonPhrase' => new ReasonPhraseVisitor(),
                'header'       => new HeaderVisitor(),
                'body'         => new BodyVisitor(),
                'json'         => new JsonVisitor(),
                'xml'          => new XmlVisitor()
            ));
        }

        return static::$instance;
    }

    /**
     * @param array $visitors Visitors to attach
     */
    public function __construct(array $visitors = array())
    {
        $this->visitors = $visitors;
    }

    /**
     * Add a location visitor to the command
     *
     * @param string                   $location Location to associate with the visitor
     * @param ResponseVisitorInterface $visitor  Visitor to attach
     *
     * @return self
     */
    public function addVisitor($location, ResponseVisitorInterface $visitor)
    {
        $this->visitors[$location] = $visitor;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function parseForContentType(AbstractCommand $command, Response $response, $contentType)
    {
        $operation = $command->getOperation();

        $model = $operation->getResponseType() == 'model'
            && $command->get(AbstractCommand::RESPONSE_PROCESSING) == AbstractCommand::TYPE_MODEL
            ? $operation->getServiceDescription()->getModel($operation->getResponseClass())
            : null;

        // No further processing is needed if the responseType is not model or using native responses, or the model
        // cannot be found
        if (!$model) {
            return parent::parseForContentType($command, $response, $contentType);
        }

        $result = null;
        if ($body = $response->getBody()) {
            if (stripos($contentType, 'json') !== false) {
                $result = $this->parseJson($body);
            } if (stripos($contentType, 'xml') !== false) {
                $result = json_decode(json_encode(new \SimpleXMLElement((string) $body)), true);
            }
        }

        if ($result === null) {
            $result = array();
        }

        // Perform transformations on the result using location visitors
        return $this->visitResult($model, $command, $response, $result);
    }

    /**
     * Perform transformations on the result array
     *
     * @param Parameter        $model    Model that defines the structure
     * @param CommandInterface $command  Command that performed the operation
     * @param Response         $response Response received
     * @param array            $result   Result array
     * @param mixed            $context  Parsing context
     *
     * @return Model
     */
    protected function visitResult(
        Parameter $model,
        CommandInterface $command,
        Response $response,
        array &$result,
        $context = null
    ) {
        foreach ($model->getProperties() as $schema) {
            /** @var $arg Parameter */
            $location = $schema->getLocation();
            // Visit with the associated visitor
            if (isset($this->visitors[$location])) {
                // Apply the parameter value with the location visitor
                $this->visitors[$location]->visit($command, $response, $schema, $result);
            }
        }

        foreach ($this->visitors as $visitor) {
            $visitor->after($command);
        }

        return new Model($result, $model);
    }
}
