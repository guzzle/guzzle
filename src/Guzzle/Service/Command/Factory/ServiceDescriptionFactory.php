<?php

namespace Guzzle\Service\Command\Factory;

use Guzzle\Service\Description\ServiceDescription;

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
     * @param ServiceDescription $description Service description
     */
    public function __construct(ServiceDescription $description)
    {
        $this->setServiceDescription($description);
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
        if ($this->description->hasCommand($name)) {
            $command = $this->description->getCommand($name);
            $class = $command->getConcreteClass();

            return new $class($args, $command);
        }
    }
}
