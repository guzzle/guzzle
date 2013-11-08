<?php

namespace Guzzle\Service;

use Guzzle\Common\HasDispatcherTrait;
use Guzzle\Http\ClientInterface;
use Guzzle\Http\Event\RequestErrorEvent;
use Guzzle\Http\Exception\RequestException;

/**
 * Default Guzzle service description based client
 */
class ServiceClient implements ServiceClientInterface
{
    use HasDispatcherTrait;

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
        $this->config = $config;
    }

    public function getHttpClient()
    {
        return $this->client;
    }

    public function getCommand($name, array $args = [])
    {
        if (!$this->description->hasOperation($name)) {
            throw new \InvalidArgumentException('No operation found matching ' . $name);
        }
    }

    public function execute(CommandInterface $command)
    {
        try {
            $response = $this->client->send($command->getRequest());
            return $command->processResponse($response);
        } catch (RequestException $e) {
            return $command->processError($e);
            // throw new OperationErrorException($command, $error, $e);
        }
    }

    public function getDescription()
    {
        return $this->description;
    }
}
