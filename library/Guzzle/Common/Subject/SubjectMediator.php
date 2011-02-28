<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Common\Subject;

/**
 * Subject mediator that connects {@see Subject}s and their {@see Observer}s for
 * loose coupling.
 *
 * @author Michael Dowling <michael@guzzle-project.org>
 */
class SubjectMediator
{
    /**
     * @var Subject Mediated {@see Subject} to connect with {@see Observer}s
     */
    protected $subject;

    /**
     * @var string The current state of the {@see Subject}
     */
    protected $state;

    /**
     * @var mixed Contextual information used with state change notifications
     */
    protected $stateContext;

    /**
     * @var array Array of {@see Observer} objects.
     */
    protected $observers = array();

    /**
     * Construct a new SubjectMediator
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
     * @param Observer $observer Object that observes the subject.
     *
     * @return Observer Returns the $observer that was attached.
     */
    public function attach(Observer $observer)
    {
        if (!$this->hasObserver($observer)) {
            $this->observers[] = $observer;
        }

        return $observer;
    }

    /**
     * Detach an observer.
     *
     * @param Observer $observer Observer to detach.
     *
     * @return Observer Returns the $observer that was detached.
     */
    public function detach(Observer $observer)
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
     * Set the state and stateContext of the subject and notify all observers
     * of the state change.
     *
     * @param string $state (optional) State of the object.  Leave unchanged to
     *      use the current state.
     * @param mixed $stateContext (optional) The context of the object's state
     * @param bool $unsetContext (optional) Set to TRUE to remove the
     *      stateContext of the subject after sending the notification.
     *
     * @return array Returns an array containing the response of each observer
     */
    public function notify($state = Subject::STATE_UNCHANGED,
        $stateContext = Subject::STATE_UNCHANGED, $unsetContext = false)
    {
        if ($state != Subject::STATE_UNCHANGED) {
            $this->state = (string) $state;
        }

        if ($stateContext != Subject::STATE_UNCHANGED) {
            $this->stateContext = $stateContext;
        }
        
        $responses = array();

        foreach ($this->observers as $observer) {
            if ($observer) {
                $result = $observer->update($this);
                if ($result) {
                    $responses[] = $result;
                }
            }
        }

        if ($unsetContext) {
            $this->stateContext = null;
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
    public function getAttached($byName = '')
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
     * Get contextual information about the state of the subject.
     *
     * @param mixed $default (optional) Pass a default value so that if the
     *      state context of the subject isn't set, the default value will be
     *      returned.
     *
     * @return mixed
     */
    public function getContext($default = null)
    {
        return (isset($this->stateContext)) ? $this->stateContext : $default;
    }

    /**
     * Get the state of the subject
     *
     * @return string|null
     */
    public function getState()
    {
        return $this->state;
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
     * @param string|Observer $observer Observer to check for.  Pass the
     *      name of an observer or a concrete {@see Observer}
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

    /**
     * Check to see if the Subject is an instance of a class or a specific class
     *
     * @param string|Object $check A concrete Subject or class name of a subject
     *
     * @return bool
     */
    public function is($check)
    {
        return (is_string($check))
            ? $this->subject instanceof $check
            : $this->subject === $check;
    }
}