<?php

namespace Guzzle\Service\Command;

use Guzzle\Common\Event\Observer;
use Guzzle\Common\Event\Subject;
use Guzzle\Http\Pool\PoolInterface;
use Guzzle\Http\Pool\Pool;
use Guzzle\Service\ClientInterface;
use Guzzle\Service\Command\CommandInterface;

/**
 * Container for sending sets of {@see CommandInterface}
 * objects through {@see ClientInterface} object.
 *
 * Commands from different services using different clients can be sent in
 * parallel if each command has an associated {@see ClientInterface} before
 * executing the set.
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class CommandSet implements \IteratorAggregate, \Countable, Observer
{
    /**
     * @var array Collections of CommandInterface objects
     */
    protected $commands = array();

    /**
     * @var PoolInterface Pool used for sending the requests in Parallel
     */
    protected $pool;

    /**
     * Constructor
     *
     * @param array $commands (optional) Array of commands to add to the set
     * @param PoolInterface $pool (optional) Pass a Pool object for executing the
     *      commands in parallel.  Leave NULL to use the default Pool.
     */
    public function __construct(array $commands = null, PoolInterface $pool = null)
    {
        if ($commands) {
            foreach ($commands as $command) {
                $this->addCommand($command);
            }
        }

        $this->pool = $pool ?: new Pool();
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
        $invalid = array();
        foreach ($this->commands as $command) {
            if (!$command->getClient()) {
                $invalid[] = $command;
            }
        }

        // If any commands do not have a client, then throw an exception
        if (count($invalid)) {
            $e = new CommandSetException('Commands found with no associated client');
            $e->setCommands($invalid);
            throw $e;
        }

        // Execute all serial commands
        foreach ($this->getSerialCommands() as $command) {
            $command->execute();
            // Trigger the result of the command to be processed
            $command->getResult();
        }

        // Execute all batched commands in parallel
        $parallel = $this->getParallelCommands();
        if (count($parallel)) {
            $this->pool->reset();
            // Prepare each request and send out client notifications
            foreach ($parallel as $command) {
                $request = $command->prepare();
                $request->getParams()->set('command', $command);
                $request->getEventManager()->attach($this, -99999);
                $command->getClient()->getEventManager()->notify('command.before_send', $command);
                $this->pool->add($request);
            }
            $this->pool->send();
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
     * Get all of the attached commands that can be sent in parallel
     *
     * @return array
     */
    public function getParallelCommands()
    {
        return array_values(array_filter($this->commands, function($value) {
            return true === $value->canBatch();
        }));
    }

    /**
     * Get all of the attached commands that can not be sent in parallel
     *
     * @return array
     */
    public function getSerialCommands()
    {
        return array_values(array_filter($this->commands, function($value) {
            return false === $value->canBatch();
        }));
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
     * Trigger the result of the command to be created as commands complete
     *
     * {@inheritdoc}
     */
    public function update(Subject $subject, $event, $context = null)
    {
        if ($event == 'request.complete' && $subject->getParams()->hasKey('command')) {
            $command = $subject->getParams()->get('command');
            // Make sure the command isn't going to send more requests
            if ($command && $command->isExecuted()) {
                $subject->getEventManager()->detach($this);
                $subject->getParams()->remove('command');
                $command->getResult();
                $command->getClient()->getEventManager()->notify('command.after_send', $command);
            }
        }
    }
}