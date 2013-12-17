<?php

namespace Guzzle\Http\Event;

use Guzzle\Common\Event;
use Guzzle\Http\Adapter\TransactionInterface;
use Guzzle\Http\ClientInterface;
use Guzzle\Http\Message\RequestInterface;

abstract class AbstractRequestEvent extends Event
{
    /** @var TransactionInterface */
    private $transaction;

    /**
     * @param TransactionInterface $transaction Transaction that contains the request
     */
    public function __construct(TransactionInterface $transaction)
    {
        $this->transaction = $transaction;
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
        return $this->transaction->getRequest();
    }

    /**
     * @return TransactionInterface
     */
    protected function getTransaction()
    {
        return $this->transaction;
    }
}
