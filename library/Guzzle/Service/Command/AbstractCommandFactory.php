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
 * Build Guzzle commands based on a service document.
 *
 * This class handles building commands based on a service document, injecting
 * configuration data with values from the service doc, and validating that
 * the commands being built meet the criteria specified in the service doc.
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
abstract class AbstractCommandFactory implements CommandFactoryInterface
{
    /**
     * @var ServiceDescription Service description describing the service
     */
    protected $service;

    /**
     * Create a new service document command factory
     *
     * @param ServiceDescription $service Service description describing the service
     */
    public function __construct(ServiceDescription $service)
    {
        $this->service = $service;
    }

    /**
     * Build a webservice command based on the service document
     *
     * @param string $command Name of the command to retrieve
     * @param array $args (optional) Arguments to pass to the command
     *
     * @return ClosureCommand
     *
     * @throws InvalidArgumentException if the command was not found in the service doc
     */
    public function buildCommand($name, array $args = array())
    {
        $args = new Collection($args);

        // Make sure that this command exists in the service doc
        if (!$this->service->hasCommand($name)) {
            throw new \InvalidArgumentException('The supplied command ' . $name . ' was not found in the service document.');
        }

        $cmd = $this->service->getCommand($name);

        return $this->createCommand($cmd, $args);
    }

    /**
     * Method to implement in concrete command factories
     *
     * @param ApiCommand $command Command to create
     * @param Collection $args Prepared collection of arguments to set on the
     *      command
     *
     * @return CommandInterface
     */
    abstract protected function createCommand(ApiCommand $command, Collection $args);
}