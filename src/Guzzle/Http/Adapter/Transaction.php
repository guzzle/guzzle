<?php

namespace Guzzle\Http\Adapter;

use Guzzle\Http\ClientInterface;
use Guzzle\Http\Message\MessageFactoryInterface;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\ResponseInterface;

class Transaction
{
    /** @var ClientInterface */
    private $client;
    /** @var RequestInterface */
    private $request;
    /** @var ResponseInterface */
    private $response;
    /** @var MessageFactoryInterface */
    private $messageFactory;

    /**
     * @param ClientInterface         $client  Client that is used to send the requests
     * @param RequestInterface        $request
     * @param MessageFactoryInterface $messageFactory Message factory used with the Transaction
     */
    public function __construct(
        ClientInterface $client,
        RequestInterface $request,
        MessageFactoryInterface $messageFactory
    ) {
        $this->client = $client;
        $this->request = $request;
        $this->messageFactory = $messageFactory;
    }

    /**
     * @return RequestInterface
     */
    public function getRequest()
    {
        return $this->request;
    }

    /**
     * @return ResponseInterface|null
     */
    public function getResponse()
    {
        return $this->response;
    }

    /**
     * Set a response on the transaction
     *
     * @param ResponseInterface $response Response to set
     */
    public function setResponse(ResponseInterface $response)
    {
        $this->response = $response;
    }

    /**
     * @return ClientInterface
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * @return MessageFactoryInterface
     */
    public function getMessageFactory()
    {
        return $this->messageFactory;
    }
}
