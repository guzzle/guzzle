<?php

namespace GuzzleHttp\Service;

use GuzzleHttp\Event\HasEmitterInterface;
use GuzzleHttp\ClientInterface;

/**
 * Web service client interface.
 *
 * Any event listener or subscriber added to the client is added to each
 * command created by the client when the command is created.
 */
interface ServiceClientInterface extends HasEmitterInterface
{
    /**
     * Invokes a command by name.
     *
     * Implementations may choose to implement other missing method calls as
     * well as executing commands by name.
     *
     * @param string $name      Name of the command
     * @param array  $arguments Arguments to pass to the command.
     * @throws CommandException
     */
    public function __call($name, array $arguments);

    /**
     * Create a command for an operation name.
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
     * @throws CommandException
     */
    public function execute(CommandInterface $command);

    /**
     * Execute multiple commands in parallel.
     *
     * @param array|\Iterator $commands Array or iterator that contains
     *     CommandInterface objects to execute.
     * @param array $options Associative array of options.
     *     - parallel: (int) Max number of commands to send in parallel
     *     - prepare: (callable) Receives a CommandPrepareEvent Concrete
     *       implementations MAY choose to implement this setting.
     *     - process: (callable) Receives a CommandProcessEvent. Concrete
     *       implementations MAY choose to implement this setting.
     *     - error: (callable) Receives a CommandErrorEvent. Concrete
     *       implementations MAY choose to implement this setting.
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
