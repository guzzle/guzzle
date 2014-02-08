<?php

namespace Guzzle\Service\Command;

use Guzzle\Common\HasEmitterInterface;
use Guzzle\Common\ToArrayInterface;
use Guzzle\Service\Description\OperationInterface;
use Guzzle\Service\Description\ModelInterface;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\ResponseInterface;

/**
 * A command object manages input and output of an operation using an
 * {@see OperationInterface} object.
 *
 * A command SHOULD emit the following events:
 * - command.prepare: Emitted when a command is preparing a request
 * - command.process: Emitted when a command is processing a response
 * - command.error: Emitted after an error occurs for a command
 */
interface CommandInterface extends \ArrayAccess, ToArrayInterface, HasEmitterInterface
{
    /**
     * Get the API operation information about the command
     *
     * @return OperationInterface
     */
    public function getOperation();

    /**
     * Get the processed result of the command.
     *
     * @return ModelInterface|mixed
     */
    public function getResult();

    /**
     * Prepares the command for sending
     *
     * If the command has not been prepared, it is prepared when called.
     *
     * @return RequestInterface Returns the request that will be sent
     */
    public function prepare();

    /**
     * Get the request associated with the command
     *
     * If the command has not been prepared, it is prepared when called.
     *
     * @return RequestInterface
     */
    public function getRequest();

    /**
     * Get the response that was received when the command was executed.
     *
     * @return ResponseInterface|null
     */
    public function getResponse();
}
