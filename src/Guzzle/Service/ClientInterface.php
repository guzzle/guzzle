<?php

namespace Guzzle\Service;

use Guzzle\Common\Collection;
use Guzzle\Common\Exception\InvalidArgumentException;
use Guzzle\Http\ClientInterface as HttpClientInterface;
use Guzzle\Service\Command\CommandInterface;
use Guzzle\Service\Command\CommandSet;
use Guzzle\Service\Description\ServiceDescription;
use Guzzle\Service\Command\Factory\FactoryInterface as CommandFactoryInterface;

/**
 * Client interface for executing commands on a web service.
 */
interface ClientInterface extends HttpClientInterface
{
    const MAGIC_CALL_DISABLED = 0;
    const MAGIC_CALL_RETURN = 1;
    const MAGIC_CALL_EXECUTE = 2;

    /**
     * Basic factory method to create a new client.  Extend this method in
     * subclasses to build more complex clients.
     *
     * @param array|Collection $config (optional) Configuration data
     *
     * @return ClientInterface
     */
    static function factory($config);

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
    function getCommand($name, array $args = array());

    /**
     * Execute a command and return the response
     *
     * @param CommandInterface|CommandSet $command The command or set to execute
     *
     * @return mixed Returns the result of the executed command's
     *       {@see CommandInterface::getResult} method if a CommandInterface is
     *       passed, or the CommandSet itself if a CommandSet is passed
     * @throws InvalidArgumentException if an invalid command is passed
     * @throws Command\CommandSetException if a set contains commands associated
     *      with other clients
     */
    function execute($command);

    /**
     * Set the service description of the client
     *
     * @param ServiceDescription $service Service description that describes
     *     all of the commands and information of the client
     * @param bool $updateFactory (optional) Set to FALSE to not update the service
     *     description based command factory if it is not already present on
     *     the client
     *
     * @return ClientInterface
     */
    function setDescription(ServiceDescription $service, $updateFactory = true);

    /**
     * Get the service description of the client
     *
     * @return ServiceDescription|null
     */
    function getDescription();

    /**
     * Get the command factory associated with the client
     *
     * @return CommandFactoryInterface
     */
    function getCommandFactory();

    /**
     * Set the command factory used to create commands by name
     *
     * @param CommandFactoryInterface $factory Command factory
     *
     * @return ClientInterface
     */
    function setCommandFactory(CommandFactoryInterface $factory);
}
