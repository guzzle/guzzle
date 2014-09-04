<?php
namespace GuzzleHttp;

use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Message\ResponseInterface;

/**
 * Represents the relationship between a client, request, and response.
 *
 * You can access the request, response, and client using their corresponding
 * public properties.
 */
class Transaction
{
    /** @var ClientInterface */
    public $client;

    /** @var RequestInterface */
    public $request;

    /** @var ResponseInterface */
    public $response;

    /**
     * @param ClientInterface  $client  Client that is used to send the requests
     * @param RequestInterface $request Request to send
     */
    public function __construct(
        ClientInterface $client,
        RequestInterface $request
    ) {
        $this->client = $client;
        $this->request = $request;
    }
}
