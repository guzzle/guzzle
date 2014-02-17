<?php

namespace GuzzleHttp\Service;

use GuzzleHttp\Event\HasEmitterInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Service\Command\CommandInterface;
use GuzzleHttp\Service\Description\DescriptionInterface;

/**
 * Web service client interface
 */
interface ServiceClientInterface extends HasEmitterInterface
{
    /**
     * Get the HTTP client used to send requests for the web service client
     *
     * @return ClientInterface
     */
    public function getHttpClient();

    /**
     * Get a command by name. First, the client will see if it has a service
     * description and if the service description defines a command by the
     * supplied name. If no dynamic command is found, the client will look for
     * a concrete command class exists matching the name supplied. If neither
     * are found, an InvalidArgumentException is thrown.
     *
     * @param string $name Name of the command to retrieve
     * @param array  $args Arguments to pass to the command
     *
     * @return CommandInterface
     * @throws \InvalidArgumentException if no command can be found by name
     */
    public function getCommand($name, array $args = array());

    /**
     * Execute a single command
     *
     * @param CommandInterface $command Command to execut
     *
     * @return mixed Returns the result of the executed command
     */
    public function execute(CommandInterface $command);

    /**
     * Get the service description of the client
     *
     * @return DescriptionInterface
     */
    public function getDescription();
}
