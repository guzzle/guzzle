<?php

namespace GuzzleHttp\Service;

use GuzzleHttp\Event\HasEmitterInterface;
use GuzzleHttp\ToArrayInterface;
use GuzzleHttp\Service\Description\OperationInterface;
use GuzzleHttp\Message\RequestInterface;

/**
 * A command object manages input and output of an operation using an
 * {@see OperationInterface} object.
 *
 * A command emits the following events:
 * - prepare: Emitted when the command is converting a command into a request
 * - process: Emitted when the command is processing a response
 * - error:   Emitted after an error occurs for a command.
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
     * Prepares the command for sending
     *
     * If the command has not been prepared, it is prepared when called.
     *
     * @return RequestInterface Returns the request that will be sent
     */
    public function prepare();
}
