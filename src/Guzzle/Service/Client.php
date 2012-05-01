<?php

namespace Guzzle\Service;

use Guzzle\Service\Inflector;
use Guzzle\Common\Collection;
use Guzzle\Common\Exception\InvalidArgumentException;
use Guzzle\Common\Exception\BadMethodCallException;
use Guzzle\Http\Client as HttpClient;
use Guzzle\Service\Exception\CommandSetException;
use Guzzle\Service\Command\CommandInterface;
use Guzzle\Service\Command\CommandSet;
use Guzzle\Service\Command\Factory\CompositeFactory;
use Guzzle\Service\Command\Factory\ServiceDescriptionFactory;
use Guzzle\Service\Command\Factory\FactoryInterface as CommandFactoryInterface;
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
     * Basic factory method to create a new client.  Extend this method in
     * subclasses to build more complex clients.
     *
     * @param array|Collection $config (optional) Configuartion data
     *
     * @return Client
     */
    public static function factory($config)
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
            'command.before_send',
            'command.after_send'
        ));
    }

    /**
     * Helper method to find and execute a command.  Magic method calls must be
     * enabled on the client to use this functionality.
     *
     * @param string $method Name of the command object to instantiate
     * @param array  $args   (optional) Arguments to pass to the command
     *
     * @return mixed
     * @throws BadMethodCallException when a command is not found or magic
     *     methods are disabled
     */
    public function __call($method, $args = null)
    {
        if ($this->magicMethodBehavior == self::MAGIC_CALL_DISABLED) {
            throw new BadMethodCallException("Missing method $method.  Enable"
                . " magic calls to use magic methods with command names.");
        }

        $command = $this->getCommand(Inflector::snake($method), $args);

        return $this->magicMethodBehavior == self::MAGIC_CALL_RETURN
            ? $command
            : $this->execute($command);
    }

    /**
     * Set the behavior for missing methods
     *
     * @param int $behavior Behavior to use when a missing method is called.
     *     Set to Client::MAGIC_CALL_DISABLED to disable magic method calls
     *     Set to Client::MAGIC_CALL_EXECUTE to execute commands and return the result
     *     Set to Client::MAGIC_CALL_RETURN to instantiate and return the command
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
     * @param array $args (optional) Arguments to pass to the command
     *
     * @return CommandInterface
     * @throws InvalidArgumentException if no command can be found by name
     */
    public function getCommand($name, array $args = array())
    {
        // Enable magic method calls on commands if it's enabled on the client
        if ($this->magicMethodBehavior) {
            $args['command.magic_method_call'] = true;
        }

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
     * Get the command factory associated with the client
     *
     * @return CommandFactoryInterface
     */
    public function getCommandFactory()
    {
        if (!$this->commandFactory) {
            $this->commandFactory = CompositeFactory::getDefaultChain($this);
        }

        return $this->commandFactory;
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
     * Execute a command and return the response
     *
     * @param CommandInterface|CommandSet|array $command Command or set to execute
     *
     * @return mixed Returns the result of the executed command's
     *       {@see CommandInterface::getResult} method if a CommandInterface is
     *       passed, or the CommandSet itself if a CommandSet is passed
     * @throws InvalidArgumentException if an invalid command is passed
     * @throws CommandSetException if a set contains commands associated
     *      with other clients
     */
    public function execute($command)
    {
        if ($command instanceof CommandInterface) {
            $command->setClient($this)->prepare();
            $this->dispatch('command.before_send', array(
                'command' => $command
            ));
            $command->getRequest()->send();
            $this->dispatch('command.after_send', array(
                'command' => $command
            ));
            return $command->getResult();
        } else if ($command instanceof CommandSet) {
            foreach ($command as $c) {
                if ($c->getClient() && $c->getClient() !== $this) {
                    throw new CommandSetException(
                        'Attempting to run a mixed-Client CommandSet from a ' .
                        'Client context.  Run the set using CommandSet::execute() '
                    );
                }
                $c->setClient($this);
            }
            return $command->execute();
        } else if (is_array($command)) {
            return $this->execute(new CommandSet($command));
        }

        throw new InvalidArgumentException('Invalid command sent to ' . __METHOD__);
    }

    /**
     * Set the service description of the client
     *
     * @param ServiceDescription $service Service description that describes
     *     all of the commands and information of the client
     * @param bool $updateFactory (optional) Set to FALSE to not update the service
     *     description based command factory if it is not already present on
     *     the client
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
}
