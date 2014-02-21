<?php

namespace GuzzleHttp\Service\Guzzle;

use GuzzleHttp\HasDataTrait;
use GuzzleHttp\Event\HasEmitterTrait;
use GuzzleHttp\Service\CommandInterface;
use GuzzleHttp\Service\Description\OperationInterface;

class Command implements CommandInterface
{
    use HasDataTrait, HasEmitterTrait;
    private $operation;

    public function __construct(OperationInterface $operation, array $args)
    {
        $this->operation = $operation;
        $this->data = $args;
    }

    public function getOperation()
    {
        return $this->operation;
    }
}
