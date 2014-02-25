<?php

namespace GuzzleHttp\Service;

use GuzzleHttp\Event\HasEmitterInterface;
use GuzzleHttp\ToArrayInterface;

/**
 * A command object encapsulates the input parameters used to control the
 * creation of a HTTP request and processing of a HTTP response.
 *
 * Using the toArray() method will return the input parameters of the command
 * as an associative array.
 *
 * A command emits the following events:
 * - prepare: Emitted when the command is converting a command into a request
 * - process: Emitted when the command is processing a response
 * - error:   Emitted after an error occurs for a command.
 */
interface CommandInterface extends
    \ArrayAccess,
    ToArrayInterface,
    HasEmitterInterface
{
    /**
     * Get the name of the command
     *
     * @return string
     */
    public function getName();

    /**
     * Get transport and plugin specific configuration settings of the command.
     *
     * @returns \GuzzleHttp\Collection
     */
    public function getConfig();

    /**
     * Check if the command has a parameter by name.
     *
     * @param string $name Name of the parameter to check
     *
     * @return bool
     */
    public function hasParam($name);
}
