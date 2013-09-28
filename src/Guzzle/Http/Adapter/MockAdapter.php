<?php

namespace Guzzle\Http\Adapter;

use Guzzle\Http\Event\RequestEvents;
use Guzzle\Http\Event\RequestAfterSendEvent;
use Guzzle\Http\Message\ResponseInterface;

/**
 * Adapter that can be used to associate mock responses with a transaction
 */
class MockAdapter implements AdapterInterface
{
    private $response;

    /**
     * Set the response that will be served by the adapter
     *
     * @param ResponseInterface|callable $response Response to serve or function to invoke that handles a transaction
     */
    public function setResponse($response)
    {
        $this->response = $response;
    }

    public function send(TransactionInterface $transaction)
    {
        if (is_callable($this->response)) {
            $transaction->setResponse($this->response($transaction));
        } else {
            $transaction->setResponse($this->response);
        }
        $transaction->getRequest()->getEventDispatcher()->dispatch(
            RequestEvents::AFTER_SEND,
            new RequestAfterSendEvent($transaction)
        );
    }
}
