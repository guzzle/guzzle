<?php
namespace GuzzleHttp\Event;

use GuzzleHttp\Message\ResponseInterface;

/**
 * Event object emitted after a request has been completed.
 *
 * You may change the Response associated with the request using the
 * intercept() method of the event.
 */
class CompleteEvent extends AbstractTransferEvent
{
    /**
     * Intercept the request and associate a response
     *
     * @param ResponseInterface $response Response to set
     */
    public function intercept(ResponseInterface $response)
    {
        $this->stopPropagation();
        $trans = $this->getTransaction();
        $trans->response = $response;
        $trans->exception = null;
    }

    /**
     * Get the response of the request
     *
     * @return ResponseInterface
     */
    public function getResponse()
    {
        return $this->getTransaction()->response;
    }
}
