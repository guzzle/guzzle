<?php

namespace GuzzleHttp\Service\Event;

/**
 * Event emitted when an error occurs while transferring a command.
 *
 * Event listeners can inject a result onto the event to intercept the
 * exception with a successful result.
 */
class ErrorEvent extends AbstractCommandEvent
{
    /**
     * Intercept the error and inject a result
     *
     * @param mixed $result Result to associate with the command
     */
    public function setResult($result)
    {
        $this->result = $result;
        $this->stopPropagation();
    }
}
