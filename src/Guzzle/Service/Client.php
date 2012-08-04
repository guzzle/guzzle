<?php

namespace Guzzle\Service;

use Guzzle\Common\Collection;
use Guzzle\Common\Exception\InvalidArgumentException;
use Guzzle\Common\Exception\BadMethodCallException;
use Guzzle\Common\Inflection\InflectorInterface;
use Guzzle\Common\Inflection\Inflector;
use Guzzle\Http\Client as HttpClient;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Service\Command\CommandInterface;
use Guzzle\Service\Command\Factory\CompositeFactory;
use Guzzle\Service\Command\Factory\ServiceDescriptionFactory;
use Guzzle\Service\Command\Factory\FactoryInterface as CommandFactoryInterface;
use Guzzle\Service\Resource\ResourceIteratorInterface;
use Guzzle\Service\Resource\ResourceIteratorClassFactory;
use Guzzle\Service\Resource\ResourceIteratorFactoryInterface;
use Guzzle\Service\Description\ServiceDescription;

/**
 * Client object for executing commands on a web service.
 */
class Client extends HttpClient implements ClientInterface
{
    /**
     * @var ServiceDescription Description of the service and possible commands
     */
    protected $serviceDescription;

    /**
     * @var string Setting to use for magic method calls
     */
    protected $magicMethodBehavior = false;

    /**
     * @var CommandFactoryInterface
     */
    protected $commandFactory;

    /**
     * @var ResourceIteratorFactoryInterface
     */
    protected $resourceIteratorFactory;

    /**
     * @var InflectorInterface Inflector associated with the service/client
     */
    protected $inflector;

    /**
     * Basic factory method to create a new client.  Extend this method in
     * subclasses to build more complex clients.
     *
     * @param array|Collection $config Configuration data
     *
     * @return Client
     */
    public static function factory($config = array())
    {
        return new static($config['base_url'], $config);
    }

    /**
     * {@inheritdoc}
     */
    public static function getAllEvents()
    {
        return array_merge(HttpClient::getAllEvents(), array(
            'client.command.create',
            'command.before_prepare',
            'command.before_send',
            'command.after_send'
        ));
    }

    /**
     * Helper method to find and execute a command.  Magic method calls must be
     * enabled on the client to use this functionality.
     *
     * @param string $method Name of the command object to instantiate
     * @param array  $args   Arguments to pass to the command
     *
     * @return mixed
     * @throws BadMethodCallException when a command is not found or magic
     *     methods are disabled
     */
    public function __call($method, $args = null)
    {
        if ($this->magicMethodBehavior == self::MAGIC_CALL_DISABLED) {
            throw new BadMethodCallException(
                "Missing method {$method}.  Enable magic calls to use magic methods with command names."
            );
        }

        $command = $this->getCommand($method, isset($args[0]) ? $args[0] : array());

        return $this->magicMethodBehavior == self::MAGIC_CALL_RETURN ? $command : $this->execute($command);
    }

    /**
     * Set the behavior for missing methods
     *
     * @param int $behavior Behavior to use when a missing method is called.
     *     - Client::MAGIC_CALL_DISABLED: Disable magic method calls
     *     - Client::MAGIC_CALL_EXECUTE:  Execute commands and return the result
     *     - Client::MAGIC_CALL_RETURN:   Instantiate and return the command
     *
     * @return Client
     */
    public function setMagicCallBehavior($behavior)
    {
        $this->magicMethodBehavior = (int) $behavior;

        return $this;
    }

    /**
     * Get a command by name.  First, the client will see if it has a service
     * description and if the service description defines a command by the
     * supplied name.  If no dynamic command is found, the client will look for
     * a concrete command class exists matching the name supplied.  If neither
     * are found, an InvalidArgumentException is thrown.
     *
     * @param string $name Name of the command to retrieve
     * @param array  $args Arguments to pass to the command
     *
     * @return CommandInterface
     * @throws InvalidArgumentException if no command can be found by name
     */
    public function getCommand($name, array $args = array())
    {
        $command = $this->getCommandFactory()->factory($name, $args);
        if (!$command) {
            throw new InvalidArgumentException("Command was not found matching {$name}");
        }
        $command->setClient($this);
        $this->dispatch('client.command.create', array(
            'client'  => $this,
            'command' => $command
        ));

        return $command;
    }

    /**
     * Set the command factory used to create commands by name
     *
     * @param CommandFactoryInterface $factory Command factory
     *
     * @return Client
     */
    public function setCommandFactory(CommandFactoryInterface $factory)
    {
        $this->commandFactory = $factory;

        return $this;
    }

