<?php

namespace Guzzle\Http\Event;

use Guzzle\Http\Exception\RequestException;
use Guzzle\Http\Message\ResponseInterface;

/**
 * Event object emitted after a request has been sent.
 *
 * You may change the result value associated with a request using the setResult() method of the event.
 */
class RequestAfterSendEvent extends AbstractRequestEvent
{
    /**
     * Intercept the request and associate aa response or exception
     *
     * @param ResponseInterface|RequestException $result Result to set for the request
     */
    public function intercept($result)
    {
        $this->transaction[$this->getRequest()] = $result;
        $this->stopPropagation();

        if ($result instanceof RequestException) {
            $this->emitError();
        }
    }

    /**
     * Get the response of the request
     *
     * @return ResponseInterface
     */
    public function getResponse()
    {
        return $this->transaction[$this->getRequest()];
    }
}
