<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Command;

use Guzzle\Service\ServiceDescription;

/**
 * Interface for building Guzzle commands based on a service document.
 *
 * This class handles building commands based on a service document, injecting
 * configuration data with values from the service doc, and validating that
 * the commands being built meet the criteria specified in the service doc.
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
interface CommandFactoryInterface
{
    /**
     * Create a new command factory
     *
     * @param ServiceDescription $service Service description describing the service
     */
    public function __construct(ServiceDescription $service);

    /**
     * Build a webservice command based on the service document
     *
     * @param string $command Name of the command to retrieve
     * @param array $args (optional) Arguments to pass to the command
     *
     * @return CommandInterface
     *
     * @throws InvalidArgumentException if the command was not found for the service
     */
    public function buildCommand($name, array $args = array());
}