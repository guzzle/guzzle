<?php

namespace GuzzleHttp\Service\Event;

use GuzzleHttp\Message\RequestInterface;

/**
 * Event emitted when a command is being prepared.
 *
 * Event listeners can inject a {@see GuzzleHttp\Message\RequestInterface}
 * object onto the event to be used as the request sent over the wire.
 */
class PrepareEvent extends AbstractCommandEvent
{
    /**
     * Set the HTTP request that will be sent for the command.
     *
     * @param RequestInterface $request Request to send for the command
     */
    public function setRequest(RequestInterface $request)
    {
        $this->request = $request;
    }

    /**
     * Intercept the prepare event and inject a response.
     *
     * @param mixed $result Result to associate with the command
     */
    public function setResult($result)
    {
        $this->result = $result;
        $this->stopPropagation();
    }
}
