<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Common\Event;

/**
 * Subject mediator event manager that connects {@see Subject}s and their
 * {@see Observer}s for loose coupling.
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class EventManager
{
    /**
     * @var Subject Mediated {@see Subject} to connect with {@see Observer}s
     */
    protected $subject;

    /**
     * @var array Array of {@see Observer} objects.
     */
    protected $observers = array();

    /**
     * @var array Array of observer priorities
     */
    protected $priorities = array();

    /**
     * Construct a new EventManager
     *
     * @param Subject Subject colleague object
     * @param array $observers (optional) Array of {@see Observer} objects
     */
    public function __construct(Subject $subject, array $observers = null)
    {
        $this->subject = $subject;
        if ($observers) {
            foreach ($observers as $observer) {
                $this->attach($observer);
            }
        }
    }

    /**
     * Attach a new observer.
     *
     * @param Observer|Closure $observer Object that observes the subject.
     * @param int $priority (optional) Priority to attach to the subject.  The
     *      higher the priority, the sooner it will be notified
     *
     * @return Observer|Closure Returns the $observer that was attached
     * @throws InvalidArgumentException if the observer is not a Closure or Observer
     */
    public function attach($observer, $priority = 0)
    {
        if (!($observer instanceof \Closure) && !($observer instanceof Observer)) {
            throw new \InvalidArgumentException(
                'Observer must be a Closure or Observer object'
            );
        }

        if (!$this->hasObserver($observer)) {

            $hash = spl_object_hash($observer);
            $this->observers[] = $observer;

            if ($priority) {
                $this->priorities[$hash] = $priority;
            }
            $priorities = $this->priorities;

            // Sort the events by priority
            usort($this->observers, function($a, $b) use ($priorities) {
                $priority1 = $priority2 = 0;
                $ah = spl_object_hash($a);
                $bh = spl_object_hash($b);
                if (isset($priorities[$ah])) {
                    $priority1 = $priorities[$ah];
                }
                if (isset($priorities[$bh])) {
                    $priority2 = $priorities[$bh];
                }

                if ($priority1 === $priority2) {
                    return 0;
                }

                return $priority1 > $priority2 ? -1 : 1;
            });
        }

        return $observer;
    }

    /**
     * Detach an observer.
     *
     * @param Observer|Closure $observer Observer to detach.
     *
     * @return Observer Returns the $observer that was detached.
     */
    public function detach($observer)
    {
        if ($this->observers === array($observer)) {
            $this->observers = array();
        } else {
            if (count($this->observers)) {
                $this->observers = array_values(
                    array_filter($this->observers, function($value) use ($observer) {
                        return ($observer !== $value);
                    })
                );
            }
        }

        return $observer;
    }

    /**
     * Detach all observers.
     *
     * @return array Returns an array of the detached observers
     */
    public function detachAll()
    {
        $detached = $this->observers;
        $this->observers = array();

        return $detached;
    }

    /**
     * Notify all observers of an event
     *
     * @param string $event (optional) Event signal to emit
     * @param mixed $context (optional) Context of the event
     * @param bool $until (optional) Set to TRUE to stop event propagation when
     *      one of the observers returns TRUE
     *
     * @return array Returns an array containing the response of each observer
     */
    public function notify($event, $context = null, $until = false)
    {
        $responses = array();

        foreach ($this->observers as $observer) {
            if ($observer) {
                if ($observer instanceof Observer) {
                    $result = $observer->update($this->subject, $event, $context);
                } else {
                    $result = $observer($this->subject, $event, $context);
                }
                if ($result) {
                    $responses[] = $result;
                    if ($until == true) {
                        break;
                    }
                }
            }
        }
        
        return $responses;
    }

    /**
     * Get all attached observers.
     *
     * @param string $byName (optional) Pass the name of a class to retrieve
     *      only observers that are an instance of a particular class.
     *
     * @return array Returns an array containing the matching observers.  The
     *      returned array may or may not be empty.
     */
    public function getAttached($byName = null)
    {
        if (!$byName) {
            return $this->observers;
        } else {
            $results = array();
            foreach ($this->observers as $observer) {
                if ($observer instanceof $byName) {
                    $results[] = $observer;
                }
            }
            return $results;
        }
    }

    /**
     * Get the mediated {@see Subject} or NULL if no Subject has been associated
     *
     * @return Subject|null
     */
    public function getSubject()
    {
        return $this->subject;
    }

    /**
     * Check if a certain observer or type of observer is attached
     *
     * @param string|Observer|Closure $observer Observer to check for.  Pass the
     *      name of an observer, a concrete {@see Observer}, or Closure
     *
     * @return bool
     */
    public function hasObserver($observer)
    {
        foreach ($this->observers as $index => $item) {
            if ((is_string($observer)  && $item instanceOf $observer)
                || $observer === $item) {
                return true;
            }
        }

        return false;
    }
}