<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service;

use Guzzle\Common\NullObject;

/**
 * A ServiceDescription stores service information based on a service document
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class ServiceDescription
{
    const DEFAULT_COMMAND_CLASS = 'Guzzle\\Service\\Command\\ClosureCommand';

    /**
     * @var string Name of the webservice
     */
    protected $name;
    
    /**
     * @var string ServiceDescription of the webservice
     */
    protected $description;

    /**
     * @var string Base URL of the webservice
     */
    protected $baseUrl;

    /**
     * @var array Array of ApiCommand objects
     */
    protected $commands = array();

    /**
     * @var array Arguments for a client constructor
     */
    protected $clientArgs = array();

    /**
     * Create a new ServiceDescription
     *
     * @param string $name Name of the service
     * @param string $description Service description
     * @param string $baseUrl Default service base URL
     * @param array $commands (optional) Array of {@see ApiCommand} objects
     * @param array $clientArgs (optional) Arguments of the class constructor
     */
    public function __construct($name, $description, $baseUrl, array $commands = array(), array $clientArgs = array())
    {
        $this->name = $name;
        $this->description = $description;
        $this->baseUrl = $baseUrl;
        $this->commands = $commands;
        $this->clientArgs = $clientArgs ?: array();
    }

    /**
     * Get the name of the service
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Get the description of the service
     *
     * @return string
     */
    public function getDescription()
    {
        return $this->description;
    }

    /**
     * Get the base URL of the service
     *
     * @return string
     */
    public function getBaseUrl()
    {
        return $this->baseUrl;
    }

    /**
     * Get the arguments of a client object
     *
     * @return array
     */
    public function getClientArgs()
    {
        return $this->clientArgs;
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
}