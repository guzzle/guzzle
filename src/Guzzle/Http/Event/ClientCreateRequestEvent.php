<?php

namespace Guzzle\Http\Event;

use Guzzle\Common\Event;
use Guzzle\Http\ClientInterface;
use Guzzle\Http\Message\RequestInterface;

/**
 * Event object emitted when a client creates a request.
 */
class ClientCreateRequestEvent extends Event
{
    private $client;
    private $request;

    /**
     * @param ClientInterface  $client Client that created the request
     * @param RequestInterface $request Request that was created
     */
    public function __construct(ClientInterface $client, RequestInterface $request)
    {
        $this->client = $client;
        $this->request = $request;
    }

    /**
     * Get the request that was created
     *
     * @return RequestInterface
     */
    public function getRequest()
    {
        return $this->request;
    }

    /**
     * Get the client that created the request
     *
     * @return ClientInterface
     */
    public function getClient()
    {
        return $this->client;
    }
}
