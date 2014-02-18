<?php

namespace GuzzleHttp\Service;

use GuzzleHttp\Collection;
use GuzzleHttp\Event\HasEmitterTrait;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Service\Description\DescriptionInterface;

/**
 * Default Guzzle service description based client.
 */
class ServiceClient implements ServiceClientInterface
{
    use HasEmitterTrait;

    private $client;
    private $description;
    private $config;
    private $commandFactory;

    public function __construct(
        ClientInterface $client,
        DescriptionInterface $description,
        array $config = []
    ) {
        $this->client = $client;
        $this->description = $description;
        $this->config = new Collection($config);
        $this->commandFactory = isset($config['command_factory'])
            ? $config['command_factory']
            : self::getCommandFactory($description);
    }

    public function getHttpClient()
    {
        return $this->client;
    }

    public function getCommand($name, array $args = [])
    {
        if (!$this->description->hasOperation($name)) {
            throw new \InvalidArgumentException("No operation found matching {$name}");
        }
    }

    public function execute(CommandInterface $command)
    {
        $this->getHttpClient()->send($command->prepare());

        return $command->getResult();
    }

    public function executeAll($commands, array $options = [])
    {

    }

    public function getDescription()
    {
        return $this->description;
    }

    public function getConfig($keyOrPath = null)
    {
        return $keyOrPath === null
            ? $this->config->toArray()
            : $this->config->getPath($keyOrPath);
    }

    public function setConfig($keyOrPath, $value)
    {
        $this->config->setPath($keyOrPath, $value);
    }

    public static function getCommandFactory(DescriptionInterface $description)
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
