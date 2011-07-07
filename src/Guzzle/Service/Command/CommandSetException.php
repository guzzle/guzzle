<?php

namespace Guzzle\Service\Command;

/**
 * Command Set exception
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class CommandSetException extends \RuntimeException
{
    /**
     * @var array Commands with an invalid client
     */
    private $invalidCommands = array();

    /**
     * Get the invalid commands in the CommandSet
     *
     * @return array
     */
    public function getCommands()
    {
        return $this->invalidCommands;
    }

    /**
     * Set the invalid commands in the CommandSet
     *
     * @param array $commands Array of Command objects
     */
    public function setCommands(array $commands)
    {
        $this->invalidCommands = $commands;
    }
}