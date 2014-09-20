<?php
namespace GuzzleHttp\Event;

use GuzzleHttp\Exception\RequestException;

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
     * Get the exception that was encountered
     *
     * @return RequestException
     */
    public function getException()
    {
        return $this->transaction->exception;
    }
}
