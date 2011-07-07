<?php

namespace Guzzle\Common\Event;

/**
 * Guzzle subject interface
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
interface Subject
{
    /**
     * Get the subject mediator associated with the subject
     *
     * @return EventManager
     */
    function getEventManager();
}