    /**
     * Set the resource iterator factory associated with the client
     *
     * @param ResourceIteratorFactoryInterface $factory Resource iterator factory
     *
     * @return Client
     */
    public function setResourceIteratorFactory(ResourceIteratorFactoryInterface $factory)
    {
        $this->resourceIteratorFactory = $factory;

        return $this;
    }

    /**
     * Get a resource iterator from the client.
     *
     * @param string|CommandInterface $command         Command class or command name.
     * @param array                   $commandOptions  Command options used when creating commands.
     * @param array                   $iteratorOptions Iterator options passed to the iterator when it is instantiated.
     *
     * @return ResourceIteratorInterface
     */
    public function getIterator($command, array $commandOptions = null, array $iteratorOptions = array())
    {
        if (!($command instanceof CommandInterface)) {
            $command = $this->getCommand($command, $commandOptions ?: array());
        }

        return $this->getResourceIteratorFactory()->build($command, $iteratorOptions);
    }

    /**
     * Execute one or more commands
     *
     * @param CommandInterface|array $command Command or array of commands to execute
     *
     * @return mixed Returns the result of the executed command or an array of
     *               commands if an array of commands was passed.
     * @throws InvalidArgumentException if an invalid command is passed
     */
    public function execute($command)
    {
        if ($command instanceof CommandInterface) {
            $command = array($command);
            $singleCommand = true;
        } elseif (is_array($command)) {
            $singleCommand = false;
        } else {
            throw new InvalidArgumentException('Command must be a command or array of commands');
        }

        $requests = array();

        foreach ($command as $c) {
            $event = array('command' => $c->setClient($this));
            $this->dispatch('command.before_prepare', $event);
            // Set the state to new if the command was previously executed
            $requests[] = $c->prepare()->setState(RequestInterface::STATE_NEW);
            $this->dispatch('command.before_send', $event);
        }

        if ($singleCommand) {
            $this->send($requests[0]);
        } else {
            $this->send($requests);
        }

        foreach ($command as $c) {
            $this->dispatch('command.after_send', array('command' => $c));
        }

        return $singleCommand ? end($command)->getResult() : $command;
    }

    /**
     * Set the service description of the client
     *
     * @param ServiceDescription $service Service description
     * @param bool $updateFactory Set to FALSE to not update the service description based
     *                            command factory if it is not already on the client.
     *
     * @return Client
     */
    public function setDescription(ServiceDescription $service, $updateFactory = true)
    {
        $this->serviceDescription = $service;

        // Add the service description factory to the factory chain if it is not set
        if ($updateFactory) {
            // Convert non chain factories to a chain factory
            if (!($this->getCommandFactory() instanceof CompositeFactory)) {
                $this->commandFactory = new CompositeFactory(array($this->commandFactory));
            }
            // Add a service description factory if one does not already exist
            if (!$this->commandFactory->has('Guzzle\\Service\\Command\\Factory\\ServiceDescriptionFactory')) {
                // Add the service description factory before the concrete factory
                $this->commandFactory->add(new ServiceDescriptionFactory($service), 'Guzzle\\Service\\Command\\Factory\\ConcreteClassFactory');
            } else {
                // Update an existing service description factory
                $factory = $this->commandFactory->find('Guzzle\\Service\\Command\\Factory\\ServiceDescriptionFactory');
                $factory->setServiceDescription($service);
            }
        }

        return $this;
    }

    /**
     * Get the service description of the client
     *
     * @return ServiceDescription|null
     */
    public function getDescription()
    {
        return $this->serviceDescription;
    }

    /**
     * Set the inflector used with the client
     *
     * @param InflectorInterface $inflector Inflection object
     *
     * @return Client
     */
    public function setInflector(InflectorInterface $inflector)
    {
        $this->inflector = $inflector;

        return $this;
    }

    /**
     * Get the inflector used with the client
     *
     * @return InflectorInterface
     */
    public function getInflector()
    {
        if (!$this->inflector) {
            $this->inflector = Inflector::getDefault();
        }

        return $this->inflector;
    }

    /**
     * Get the resource iterator factory associated with the client
     *
     * @return ResourceIteratorFactoryInterface
     */
    protected function getResourceIteratorFactory()
    {
        if (!$this->resourceIteratorFactory) {
            // Build the default resource iterator factory if one is not set
            $clientClass = get_class($this);
            $namespace = substr($clientClass, 0, strrpos($clientClass, '\\')) . '\\Model';
            $this->resourceIteratorFactory = new ResourceIteratorClassFactory($namespace);
        }

        return $this->resourceIteratorFactory;
    }

    /**
     * Get the command factory associated with the client
     *
     * @return CommandFactoryInterface
     */
    protected function getCommandFactory()
    {
        if (!$this->commandFactory) {
            $this->commandFactory = CompositeFactory::getDefaultChain($this);
        }

        return $this->commandFactory;
    }
}
