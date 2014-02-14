<?php

namespace Guzzle\Http\Event;

use Guzzle\Http\Message\ResponseInterface;

/**
 * Event object emitted after a request has been completed.
 *
 * You may change the Response associated with the request using the
 * intercept() method of the event.
 */
class CompleteEvent extends AbstractTransferStatsEvent
{
    /**
     * Intercept the request and associate a response
     *
     * @param ResponseInterface $response Response to set
     */
    public function intercept(ResponseInterface $response)
    {
        $this->stopPropagation();
        $this->getTransaction()->setResponse($response);
    }

    /**
     * Get the response of the request
     *
     * @return ResponseInterface
     */
    public function getResponse()
    {
        return $this->getTransaction()->getResponse();
    }
}
