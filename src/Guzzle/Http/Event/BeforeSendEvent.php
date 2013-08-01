<?php

namespace Guzzle\Http\Event;

use Guzzle\Common\Event;
use Guzzle\Http\Message\MessageFactoryInterface;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\ResponseInterface;

class BeforeSendEvent extends Event
{
    public function __construct(RequestInterface $request, MessageFactoryInterface $factory)
    {
        parent::__construct([
            'request'         => $request,
            'message_factory' => $factory
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

    public function getResponse()
    {
        return $this['response'];
    }

    public function setResponse(ResponseInterface $response)
    {
        $this['response'] = $response;
    }
}
