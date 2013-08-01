<?php

namespace Guzzle\Http\Event;

use Guzzle\Common\Event;
use Guzzle\Http\Message\MessageFactoryInterface;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\ResponseInterface;

class AfterSendEvent extends Event
{
    public function __construct(RequestInterface $request, $result, MessageFactoryInterface $factory)
    {
        parent::__construct([
            'request'         => $request,
            'message_factory' => $factory,
            'result'          => $result
        ]);
    }

    public function getRequest()
    {
        return $this['request'];
    }

    public function getMessageFactory()
    {
        return $this['message_factory'];
    }

    public function setResult($result)
    {
        if (!($result instanceof ResponseInterface) && !($result instanceof \Exception)) {
            throw new \InvalidArgumentException('Result must be a ResponseInterface or Exception object');
        }

        $this['result'] = $result;
    }

    public function getResult()
    {
        return $this['result'];
    }

    public function hasResponse()
    {
        return $this['result'] instanceof ResponseInterface;
    }

    public function hasException()
    {
        return $this['result'] instanceof \Exception;
    }
}
