<?php
namespace GuzzleHttp\Event;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Message\ResponseInterface;

/**
 * Event object emitted after a request has been sent and an error was
 * encountered.
 *
 * You may intercept the exception and inject a response into the event to
 * rescue the request.
 */
class ErrorEvent extends AbstractTransferEvent
{
    /**
     * Intercept the exception and inject a response
     *
     * @param ResponseInterface $response Response to set
     */
    public function intercept(ResponseInterface $response)
    {
        $this->transaction->response = $response;
        $this->transaction->exception = null;
        $this->stopPropagation();
    }

    /**
     * Get the exception that was encountered
     *
     * @return RequestException
     */
    public function getException()
    {
        return $this->transaction->exception;
    }
}
