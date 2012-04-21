<?php

namespace Guzzle\Service\Command;

use Guzzle\Common\Event;
use Guzzle\Service\Exception\CommandSetException;
use Guzzle\Service\ClientInterface;
use Guzzle\Service\Command\CommandInterface;

/**
 * Container for sending sets of {@see CommandInterface}
 * objects through {@see ClientInterface} object.
 *
 * Commands from different services using different clients can be sent in
 * parallel if each command has an associated {@see ClientInterface} before
 * executing the set.
 */
class CommandSet implements \IteratorAggregate, \Countable
{
    /**
     * @var array Collections of CommandInterface objects
     */
    protected $commands = array();

    /**
     * Constructor
     *
     * @param array $commands (optional) Array of commands to add to the set
     */
    public function __construct(array $commands = null)
    {
        foreach ((array) $commands as $command) {
            $this->addCommand($command);
        }
    }

    /**
     * Add a command to the set
     *
     * @param CommandInterface $command Command object to add to the command set
     *
     * @return CommandSet
     */
    public function addCommand(CommandInterface $command)
    {
        $this->commands[] = $command;

        return $this;
    }

    /**
     * Implements Countable
     *
     * @return int
     */
    public function count()
    {
        return count($this->commands);
    }

    /**
     * Execute the command set
     *
     * @return CommandSet
     * @throws CommandSetException if any of the commands do not have an associated
     *      {@see ClientInterface} object
     */
    public function execute()
    {
        // Keep a list of all commands with no client
        $invalid = array_filter($this->commands, function($command) {
            return !$command->getClient();
        });

        // If any commands do not have a client, then throw an exception
        if (count($invalid)) {
            $e = new CommandSetException('Commands found with no associated client');
            $e->setCommands($invalid);
            throw $e;
        }

        // Execute all batched commands in parallel
        if (count($this->commands)) {
            $multis = array();
            // Prepare each request and send out client notifications
            foreach ($this->commands as $command) {
                $request = $command->prepare();
                $request->getParams()->set('command', $command);
                $request->getEventDispatcher()->addListener('request.complete', array($this, 'update'), -99999);
                $command->getClient()->dispatch('command.before_send', array(
                    'command' => $command
                ));
                $command->getClient()->getCurlMulti()->add($command->getRequest(), true);
                if (!in_array($command->getClient()->getCurlMulti(), $multis)) {
                    $multis[] = $command->getClient()->getCurlMulti();
                }
            }
            foreach ($multis as $multi) {
                $multi->send();
            }
        }

        return $this;
    }

    /**
     * Implements IteratorAggregate
     *
     * @return ArrayIterator
     */
    public function getIterator()
    {
        return new \ArrayIterator($this->commands);
    }

    /**
     * Get all of the attached commands
     *
     * @return array
     */
    public function getCommands()
    {
        return $this->commands;
    }

    /**
     * Check if the set contains a specific command
     *
     * @param string|CommandInterface $command Command object class name or
     *      concrete CommandInterface object
     *
     * @return bool
     */
    public function hasCommand($command)
    {
        return (bool) (count(array_filter($this->commands, function($value) use ($command) {
            return is_string($command) ? ($value instanceof $command) : ($value === $command);
        })) > 0);
    }

    /**
     * Remove a command from the set
     *
     * @param string|CommandInterface $command The command object or command
     *      class name to remove
     *
     * @return CommandSet
     */
    public function removeCommand($command)
    {
        $this->commands = array_values(array_filter($this->commands, function($value) use ($command) {
            return is_string($command) ? !($value instanceof $command) : ($value !== $command);
        }));

        return $this;
    }

    /**
     * Trigger the result of the command to be created as commands complete and
     * make sure the command isn't going to send more requests
     *
     * {@inheritdoc}
     */
    public function update(Event $event)
    {
        $request = $event['request'];
        $command = $request->getParams()->get('command');
        if ($command && $command->isExecuted()) {
            $request = $event['request'];
            $request->getEventDispatcher()->removeListener('request.complete', $this);
            $request->getParams()->remove('command');
            // Force the result to be processed
            $command->getResult();
            $command->getClient()->dispatch('command.after_send', array(
                'command' => $command
            ));
        }
    }
}
