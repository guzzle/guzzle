<?php

namespace Guzzle\Service;

use Guzzle\Http\ClientInterface;
use Guzzle\Http\Exception\RequestException;

class ServiceClient implements ServiceClientInterface
{
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

    public function getCommand($name, array $args = [])
    {
        if (!$this->description->hasOperation($name)) {
            throw new \InvalidArgumentException('No operation found matching ' . $name);
        }
    }

    public function execute(CommandInterface $command)
    {
        $request = $command->getRequest();

        try {
            $response = $this->client->send($request);
            return $command->processResponse($response);
        } catch (RequestException $e) {
            if (!$e->hasResponse()) {
                throw $e;
            }
            // $error = $command->processError($e);
            // throw new OperationErrorException($command, $error, $e);
        }
    }

    public function getDescription()
    {
        return $this->description;
    }
}
