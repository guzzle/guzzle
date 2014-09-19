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
    /** @var ClientInterface Client used to transfer the request. */
    public $client;

    /** @var RequestInterface The request that is being sent. */
    public $request;

    /**
     * The response associated with the transaction. A response will not be
     * present when a networking error occurs or an error occurs before sending
     * the request.
     *
     * @var ResponseInterface|null
     */
    public $response;

    /**
     * Exception associated with the transaction. If this exception is present
     * when processing synchronous or future commands, then it is thrown. When
     * intercepting a failed transaction, you MUST set this value to null in
     * order to prevent the exception from being thrown.
     *
     * @var \Exception
     */
    public $exception;

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
