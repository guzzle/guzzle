<?php

namespace Guzzle\Http\Adapter;

use Guzzle\Http\Event\RequestEvents;
use Guzzle\Http\Message\ResponseInterface;

/**
 * Adapter that can be used to associate mock responses with a transaction
 * while still emulating the event workflow of real adapters.
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
        RequestEvents::emitBeforeSendEvent($transaction);
        if (!$transaction->getResponse()) {
            $transaction->setResponse(
                is_callable($this->response)
                    ? $this->response($transaction)
                    : $this->response
            );
            RequestEvents::emitAfterSend($transaction);
        }

        return $transaction->getResponse();
    }
}
