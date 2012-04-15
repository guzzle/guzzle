<?php

namespace Guzzle\Service;

use Guzzle\Service\Inflector;
use Guzzle\Common\Collection;
use Guzzle\Common\NullObject;
use Guzzle\Http\Client as HttpClient;
use Guzzle\Service\Command\CommandInterface;
use Guzzle\Service\Command\CommandSet;
use Guzzle\Service\Description\ServiceDescription;

/**
 * Client object for executing commands on a web service.
 */
class Client extends HttpClient implements ClientInterface
{
    const MAGIC_CALL_DISABLED = 0;
    const MAGIC_CALL_RETURN = 1;
    const MAGIC_CALL_EXECUTE = 2;

    /**
     * @var ServiceDescription Description of the service and possible commands
     */
    protected $serviceDescription;

    /**
     * @var string Setting to use for magic method calls
     */
    protected $magicMethodBehavior = false;

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
        return new self($config['base_url'], $config);
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
            throw new \BadMethodCallException("Missing method $method.  Enable"
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
     * @throws \InvalidArgumentException if no command can be found by name
     */
    public function getCommand($name, array $args = array())
    {
        $command = null;

        // If a service description is present, see if a command is defined
        if ($this->serviceDescription && $this->serviceDescription->hasCommand($name)) {
            $command = $this->serviceDescription->createCommand($name, $args);
        }

        // Check if a concrete command exists using inflection
        if (!$command) {
            // Determine the class to instantiate based on the namespace of the
            // current client and the default location of commands
            $prefix = $this->getConfig('command.prefix');
            if (!$prefix) {
                // The prefix can be specified in a factory method and is cached
                $prefix = implode('\\', array_slice(explode('\\', get_class($this)), 0, -1)) . '\\Command\\';
                $this->getConfig()->set('command.prefix', $prefix);
            }

            $class = $prefix . str_replace(' ', '\\', ucwords(str_replace('.', ' ', Inflector::camel($name))));

            // Create the concrete command if it exists
            if (class_exists($class)) {
                $command = new $class($args);
            }
        }

        if (!$command) {
            throw new \InvalidArgumentException("$name command could not be found");
        }

        $command->setClient($this);
        $this->dispatch('client.command.create', array(
            'client'  => $this,
            'command' => $command
        ));

        return $command;
    }

    /**
     * Execute a command and return the response
     *
     * @param CommandInterface|CommandSet|array $command Command or set to execute
     *
     * @return mixed Returns the result of the executed command's
     *       {@see CommandInterface::getResult} method if a CommandInterface is
     *       passed, or the CommandSet itself if a CommandSet is passed
     * @throws \InvalidArgumentException if an invalid command is passed
     * @throws Command\CommandSetException if a set contains commands associated
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
                    throw new Command\CommandSetException(
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

        throw new \InvalidArgumentException('Invalid command sent to ' . __METHOD__);
    }

    /**
     * Set the service description of the client
     *
     * @param ServiceDescription $service Service description that describes
     *      all of the commands and information of the client
     *
     * @return Client
     */
    public function setDescription(ServiceDescription $service)
    {
        $this->serviceDescription = $service;

        return $this;
    }

    /**
     * Get the service description of the client
     *
     * @return ServiceDescription|NullObject
     */
    public function getDescription()
    {
        return $this->serviceDescription ?: new NullObject();
    }
}