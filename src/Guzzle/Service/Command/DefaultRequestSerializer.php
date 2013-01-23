<?php

namespace Guzzle\Service\Command;

use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Url;
use Guzzle\Parser\ParserRegistry;
use Guzzle\Service\Command\LocationVisitor\Request\RequestVisitorInterface;
use Guzzle\Service\Command\LocationVisitor\VisitorFlyweight;

/**
 * Default request serializer that transforms command options and operation parameters into a request
 */
class DefaultRequestSerializer implements RequestSerializerInterface
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
     * Get a cached default instance of the class
     *
     * @return self
     * @codeCoverageIgnore
     */
    public static function getInstance()
    {
        if (!self::$instance) {
            self::$instance = new self(VisitorFlyweight::getInstance());
        }

        return self::$instance;
    }

    /**
     * @param VisitorFlyweight $factory Factory to use when creating visitors
     */
    public function __construct(VisitorFlyweight $factory)
    {
        $this->factory = $factory;
    }

    /**
     * Add a location visitor to the serializer
     *
     * @param string                   $location Location to associate with the visitor
     * @param RequestVisitorInterface  $visitor  Visitor to attach
     *
     * @return self
     */
    public function addVisitor($location, RequestVisitorInterface $visitor)
    {
        $this->factory->addRequestVisitor($location, $visitor);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function prepare(CommandInterface $command)
    {
        $request = $this->createRequest($command);
        // Keep an array of visitors found in the operation
        $foundVisitors = array();

        // Add arguments to the request using the location attribute
        foreach ($command->getOperation()->getParams() as $name => $arg) {
            /** @var $arg \Guzzle\Service\Description\Parameter */
            if ($location = $arg->getLocation()) {
                // Skip 'uri' locations because they've already been processed
                if ($location == 'uri') {
                    continue;
                }
                // Instantiate visitors as they are detected in the properties
                if (!isset($foundVisitors[$location])) {
                    $foundVisitors[$location] = $this->factory->getRequestVisitor($location);
                }
                // Ensure that a value has been set for this parameter
                $value = $command->get($name);
                if ($value !== null) {
                    // Apply the parameter value with the location visitor
                    $foundVisitors[$location]->visit($command, $request, $arg, $value);
                }
            }
        }

        // Call the after method on each visitor found in the operation
        foreach ($foundVisitors as $visitor) {
            $visitor->after($command, $request);
        }

        return $request;
    }

    /**
     * Create a request for the command and operation
     *
     * @param CommandInterface $command Command to create a request for
     *
     * @return RequestInterface
     */
    protected function createRequest(CommandInterface $command)
    {
        $operation = $command->getOperation();
        $client = $command->getClient();

        // If the command does not specify a template, then assume the base URL of the client
        if (!($uri = $operation->getUri())) {
            return $client->createRequest($operation->getHttpMethod(), $client->getBaseUrl());
        }

        // Get the path values and use the client config settings
        $variables = array();
        foreach ($operation->getParams() as $name => $arg) {
            if ($arg->getLocation() == 'uri') {
                if ($command->hasKey($name)) {
                    $variables[$name] = $arg->filter($command->get($name));
                    if (!is_array($variables[$name])) {
                        $variables[$name] = (string) $variables[$name];
                    }
                }
            }
        }

        // Merge the client's base URL with an expanded URI template
        return $client->createRequest(
            $operation->getHttpMethod(),
            (string) Url::factory($client->getBaseUrl())
                ->combine(ParserRegistry::getInstance()->getParser('uri_template')->expand($uri, $variables))
        );
    }
}
