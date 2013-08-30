<?php

namespace Guzzle\Http\Adapter;

use Guzzle\Http\ClientInterface;
use Guzzle\Http\Message\MessageFactoryInterface;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\ResponseInterface;
use Guzzle\Stream\StreamInterface;

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
     * @param MessageFactoryInterface $messageFactory
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
     * @param string          $statusCode   HTTP response status code
     * @param string          $reasonPhrase Response reason phrase
     * @param array           $headers      Headers of the response
     * @param StreamInterface $body         Response body
     */
    public function setResponse($statusCode, $reasonPhrase, array $headers, StreamInterface $body)
    {
        $this->response = $this->messageFactory->createResponse($statusCode, $reasonPhrase, $headers, $body);
    }

    /**
     * @return ClientInterface
     */
    public function getClient()
    {
        return $this->client;
    }
}
