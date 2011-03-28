<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Command;

use Guzzle\Common\Collection;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Service\Client;
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
    public function __construct($parameters = null, ApiCommand $apiCommand = null);

    /**
     * Get the short form name of the command
     *
     * @return string
     */
    public function getName();

    /**
     * Get the API command information about the command
     *
     * @return ApiCommand|NullObject
     */
    public function getApiCommand();

    /**
     * Get whether or not the command can be batched
     *
     * @return bool
     */
    public function canBatch();

    /**
     * Execute the command
     *
     * @return Command
     * @throws RuntimeException if a client has not been associated with the command
     */
    public function execute();

    /**
     * Get the client object that will execute the command
     *
     * @return Client|null
     */
    public function getClient();

    /**
     * Set the client objec that will execute the command
     *
     * @param Client $client The client objec that will execute the command
     *
     * @return Command
     */
    public function setClient(Client $client);

    /**
     * Get the request object associated with the command
     *
     * @return RequestInterface
     * @throws RuntimeException if the command has not been executed
     */
    public function getRequest();

    /**
     * Get the response object associated with the command
     *
     * @return Response
     * @throws RuntimeException if the command has not been executed
     */
    public function getResponse();

    /**
     * Get the result of the command
     *
     * @return Response By default, commands return a Response
     *      object unless overridden in a subclass
     * @throws RuntimeException if the command has not been executed
     */
    public function getResult();

    /**
     * Returns TRUE if the command has been prepared for executing
     *
     * @return bool
     */
    public function isPrepared();

    /**
     * Returns TRUE if the command has been executed
     *
     * @return bool
     */
    public function isExecuted();

    /**
     * Prepare the command for executing and create a request object.
     *
     * @return RequestInterface Returns the generated request
     * @throws RuntimeException if a client object has not been set previously
     *      or in the prepare()
     */
    public function prepare();

    /**
     * Get the object that manages the request headers that will be set on any
     * outbound requests from the command
     *
     * @return Collection
     */
    public function getRequestHeaders();
}