<?php

namespace Guzzle\Service\Resource;

use Guzzle\Common\Exception\InvalidArgumentException;
use Guzzle\Service\Command\CommandInterface;

/**
 * Abstract resource iterator factory implementation
 */
abstract class AbstractResourceIteratorFactory implements ResourceIteratorFactoryInterface
{
    /**
     * {@inheritdoc}
     */
    public function build($data, array $options = array())
    {
        if (!($data instanceof CommandInterface)) {
            throw new InvalidArgumentException('The first argument must be an instance of CommandInterface');
        }

        $className = $this->getClassName($data);

        if (!$className) {
            throw new InvalidArgumentException('Iterator was not found for ' . $data->getName());
        }

        return new $className($data, $options);
    }

    /**
     * Get the name of the class to instantiate for the command
     *
     * @param CommandInterface $command Command that is associated with the iterator
     *
     * @return string
     */
    abstract protected function getClassName(CommandInterface $command);
}
