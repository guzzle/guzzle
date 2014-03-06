<?php

namespace GuzzleHttp\Event;

use GuzzleHttp\Adapter\TransactionInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Message\RequestInterface;

abstract class AbstractRequestEvent extends AbstractEvent
{
    /** @var TransactionInterface */
    private $transaction;

    /**
     * @param TransactionInterface $transaction
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
