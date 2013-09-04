<?php

namespace Guzzle\Http\Event;

use Guzzle\Http\Message\ResponseInterface;
use Guzzle\Http\Adapter\Transaction;
use Guzzle\Http\Header\HeaderCollection;

/**
 * Event object emitted after the response headers of a request have been received
 *
 * You may intercept the exception and inject a response into the event to rescue the request.
 */
class GotResponseHeadersEvent extends AbstractRequestEvent
{
    /**
     * @param Transaction $transaction Transaction that contains the request and response
     */
    public function __construct(Transaction $transaction)
    {
        parent::__construct($transaction);
    }

    /**
     * Get the response the was received
     *
     * @return ResponseInterface
     */
    public function getResponse()
    {
        return $this->getTransaction()->getResponse();
    }

    /**
     * Get the received headers
     *
     * @return HeaderCollection
     */
    public function getHeaders()
    {
        return $this->getResponse()->getHeaders();
    }
}
