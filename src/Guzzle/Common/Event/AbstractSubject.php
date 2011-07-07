<?php

namespace Guzzle\Common\Event;

/**
 * Abstract subject class
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
abstract class AbstractSubject implements Subject
{
    /**
     * @var EventManager
     */
    protected $eventManager;

    /**
     * Get the subject mediator associated with the subject
     *
     * @return EventManager
     */
    public function getEventManager()
    {
        if (!$this->eventManager) {
            $this->eventManager = new EventManager($this);
        }

        return $this->eventManager;
    }
}