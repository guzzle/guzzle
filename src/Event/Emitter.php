<?php
namespace GuzzleHttp\Event;

/**
 * Guzzle event emitter.
 *
 * Some of this class is based on the Symfony EventDispatcher component, which
 * ships with the following license:
 *
 *     This file is part of the Symfony package.
 *
 *     (c) Fabien Potencier <fabien@symfony.com>
 *
 *     For the full copyright and license information, please view the LICENSE
 *     file that was distributed with this source code.
 *
 * @link https://github.com/symfony/symfony/tree/master/src/Symfony/Component/EventDispatcher
 */
class Emitter implements EmitterInterface
{
    /** @var array */
    private $listeners = [];

    /** @var array */
    private $sorted = [];

    public function on($eventName, callable $listener, $priority = 0)
    {
        if ($priority === 'first') {
            $priority = isset($this->listeners[$eventName])
                ? max(array_keys($this->listeners[$eventName])) + 1
                : 1;
        } elseif ($priority === 'last') {
            $priority = isset($this->listeners[$eventName])
                ? min(array_keys($this->listeners[$eventName])) - 1
                : -1;
        }

        $this->listeners[$eventName][$priority][] = $listener;
        unset($this->sorted[$eventName]);
    }

    public function once($eventName, callable $listener, $priority = 0)
    {
        $onceListener = function (
            EventInterface $event,
            $eventName
        ) use (&$onceListener, $eventName, $listener, $priority) {
            $this->removeListener($eventName, $onceListener);
            $listener($event, $eventName, $this);
        };

        $this->on($eventName, $onceListener, $priority);
    }

    public function removeListener($eventName, callable $listener)
    {
        if (empty($this->listeners[$eventName])) {
            return;
        }

        foreach ($this->listeners[$eventName] as $priority => $listeners) {
            if (false !== ($key = array_search($listener, $listeners, true))) {
                unset(
                    $this->listeners[$eventName][$priority][$key],
                    $this->sorted[$eventName]
                );
            }
        }
    }

    public function listeners($eventName = null)
    {
        // Return all events in a sorted priority order
        if ($eventName === null) {
            foreach (array_keys($this->listeners) as $eventName) {
                if (empty($this->sorted[$eventName])) {
                    $this->listeners($eventName);
                }
            }
            return $this->sorted;
        }

        // Return the listeners for a specific event, sorted in priority order
        if (empty($this->sorted[$eventName])) {
            $this->sorted[$eventName] = [];
            if (isset($this->listeners[$eventName])) {
                krsort($this->listeners[$eventName], SORT_NUMERIC);
                foreach ($this->listeners[$eventName] as $listeners) {
                    foreach ($listeners as $listener) {
                        $this->sorted[$eventName][] = $listener;
                    }
                }
            }
        }

        return $this->sorted[$eventName];
    }

    public function hasListeners($eventName)
    {
        return !empty($this->listeners[$eventName]);
    }

    public function emit($eventName, EventInterface $event)
    {
        if (isset($this->listeners[$eventName])) {
            foreach ($this->listeners($eventName) as $listener) {
                $listener($event, $eventName);
                if ($event->isPropagationStopped()) {
                    break;
                }
            }
        }

        return $event;
    }

    public function attach(SubscriberInterface $subscriber)
    {
        foreach ($subscriber->getEvents() as $eventName => $listeners) {
            if (is_array($listeners[0])) {
                foreach ($listeners as $listener) {
                    $this->on(
                        $eventName,
                        [$subscriber, $listener[0]],
                        isset($listener[1]) ? $listener[1] : 0
                    );
                }
            } else {
                $this->on(
                    $eventName,
                    [$subscriber, $listeners[0]],
                    isset($listeners[1]) ? $listeners[1] : 0
                );
            }
        }
    }

    public function detach(SubscriberInterface $subscriber)
    {
        foreach ($subscriber->getEvents() as $eventName => $listener) {
            $this->removeListener($eventName, [$subscriber, $listener[0]]);
        }
    }
}
