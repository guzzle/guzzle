<?php

namespace Guzzle\Service\Description;

/**
 * Build Guzzle commands based on an ApiCommand object
 */
class ApiCommandFactory implements CommandFactoryInterface
{
    /**
     * {@inheritdoc}
     */
    public function createCommand(ApiCommand $command, array $args)
    {
        $class = $command->getConcreteClass();

        return new $class($args, $command);
    }
}