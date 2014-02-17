<?php

namespace GuzzleHttp\Service\Guzzle;

use GuzzleHttp\HasDataTrait;
use GuzzleHttp\HasEmitterTrait;
use GuzzleHttp\Service\Command\CommandInterface;

class Command implements CommandInterface
{
    use HasDataTrait;
    use HasEmitterTrait;

    protected $operation;
    protected $serializer;
    protected $request;
    protected $response;
    protected $result;

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
        if (!$this->request) {
            if (!isset($this['client'])) {
                throw new \RuntimeException('A client must be specified on the command');
            }
            $this->request = $this['client']->createRequest('GET', 'https://raw.github.com/aws/aws-sdk-core-ruby/master/apis/CloudFront-2012-05-05.json');
        }

        return $this->request;
    }

    public function getResponse()
    {
        return $this->response;
    }

    public function prepare()
    {
        $this->request = $this->response = null;

        return $this->getRequest();
    }

    public function getResult()
    {
        return $this->result;
    }
}
