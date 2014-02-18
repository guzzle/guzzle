<?php

namespace GuzzleHttp\Service;

use GuzzleHttp\Event\HasEmitterInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Service\Description\DescriptionInterface;

/**
 * Web service client interface
 */
interface ServiceClientInterface extends HasEmitterInterface
{
    /**
     * Get the service description of the client
     *
     * @return DescriptionInterface
     */
    public function getDescription();

    /**
     * Create a command for an operation.
     *
     * @param string $name Name of the operation to use in the command
     * @param array  $args Arguments to pass to the command
     *
     * @return CommandInterface
     * @throws \InvalidArgumentException if no command can be found by name
     */
    public function getCommand($name, array $args = []);

    /**
     * Execute a single command.
     *
     * @param CommandInterface $command Command to execute
     *
     * @return mixed Returns the result of the executed command
     */
    public function execute(CommandInterface $command);

    /**
     * Execute multiple commands in parallel.
     *
     * @param array|\Iterator $commands Array or iterator that contains
     *     CommandInterface objects to execute.
     * @param array $options Associative array of options
     *     - parallel: (int) Max number of commands to send in parallel
     *     - before: (callable) Receives a CommandBeforeEvent
     *     - after: (callable) Receives a CommandCompleteEvent
     *     - error: (callable) Receives a CommandErrorEvent
     */
    public function executeAll($commands, array $options = []);

    /**
     * Get the HTTP client used to send requests for the web service client
     *
     * @return ClientInterface
     */
    public function getHttpClient();

    /**
     * Get a client configuration value.
     *
     * @param string|int|null $keyOrPath The Path to a particular configuration
     *     value. The syntax uses a path notation that allows you to retrieve
     *     nested array values without throwing warnings.
     *
     * @return mixed
     */
    public function getConfig($keyOrPath = null);

    /**
     * Set a client configuration value at the specified configuration path.
     *
     * @param string|int $keyOrPath Path at which to change a configuration
     *     value. This path syntax follows the same path syntax specified in
     *     {@see getConfig}.
     *
     * @param mixed $value Value to set
     */
    public function setConfig($keyOrPath, $value);
}
