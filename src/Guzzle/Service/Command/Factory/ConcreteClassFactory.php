<?php

namespace Guzzle\Service\Command\Factory;

use Guzzle\Service\ClientInterface;
use Guzzle\Service\Inflector;

/**
 * Command factory used to create commands referencing concrete command classes
 */
class ConcreteClassFactory implements FactoryInterface
{
    /**
     * @var ClientInterface
     */
    protected $client;

    /**
     * @param ClientInterface $client Client that owns the commands
     */
    public function __construct(ClientInterface $client)
    {
        $this->client = $client;
    }

    /**
     * {@inheritdoc}
     */
    public function factory($name, array $args = array())
    {
        // Determine the class to instantiate based on the namespace of the
        // current client and the default location of commands
        $prefix = $this->client->getConfig('command.prefix');
        if (!$prefix) {
            // The prefix can be specified in a factory method and is cached
            $prefix = implode('\\', array_slice(explode('\\', get_class($this->client)), 0, -1)) . '\\Command\\';
            $this->client->getConfig()->set('command.prefix', $prefix);
        }

        $class = $prefix . str_replace(' ', '\\', ucwords(str_replace('.', ' ', Inflector::camel($name))));

        // Create the concrete command if it exists
        if (class_exists($class)) {
            return new $class($args);
        }
    }
}
