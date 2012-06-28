<?php

namespace Guzzle\Service\Command\Factory;

use Guzzle\Service\Description\ServiceDescription;
use Guzzle\Common\Inflection\InflectorInterface;

/**
 * Command factory used to create commands based on service descriptions
 */
class ServiceDescriptionFactory implements FactoryInterface
{
    /**
     * @var ServiceDescription
     */
    protected $description;

    /**
     * @var InflectorInterface
     */
    protected $inflector;

    /**
     * @param ServiceDescription $description Service description
     * @param InflectorInterface $inflector   Optional inflector to use if the command is not at first found
     */
    public function __construct(ServiceDescription $description, InflectorInterface $inflector = null)
    {
        $this->setServiceDescription($description);
        $this->inflector = $inflector;
    }

    /**
     * Change the service description used with the factory
     *
     * @param ServiceDescription $description Service description to use
     *
     * @return ServiceDescriptionFactory
     */
    public function setServiceDescription(ServiceDescription $description)
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Returns the service description
     *
     * @return ServiceDescription
     */
    public function getServiceDescription()
    {
        return $this->description;
    }

    /**
     * {@inheritdoc}
     */
    public function factory($name, array $args = array())
    {
        $command = $this->description->getCommand($name);

        // If an inflector was passed, then attempt to get the command using
        // snake_case inflection
        if (!$command && $this->inflector) {
            $command = $this->description->getCommand($this->inflector->snake($name));
        }

        if ($command) {
            $class = $command->getConcreteClass();
            return new $class($args, $command);
        }
    }
}
