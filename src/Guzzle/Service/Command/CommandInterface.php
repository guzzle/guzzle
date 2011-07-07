<?php

namespace Guzzle\Service\Command;

use Guzzle\Common\Collection;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Service\ClientInterface;
use Guzzle\Service\Description\ApiCommand;

/**
 * Command object to handle preparing and processing client requests and
 * responses of the requests
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
interface CommandInterface
{
    /**
     * Constructor
     *
     * @param array|Collection $parameters (optional) Collection of parameters
     *      to set on the command
     * @param ApiCommand $apiCommand (optional) Command definition from description
     */
    function __construct($parameters = null, ApiCommand $apiCommand = null);

    /**
     * Get the short form name of the command
     *
     * @return string
     */
    function getName();

    /**
     * Get the API command information about the command
     *
     * @return ApiCommand|NullObject
     */
    function getApiCommand();

    /**
     * Get whether or not the command can be batched
     *
     * @return bool
     */
    function canBatch();

    /**
     * Execute the command
     *
     * @return Command
     * @throws RuntimeException if a client has not been associated with the command
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
     * @throws RuntimeException if the command has not been executed
     */
    function getRequest();

    /**
     * Get the response object associated with the command
     *
     * @return Response
     * @throws RuntimeException if the command has not been executed
     */
    function getResponse();

    /**
     * Get the result of the command
     *
     * @return Response By default, commands return a Response
     *      object unless overridden in a subclass
     * @throws RuntimeException if the command has not been executed
     */
    function getResult();

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
     * @throws RuntimeException if a client object has not been set previously
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