<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Command;

use Guzzle\Service\ServiceException;

/**
 * Command Set exception
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class CommandSetException extends ServiceException
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