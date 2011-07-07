<?php

namespace Guzzle\Service\Description;

use Guzzle\Common\NullObject;
use Guzzle\Service\Command\CommandFactoryInterface;
use Guzzle\Service\Description\DynamicCommandFactory;

/**
 * A ServiceDescription stores service information based on a service document
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class ServiceDescription
{
    const DEFAULT_COMMAND_CLASS = 'Guzzle\\Service\\Command\\ClosureCommand';
    
    /**
     * @var array Array of ApiCommand objects
     */
    protected $commands = array();

    /**
     * @var CommandFactoryInterface
     */
    protected $commandFactory;

    /**
     * Create a new ServiceDescription
     *
     * @param array $commands (optional) Array of {@see ApiCommand} objects
     * @param CommandFactoryInterface (optional) Command factory to build
     *      dynamic commands.  Uses the DynamicCommandFactory by default.
     */
    public function __construct(array $commands = array(), CommandFactoryInterface $commandFactory = null)
    {
        $this->commands = $commands;
        if (!$commandFactory) {
            $commandFactory = new DynamicCommandFactory();
        }
        $this->commandFactory = $commandFactory;
    }

    /**
     * Get the API commands of the service
     *
     * @return array Returns an array of ApiCommand objects
     */
    public function getCommands()
    {
        return $this->commands;
    }

    /**
     * Check if the service has a command by name
     *
     * @param string $name Name of the command to check
     *
     * @return bool
     */
    public function hasCommand($name)
    {
        foreach ($this->commands as $command) {
            if ($command->getName() == $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get an API command by name
     *
     * @param string $name Name of the command
     *
     * @return ApiCommand|NullObject Returns an ApiCommand on success or a
     *      NullObject on error
     */
    public function getCommand($name)
    {
        foreach ($this->commands as $command) {
            if ($command->getName() == $name) {
                return $command;
            }
        }

        return new NullObject();
    }

    /**
     * Create a webservice command based on the service document
     *
     * @param string $command Name of the command to retrieve
     * @param array $args (optional) Arguments to pass to the command
     *
     * @return Command\CommandInterface
     * @throws InvalidArgumentException if the command was not found in the service doc
     */
    public function createCommand($name, array $args = array())
    {
        if (!$this->hasCommand($name)) {
            throw new \InvalidArgumentException($name . ' command not found');
        }

        return $this->commandFactory->createCommand($this->getCommand($name), $args);
    }
}