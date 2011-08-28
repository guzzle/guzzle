<?php

namespace Guzzle\Common\Event;

/**
 * Connects {@see Subject} objects and {@see Observer} objects by emitting
 * signals from the subject to the observer.  Contextual information can be
 * sent to observers to give more context on how to react to a signal.
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
     */
    public function __construct(Subject $subject)
    {
        $this->subject = $subject;
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
            $this->observers[$hash] = $observer;
            $this->priorities[$hash] = $priority;
            $priorities = $this->priorities;
            // Sort the events by priority
            uasort($this->observers, function($a, $b) use ($priorities) {
                $priority1 = $priorities[spl_object_hash($a)];
                $priority2 = $priorities[spl_object_hash($b)];
                if ($priority1 === $priority2) {
                    return 0;
                } else if ($priority1 > $priority2) {
                    return -1;
                } else {
                    return 1;
                }
            });
            // Notify the observer that it is being attached to the subject
            $this->notifyObserver($observer, 'event.attach');
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
        if (is_object($observer)) {
            $hash = spl_object_hash($observer);
            if (isset($this->observers[$hash])) {
                // Notify the observer that it is being detached
                $this->notifyObserver($observer, 'event.detach');
                unset($this->priorities[$hash]);
                unset($this->observers[$hash]);
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
        $detached = array_values($this->observers);
        foreach ($detached as $o) {
            $this->detach($o);
        }

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
            $result = $this->notifyObserver($observer, $event, $context);
            if ($result) {
                $responses[] = $result;
                if ($until == true) {
                    break;
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
            return array_values($this->observers);
        }

        return array_values(array_filter($this->observers, function($o) use ($byName) {
            return $o instanceof $byName;
        }));
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
        if (is_object($observer)) {
            return isset($this->observers[spl_object_hash($observer)]);
        } else if (is_string($observer)) {
            foreach ($this->observers as $item) {
                if ($item instanceOf $observer) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get the priority level that an observer was attached at
     *
     * @param object $observer Observer to get the priority level of
     *
     * @return int|null Returns the priortity level or NULl if not attached
     */
    public function getPriority($observer)
    {
        if (is_object($observer)) {
            $hash = spl_object_hash($observer);
            if (array_key_exists($hash, $this->priorities)) {
                return $this->priorities[$hash];
            }
        }

        return null;
    }

    /**
     * Notify a single observer of an event
     *
     * @param Closure|Observer $observer Observer to notify
     * @param string $event Event signal to send to the observer
     * @param mixed $context (optional) Context about the event
     *
     * @return mixed
     */
    protected function notifyObserver($observer, $event, $context = null)
    {
        if ($observer instanceof Observer) {
            return $observer->update($this->subject, $event, $context);
        } else {
            return $observer($this->subject, $event, $context);
        }
    }
}