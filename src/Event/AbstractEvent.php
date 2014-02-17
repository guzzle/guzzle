<?php

namespace GuzzleHttp\Event;

/**
 * Basic event class that can be extended.
 */
abstract class AbstractEvent implements EventInterface
{
    private $propagationStopped = false;

    public function isPropagationStopped()
    {
        return $this->propagationStopped;
    }

    public function stopPropagation()
    {
        $this->propagationStopped = true;
    }
}
