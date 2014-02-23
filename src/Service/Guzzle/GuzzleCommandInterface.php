<?php

namespace GuzzleHttp\Service\Guzzle;

use GuzzleHttp\Service\CommandInterface;
use GuzzleHttp\Service\Guzzle\Description\Operation;

/**
 * Represents a command that is sent using a Guzzle service description.
 */
interface GuzzleCommandInterface extends CommandInterface
{
    /**
     * Returns the API description operation associated with the command
     *
     * @return Operation
     */
    public function getOperation();
}
