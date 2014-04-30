<?php

namespace GuzzleHttp\Adapter;

use GuzzleHttp\Event\RequestEvents;
use GuzzleHttp\Message\ResponseInterface;

/**
 * Adapter that can be used to associate mock responses with a transaction
 * while still emulating the event workflow of real adapters.
 */
class StackedMockAdapter implements AdapterInterface
{
    /** @var array of responses */
    private $responseStack;

    /**
     * @param array|ResponseInterface|callable $response Response / array
     *     of responses to serve or function to invoke that handles a transaction
     */
    public function __construct($response = null)
    {
        if ($response != null) {
            $this->setResponse($response);
        }
    }

    /**
     * Set the response that will be served by the adapter
     *
     * @param array|ResponseInterface|callable $response Response / array
     *     of responses to serve or function to invoke that handles a transaction
     */
    public function setResponse($response)
    {
        if (!is_array($response)) {
            $response = array($response);
        }
        $this->responseStack = $response;
    }

    /**
     * Append a response to the response stack
     * @param array|ResponseInterface|callable $response Response / array
     *     of responses to serve or function to invoke that handles a transaction
     */
    public function addResponse($response)
    {
        $this->responseStack[] = $response;
    }

    public function send(TransactionInterface $transaction)
    {
        RequestEvents::emitBefore($transaction);
        if (!$transaction->getResponse()) {

            // Read the request body if it is present
            if ($transaction->getRequest()->getBody()) {
                $transaction->getRequest()->getBody()->__toString();
            }

            //Fetch the first response in the stack and try to use it
            $fetchedResponse = array_shift($this->responseStack);

            if ($fetchedResponse === null) {
                throw new \RuntimeException(
                    'No mock responses left in stack for request ' . $transaction->getRequest()->getPath()
                );
            }

            $response = is_callable($fetchedResponse)
                ? call_user_func($fetchedResponse, $transaction)
                : $fetchedResponse;
            if (!$response instanceof ResponseInterface) {
                throw new \RuntimeException('Invalid mocked response');
            }

            $transaction->setResponse($response);
            RequestEvents::emitHeaders($transaction);
            RequestEvents::emitComplete($transaction);
        }

        return $transaction->getResponse();
    }
}
