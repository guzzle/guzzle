<?php

namespace Guzzle\Http\Event;

use Guzzle\Common\Event;
use Guzzle\Http\Adapter\Transaction;
use Guzzle\Http\ClientInterface;
use Guzzle\Http\Message\RequestInterface;

abstract class AbstractRequestEvent extends Event
{
    /** @var Transaction */
    protected $transaction;

    /**
     * @param RequestInterface        $request
     * @param Transaction             $transaction Transaction that contains the request
     */
    public function __construct(RequestInterface $request, Transaction $transaction)
    {
        $this->transaction = $transaction;
        parent::__construct(['request' => $request, 'client' => $transaction->getClient()]);
    }

    /**
     * Get the client associated with the event
     *
     * @return ClientInterface
     */
    public function getClient()
    {
        return $this->transaction->getClient();
    }

    /**
     * Get the request object
     *
     * @return RequestInterface
     */
    public function getRequest()
    {
        return $this['request'];
    }
}
