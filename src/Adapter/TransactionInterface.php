<?php

namespace GuzzleHttp\Adapter;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Message\ResponseInterface;

/**
 * Represents a transactions that consists of a request, response, and client
 */
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
