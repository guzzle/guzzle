<?php

namespace GuzzleHttp\Service\Guzzle;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Service\Description\DescriptionInterface;
use GuzzleHttp\Service\ServiceClient;

class Client extends ServiceClient
{
    private $commandFactory;

    public function __construct(
        ClientInterface $client,
        GuzzleDescription $description,
        array $config = []
    ) {
        parent::__construct($client, $description, $config);
        $this->commandFactory = isset($config['command_factory'])
            ? $config['command_factory']
            : self::getDefaultCommandFactory($description);
    }

    public function getCommand($name, array $args = [])
    {
        $description = $this->getDescription();
        if (!$description->hasOperation($name)) {
            throw new \InvalidArgumentException("No operation found matching {$name}");
        }

        return new Command($description->getOperation($name), $args);
    }

    public static function getDefaultCommandFactory(DescriptionInterface $description)
    {
        return function ($name, array $args = []) use ($description) {
            // If the command cannot be found, try again with a capital first
            // letter.
            if (!$description->hasOperation($name)) {
                $name = ucfirst($name);
            }

            if (!($operation = $description->getOperation($name))) {
                return null;
            }

            $class = $operation->getMetadata('class') ?: 'GuzzleHttp\Service\GuzzleHttp\Command';

            return new $class($args, $operation);
        };
    }
}
