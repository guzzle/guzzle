<?php

namespace GuzzleHttp\Service;

use GuzzleHttp\Collection;
use GuzzleHttp\Event\ErrorEvent;
use GuzzleHttp\Event\HasEmitterTrait;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Service\Description\DescriptionInterface;
use GuzzleHttp\Service\Event\CommandErrorEvent;
use GuzzleHttp\Service\Event\PrepareEvent;
use GuzzleHttp\Service\Event\ProcessEvent;

/**
 * Default Guzzle service description based client.
 */
abstract class ServiceClient implements ServiceClientInterface
{
    use HasEmitterTrait;

    private $client;
    private $description;
    private $config;

    public function __construct(
        ClientInterface $client,
        DescriptionInterface $description,
        array $config = []
    ) {
        $this->client = $client;
        $this->description = $description;
        $this->config = new Collection($config);
    }

    public function getHttpClient()
    {
        return $this->client;
    }

    public function execute(CommandInterface $command)
    {
        $event = new PrepareEvent($command);
        $command->getEmitter()->emit('prepare', $event);
        if (!($request = $event->getRequest())) {
            throw new \RuntimeException('No request was prepared for the '
                . 'command. One of the event listeners must set a request on '
                . 'the prepare event.');
        }

        // Handle request errors with the command
        $request->getEmitter()->on(
            'error',
            function (ErrorEvent $e) use ($command) {
                $event = new CommandErrorEvent($command, $e);
                $command->getEmitter()->emit('error', $event);
                if ($event->getResult()) {
                    $e->stopPropagation();
                }
            }
        );

        $response = $this->client->send($request);
        $event = new ProcessEvent($command, $request, $response);
        $command->getEmitter()->emit('process', $event);

        return $event->getResult();
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
}
