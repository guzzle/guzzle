<?php

namespace GuzzleHttp\Event;

/**
 * SubscriberInterface provides an array of events to an
 * EventEmitterInterface when it is registered. The emitter then binds the
 * listeners specified by the EventSubscriber.
 *
 * This interface is based on the SubscriberInterface of the Symfony.
 * @link https://github.com/symfony/symfony/tree/master/src/Symfony/Component/EventDispatcher
 */
interface SubscriberInterface
{
    /**
     * Returns an array of event names this subscriber wants to listen to.
     *
     * The returned array keys MUST map to an event name. Each array value
     * MUST be an array in which the first element is the name of a function
     * on the EventSubscriber. The second element in the array is optional, and
     * if specified, designates the event priority.
     *
     * For example:
     *
     *  - ['eventName' => ['methodName']]
     *  - ['eventName' => ['methodName', $priority]]
     *
     * @return array
     */
    public function getEvents();
}
