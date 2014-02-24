<?php

namespace GuzzleHttp\Service\Guzzle\Subscriber;

use GuzzleHttp\Event\SubscriberInterface;
use GuzzleHttp\Message\ResponseInterface;
use GuzzleHttp\Service\Guzzle\Description\Parameter;
use GuzzleHttp\Service\Guzzle\GuzzleCommandInterface;
use GuzzleHttp\Service\Guzzle\ResponseLocation\JsonLocation;
use GuzzleHttp\Service\ProcessEvent;
use GuzzleHttp\Service\Guzzle\ResponseLocation\ResponseLocationInterface;
use GuzzleHttp\Service\Guzzle\ResponseLocation\BodyLocation;
use GuzzleHttp\Service\Guzzle\ResponseLocation\StatusCodeLocation;
use GuzzleHttp\Service\Guzzle\ResponseLocation\ReasonPhraseLocation;
use GuzzleHttp\Service\Guzzle\ResponseLocation\HeaderLocation;
use GuzzleHttp\Service\Guzzle\ResponseLocation\XmlLocation;
use GuzzleHttp\Service\Model;

/**
 * Subscriber used to create response models based on an HTTP response and
 * a service description.
 *
 * Response location visitors are registered with this subscriber to handle
 * locations (e.g., 'xml', 'json', 'header'). All of the locations of a response
 * model that will be visited first have their ``before`` method triggered.
 * After the before method is called on every visitor that will be walked, each
 * visitor is triggered using the ``visit()`` method. After all of the visitors
 * are visited, the ``after()`` method is called on each visitor. This is the
 * place in which you should handle things like additionalProperties with
 * custom locations (i.e., this is how it is handled in the JSON visitor).
 */
class ProcessResponse implements SubscriberInterface
{
    /** @var ResponseLocationInterface[] */
    private $responseLocations;

    public static function getSubscribedEvents()
    {
        return ['process' => ['onProcess']];
    }

    /**
     * @param ResponseLocationInterface[] $responseLocations Extra response locations
     */
    public function __construct(array $responseLocations = [])
    {
        static $defaultResponseLocations;
        if (!$defaultResponseLocations) {
            $defaultResponseLocations = [
                'body'         => new BodyLocation(),
                'header'       => new HeaderLocation(),
                'reasonPhrase' => new ReasonPhraseLocation(),
                'statusCode'   => new StatusCodeLocation(),
                'xml'          => new XmlLocation(),
                'json'         => new JsonLocation()
            ];
        }

        $this->responseLocations = $responseLocations + $defaultResponseLocations;
    }

    public function onProcess(ProcessEvent $event)
    {
        $command = $event->getCommand();
        if (!($command instanceof GuzzleCommandInterface)) {
            throw new \RuntimeException('Invalid command');
        }

        $operation = $command->getOperation();
        $type = $operation->getResponseType();

        if ($type == 'class') {
            $event->setResult($this->createClass($event));
        } elseif ($type == 'primitive') {
            return;
        }

        $model = $operation->getServiceDescription()->getModel($operation->getResponseClass());

        if (!$model) {
            throw new \RuntimeException('No model found matching: '
                . $operation->getResponseClass());
        }

        $event->setResult(new Model($this->visit($model, $event)));
    }

    protected function createClass(ProcessEvent $event)
    {
        return null;
    }

    protected function visit(Parameter $model, ProcessEvent $event)
    {
        $result = [];
        $context = ['client' => $event->getClient(), 'visitors' => []];
        $command = $event->getCommand();
        $response = $event->getResponse();

        if ($model->getType() == 'object') {
            $this->visitOuterObject($model, $result, $command, $response, $context);
        } elseif ($model->getType() == 'array') {
            $this->visitOuterArray($model, $result, $command, $response, $context);
        } else {
            throw new \InvalidArgumentException('Invalid response model: ' . $model->getType());
        }

        // Call the after() method of each found visitor
        foreach ($context['visitors'] as $visitor) {
            $visitor->after($command, $response, $model, $result, $context);
        }

        return $result;
    }

    private function triggerBeforeVisitor(
        $location,
        Parameter $model,
        array &$result,
        GuzzleCommandInterface $command,
        ResponseInterface $response,
        array &$context
    ) {
        if (!isset($this->responseLocations[$location])) {
            throw new \RuntimeException("Unknown location: $location");
        }

        $context['visitors'][$location] = $this->responseLocations[$location];

        $this->responseLocations[$location]->before(
            $command,
            $response,
            $model,
            $result,
            $context
        );
    }

    private function visitOuterObject(
        Parameter $model,
        array &$result,
        GuzzleCommandInterface $command,
        ResponseInterface $response,
        array &$context
    ) {
        // If top-level additionalProperties is a schema, then visit it
        $additional = $model->getAdditionalProperties();
        if ($additional instanceof Parameter) {
            $this->triggerBeforeVisitor($additional->getLocation(), $model,
                $result, $command, $response, $context);
        }

        // Use 'location' from all individual defined properties
        $properties = $model->getProperties();
        foreach ($properties as $schema) {
            if ($location = $schema->getLocation()) {
                // Trigger the before method on each unique visitor location
                if (!isset($context['visitors'][$location])) {
                    $this->triggerBeforeVisitor($location, $model, $result,
                        $command, $response, $context);
                }
            }
        }

        // Actually visit each response element
        foreach ($properties as $schema) {
            if ($location = $schema->getLocation()) {
                $this->responseLocations[$location]->visit($command, $response,
                    $schema, $result, $context);
            }
        }
    }

    private function visitOuterArray(
        Parameter $model,
        array &$result,
        GuzzleCommandInterface $command,
        ResponseInterface $response,
        array &$context
    ) {
        // Use 'location' defined on the top of the model
        if (!($location = $model->getLocation())) {
            return;
        }

        if (!isset($foundVisitors[$location])) {
            $this->triggerBeforeVisitor($location, $model, $result,
                $command, $response, $context);
        }

        // Visit each item in the response
        $this->responseLocations[$location]->visit($command, $response,
            $model, $result, $context);
    }
}
