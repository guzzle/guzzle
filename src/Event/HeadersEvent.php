<?php

namespace GuzzleHttp\Event;

use GuzzleHttp\Adapter\TransactionInterface;
use GuzzleHttp\Message\ResponseInterface;

/**
 * Event object emitted after the response headers of a request have been
 * received.
 *
 * You may intercept the exception and inject a response into the event to
 * rescue the request.
 */
class HeadersEvent extends AbstractRequestEvent
{
    /**
     * @param TransactionInterface $transaction Transaction that contains the
     *     request and response.
     * @throws \RuntimeException
     */
    public function __construct(TransactionInterface $transaction)
    {
        parent::__construct($transaction);
        if (!$transaction->getResponse()) {
            throw new \RuntimeException('A response must be present');
        }
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
}
