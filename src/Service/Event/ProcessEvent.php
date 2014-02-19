<?php

namespace GuzzleHttp\Service\Event;

/**
 * Event emitted when the HTTP response of a command is being processed.
 *
 * Event listeners can inject a result onto the event to change the result of
 * the command.
 */
class ProcessEvent extends AbstractCommandEvent
{
    /**
     * Set the processed result on the event.
     *
     * @param mixed $result Result to associate with the command
     */
    public function setResult($result)
    {
        $this->result = $result;
    }
}
