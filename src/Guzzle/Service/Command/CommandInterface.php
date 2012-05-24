<?php

namespace Guzzle\Service\Command;

use Guzzle\Common\Collection;
use Guzzle\Common\Exception\InvalidArgumentException;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Service\ClientInterface;
use Guzzle\Service\Description\ApiCommand;
use Guzzle\Service\Exception\CommandException;

/**
 * Command object to handle preparing and processing client requests and
 * responses of the requests
 */
interface CommandInterface
{
    /**
     * Constructor
     *
     * @param array|Collection $parameters Collection of parameters
     *      to set on the command
     * @param ApiCommand $apiCommand Command definition from description
     */
    function __construct($parameters = null, ApiCommand $apiCommand = null);

    /**
     * Specify a callable to execute when the command completes
     *
     * @param mixed $callable Callable to execute when the command completes.
     *     The callable must accept a {@see CommandInterface} object as the
     *     only argument.
     *
     * @return CommandInterface
     * @throws InvalidArgumentException
     */
    function setOnComplete($callable);

    /**
     * Get the short form name of the command
     *
     * @return string
     */
    function getName();

    /**
     * Get the API command information about the command
     *
     * @return ApiCommand
     */
    function getApiCommand();

    /**
     * Execute the command and return the result
     *
     * @return mixed Returns the result of {@see CommandInterface::execute}ß
     * @throws CommandException if a client has not been associated with the command
     */
    function execute();

    /**
     * Get the client object that will execute the command
     *
     * @return ClientInterface|null
     */
    function getClient();

    /**
     * Set the client objec that will execute the command
     *
     * @param ClientInterface $client The client objec that will execute the command
     *
     * @return Command
     */
    function setClient(ClientInterface $client);

    /**
     * Get the request object associated with the command
     *
     * @return RequestInterface
     * @throws CommandException if the command has not been executed
     */
    function getRequest();

    /**
     * Get the response object associated with the command
     *
     * @return Response
     * @throws CommandException if the command has not been executed
     */
    function getResponse();

    /**
     * Get the result of the command
     *
     * @return Response By default, commands return a Response
     *      object unless overridden in a subclass
     * @throws CommandException if the command has not been executed
     */
    function getResult();

    /**
     * Set the result of the command
     *
     * @param mixed $result Result to set
     *
     * @return self
     */
    function setResult($result);

    /**
     * Returns TRUE if the command has been prepared for executing
     *
     * @return bool
     */
    function isPrepared();

    /**
     * Returns TRUE if the command has been executed
     *
     * @return bool
     */
    function isExecuted();

    /**
     * Prepare the command for executing and create a request object.
     *
     * @return RequestInterface Returns the generated request
     * @throws CommandException if a client object has not been set previously
     *      or in the prepare()
     */
    function prepare();

    /**
     * Get the object that manages the request headers that will be set on any
     * outbound requests from the command
     *
     * @return Collection
     */
    function getRequestHeaders();
}
