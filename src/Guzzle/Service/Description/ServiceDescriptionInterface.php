<?php

namespace Guzzle\Service\Description;

/**
 * A ServiceDescription stores service information based on a service document
 */
interface ServiceDescriptionInterface extends \Serializable
{
    const DEFAULT_COMMAND_CLASS = 'Guzzle\\Service\\Command\\DynamicCommand';

    /**
     * Get the API commands of the service
     *
     * @return array Returns an array of {@see ApiCommandInterface} objects
     */
    public function getCommands();

    /**
     * Check if the service has a command by name
     *
     * @param string $name Name of the command to check
     *
     * @return bool
     */
    public function hasCommand($name);

    /**
     * Get an API command by name
     *
     * @param string $name Name of the command
     *
     * @return ApiCommandInterface|null
     */
    public function getCommand($name);
}
