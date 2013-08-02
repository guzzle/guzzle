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
        $this->transaction[$this['request']] = $result;
        $this->stopPropagation();

        if ($result instanceof RequestException) {
            // Emit the 'request.error' event for the request
            $this['request']->getEventDispatcher()->dispatch(
                'request.error',
                new RequestAfterSendEvent($this['request'], $this->transaction)
            );
        }
    }

    /**
     * Get the response of the request
     *
     * @return ResponseInterface
     */
    public function getResponse()
    {
        return $this->transaction[$this['request']];
    }
}
