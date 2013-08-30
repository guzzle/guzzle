<?php

namespace Guzzle\Http\Event;

use Guzzle\Http\Exception\RequestException;
use Guzzle\Http\Message\ResponseInterface;
use Guzzle\Http\Adapter\Transaction;

/**
 * Event object emitted after a request has been sent and an error was encountered
 *
 * You may intercept the exception and inject a response into the event to rescue the request.
 */
class RequestErrorEvent extends AbstractRequestEvent
{
    private $exception;

    /**
     * @param Transaction      $transaction Transaction that contains the request
     * @param RequestException $e           Exception encountered
     */
    public function __construct(Transaction $transaction, RequestException $e)
    {
        parent::__construct($transaction);
        $this->exception = $e;
    }

    /**
     * Intercept the exception and inject a response
     *
     * @param ResponseInterface $response Response to set
     */
    public function intercept(ResponseInterface $response)
    {
        $this->getTransaction()->setResponse($response);
        $this->stopPropagation();
        $this->emitAfterSend();
    }

    /**
     * Get the exception that was encountered
     *
     * @return RequestException
     */
    public function getException()
    {
        return $this->exception;
    }

    /**
     * Get the response the was received (if any)
     *
     * @return ResponseInterface|null
     */
    public function getResponse()
    {
        return $this->getException()->getResponse();
    }
}
