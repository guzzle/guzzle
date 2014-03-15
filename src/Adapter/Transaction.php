<?php

namespace GuzzleHttp\Adapter;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Message\ResponseInterface;

class Transaction implements TransactionInterface
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
    public function __construct(
        ClientInterface $client,
        RequestInterface $request
    ) {
        $this->client = $client;
        $this->request = $request;
    }

    public function getRequest()
    {
        return $this->request;
    }

    public function getResponse()
    {
        return $this->response;
    }

    public function setResponse(ResponseInterface $response)
    {
        $this->response = $response;
    }

    public function getClient()
    {
        return $this->client;
    }
}
