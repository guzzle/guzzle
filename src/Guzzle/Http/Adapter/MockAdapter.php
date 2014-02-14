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
     * @param ResponseInterface|callable $response Response to serve or function to invoke that handles a transaction
     */
    public function __construct($response = null)
    {
        $this->setResponse($response);
    }

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
        RequestEvents::emitBefore($transaction);
        if (!$transaction->getResponse()) {
            $response = is_callable($this->response)
                ? call_user_func($this->response, $transaction)
                : $this->response;
            if (!$response instanceof ResponseInterface) {
                throw new \RuntimeException('Invalid mocked response');
            }
            $transaction->setResponse($response);
            RequestEvents::emitComplete($transaction);
        }

        return $transaction->getResponse();
    }
}
