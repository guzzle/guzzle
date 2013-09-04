<?php

namespace Guzzle\Http\Adapter;

use Guzzle\Http\ClientInterface;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\ResponseInterface;

interface TransactionInterface
{
    /**
     * @return RequestInterface
     */
    public function getRequest();

    /**
     * @return ResponseInterface|null
     */
    public function getResponse();

    /**
     * Set a response on the transaction
     *
     * @param ResponseInterface $response Response to set
     */
    public function setResponse(ResponseInterface $response);

    /**
     * @return ClientInterface
     */
    public function getClient();
}
