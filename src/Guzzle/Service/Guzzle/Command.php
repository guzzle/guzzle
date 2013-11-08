<?php

namespace Guzzle\Service\Guzzle;

use Guzzle\Common\HasDataTrait;
use Guzzle\Http\Exception\RequestException;
use Guzzle\Http\Message\ResponseInterface;
use Guzzle\Service\CommandInterface;
use Guzzle\Service\OperationInterface;

class Command implements CommandInterface
{
    use HasDataTrait;

    protected $operation;
    protected $request;
    protected $serializer;

    public function __construct(array $args)
    {
        $this->data = $args;
        //$this->operation = $operation;
    }

    public function getOperation()
    {
        return $this->operation;
    }

    public function getRequest()
    {
        if (!isset($this['client'])) {
            throw new \RuntimeException('A client must be specified on the command');
        }

        if (!$this->request) {
            $this->request = $this['client']->createRequest('GET', 'https://raw.github.com/aws/aws-sdk-core-ruby/master/apis/CloudFront-2012-05-05.json');
        }

        return $this->request;
    }

    public function processResponse(ResponseInterface $response)
    {
        return $response->json();
    }

    public function processError(RequestException $e)
    {
        return $e->getResponse()->json();
    }
}
