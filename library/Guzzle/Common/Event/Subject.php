<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Common\Event;

/**
 * Guzzle subject interface
 *
 * @author Michael Dowling <michael@guzzle-project.org>
 */
interface Subject
{
    /**
     * STATE_UNCHANGED is used when dispatching events so that the state
     * will remain unchanged from the previous state.
     *
     * @var string
     */
    const STATE_UNCHANGED = 'unchaged';

    /**
     * Get the subject mediator associated with the subject
     *
     * @return EventManager
     */
    public function getEventManager();
}