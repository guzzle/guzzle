<?php

namespace Guzzle\Common;

/**
 * Basic event class that can be extended.
 */
class Event implements EventInterface
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
