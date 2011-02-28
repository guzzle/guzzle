<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Command;

use Guzzle\Common\Collection;
use Guzzle\Service\ApiCommand;
use Guzzle\Service\ServiceDescription;

/**
 * Build Guzzle commands based on a service document using concrete classes for
 * each command.
 * 
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class ConcreteCommandFactory extends AbstractCommandFactory
{
    /**
     * {@inheritdoc}
     */
    protected function createCommand(ApiCommand $command, Collection $args)
    {
        $class = $command->getConcreteClass();
        
        return new $class($args->getAll(), $command);
    }
}