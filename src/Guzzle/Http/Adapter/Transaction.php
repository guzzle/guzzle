<?php

namespace Guzzle\Http\Adapter;

use Guzzle\Http\ClientInterface;
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

    /**
     * @param ClientInterface  $client  Client that is used to send the requests
     * @param RequestInterface $request
     */
    public function __construct(ClientInterface $client, RequestInterface $request)
    {
        $this->client = $client;
        $this->request = $request;
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
}
