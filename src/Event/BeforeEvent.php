<?php

namespace GuzzleHttp\Event;

use GuzzleHttp\Message\ResponseInterface;

/**
 * Event object emitted before a request is sent.
 *
 * You may change the Response associated with the request using the
 * intercept() method of the event.
 */
class BeforeEvent extends AbstractRequestEvent
{
    /**
     * Intercept the request and associate a response
     *
     * @param ResponseInterface $response Response to set
     */
    public function intercept(ResponseInterface $response)
    {
        $this->getTransaction()->setResponse($response);
        $this->stopPropagation();
        RequestEvents::emitComplete($this->getTransaction());
    }
}
