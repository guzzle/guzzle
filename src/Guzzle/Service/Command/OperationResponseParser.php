<?php

namespace Guzzle\Service\Command;

use Guzzle\Http\Message\Response;
use Guzzle\Service\Command\LocationVisitor\VisitorFlyweight;
use Guzzle\Service\Command\LocationVisitor\Response\ResponseVisitorInterface;
use Guzzle\Service\Description\Parameter;
use Guzzle\Service\Description\OperationInterface;
use Guzzle\Service\Description\Operation;
use Guzzle\Service\Resource\Model;

/**
 * Response parser that attempts to marshal responses into an associative array based on models in a service description
 */
class OperationResponseParser extends DefaultResponseParser
{
    /**
     * @var VisitorFlyweight $factory Visitor factory
     */
    protected $factory;

    /**
     * @var self
     */
    protected static $instance;

    /**
     * Get a cached default instance of the Operation response parser that uses default visitors
     *
     * @return self
     * @codeCoverageIgnore
     */
    public static function getInstance()
    {
        if (!static::$instance) {
            static::$instance = new static(VisitorFlyweight::getInstance());
        }

        return static::$instance;
    }

    /**
     * @param VisitorFlyweight $factory Factory to use when creating visitors
     */
    public function __construct(VisitorFlyweight $factory)
    {
        $this->factory = $factory;
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
        $this->factory->addResponseVisitor($location, $visitor);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    protected function handleParsing(AbstractCommand $command, Response $response, $contentType)
    {
        $operation = $command->getOperation();
        $model = $operation->getResponseType() == OperationInterface::TYPE_MODEL
            ? $operation->getServiceDescription()->getModel($operation->getResponseClass())
            : null;

        if (!$model) {
            // Return basic processing if the responseType is not model or the model cannot be found
            return parent::handleParsing($command, $response, $contentType);
        } elseif ($command->get(AbstractCommand::RESPONSE_PROCESSING) != AbstractCommand::TYPE_MODEL) {
            // Returns a model with no visiting if the command response processing is not model
            return new Model(parent::handleParsing($command, $response, $contentType), $model);
        } else {
            return new Model($this->visitResult($model, $command, $response), $model);
        }
    }

    /**
     * Perform transformations on the result array
     *
     * @param Parameter        $model    Model that defines the structure
     * @param CommandInterface $command  Command that performed the operation
     * @param Response         $response Response received
     *
     * @return array Returns the array of result data
     */
    protected function visitResult(
        Parameter $model,
        CommandInterface $command,
        Response $response
    ) {
        // Determine what visitors are associated with the model
        $foundVisitors = $result = array();

        foreach ($model->getProperties() as $schema) {
            if ($location = $schema->getLocation()) {
                $foundVisitors[$location] = $this->factory->getResponseVisitor($location);
                $foundVisitors[$location]->before($command, $result);
            }
        }

        foreach ($model->getProperties() as $schema) {
            /** @var $arg Parameter */
            if ($location = $schema->getLocation()) {
                // Apply the parameter value with the location visitor
                $foundVisitors[$location]->visit($command, $response, $schema, $result);
            }
        }

        foreach ($foundVisitors as $visitor) {
            $visitor->after($command);
        }

        return $result;
    }
}
