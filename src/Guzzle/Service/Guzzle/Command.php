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

    public function __construct(array $args, OperationInterface $operation)
    {
        $this->data = $args;
        $this->operation = $operation;
    }

    public function getOperation()
    {
        return $this->operation;
    }

    public function getRequest()
    {

    }

    public function processResponse(ResponseInterface $response)
    {

    }

    public function processError(RequestException $e)
    {

    }
}
