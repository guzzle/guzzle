============
Event System
============

Guzzle uses an event emitter to allow you to easily extend the behavior of a
request, change the response associated with a request, and implement custom
error handling. All events in Guzzle are managed and emitted by an
**event emitter**.

Event Emitters
==============

Clients, requests, and any other class that implements the
``GuzzleHttp\Common\HasEmitterInterface`` interface have a
``GuzzleHttp\Common\EventEmitter`` object. You can add event *listeners* and
event *subscribers* to an event *emitter*.

emitter
    An object that implements ``GuzzleHttp\Common\EventEmitterInterface``. This
    object emits named events to event listeners. You may register event
    listeners on subscribers on an emitter.

event listeners
    Callable functions that are registered on an event emitter for specific
    events. Event listeners are registered on an emitter with a *priority*
    setting. If no priority is provided, ``0`` is used by default.

event subscribers
    Classes that tell an event emitter what methods to listen to and what
    functions on the class to invoke when the event is triggered. Event
    subscribers subscribe event listeners to an event emitter. They should be
    used when creating more complex event based logic in applications (i.e.,
    cookie handling is implemented using an event subscriber because it's
    easier to share a subscriber than an anonymous function and because
    handling cookies is a complex process).

priority
    Describes the order in which event listeners are invoked when an event is
    emitted. The higher a priority value, the earlier the event listener will
    be invoked (a higher priority means the listener is more important). If
    no priority is provided, the priority is assumed to be ``0``.

propagation
    Describes whether or not other event listeners are triggered. Event
    emitters will trigger every event listener registered to a specific event
    in priority order until all of the listeners have been triggered **or**
    until the propagation of an event is stopped.

Getting an EventEmitter
-----------------------

You can get the event emitter of ``GuzzleHttp\Common\HasEmitterInterface``
object using the the ``getEmitter()`` method. Here's an example of getting a
client object's event emitter.

.. code-block:: php

    $client = new GuzzleHttp\Client();
    $emitter = $client->getEmitter();

.. note::

    You'll notice that the event emitter used in Guzzle is very similar to the
    `Symfony2 EventDispatcher component <https://github.com/symfony/symfony/tree/master/src/Symfony/Component/EventDispatcher>`_.
    This is because the Guzzle event system is based on the Symfony2 event
    system with several changes. Guzzle uses its own event emitter to improve
    performance, isolate Guzzle from changes to the Symfony, and provide a few
    improvements that make it easier to use for an HTTP client (e.g., the
    addition of the ``once()`` method).

Adding Event Listeners
----------------------

After you have the emitter, you can register event listeners that listen to
specific events using the ``on()`` method. When registering an event listener,
you must tell the emitter what event to listen to (e.g., "before", "after",
"headers", "complete", "error", etc...), what callable to invoke when the
event is triggered, and optionally provide a priority.

.. code-block:: php

    use GuzzleHttp\Event\BeforeEvent;

    $emitter->on('before', function (BeforeEvent $event) {
        echo $event->getRequest();
    });

When a listener is triggered, it is passed an event that implements the
``GuzzleHttp\Common\EventInterface`` interface, the name of the event, and the
event emitter itself. The above example could more verbosely be written as
follows:

.. code-block:: php

    use GuzzleHttp\Event\BeforeEvent;

    $emitter->on('before', function (
        BeforeEvent $event,
        $name,
        EmitterInterface $emitter
    ) {
        echo $event->getRequest();
    });

You can add an event listener that automatically removes itself after it is
triggered using the ``once()`` method of an event emitter.

.. code-block:: php

    $client = new GuzzleHttp\Client();
    $client->getEmitter()->once('before', function () {
        echo 'This will only happen once... per request!';
    });

Event Propagation
-----------------

Event listeners can prevent other event listeners from being triggered by
stopping an event's propagation.

Stopping event propagation can be useful, for example, if an event listener has
changed the state of the subject to such an extent that allowing subsequent
event listeners to be triggered could place the subject in an inconsistent
state. This technique is used in Guzzle extensively when intercepting error
events with responses.

You can stop the propagation of an event using the ``stopPropagation()`` method
of a ``GuzzleHttp\Common\EventInterface`` object:

.. code-block:: php

    use GuzzleHttp\Event\ErrorEvent;

    $emitter->on('error', function (ErrorEvent $event) {
        $event->stopPropagation();
    });

After stopping the propagation of an event, any subsequent event listeners that
have not yet been trigger will not be triggered. You can check to see if the
propagation of an event was stopped using the ``isPropagationStopped()`` method
of the event.

.. code-block:: php

    $client = new GuzzleHttp\Client();
    $emitter = $client->getEmitter();
    // Note: assume that the $errorEvent was created
    if ($emitter->emit('error', $errorEvent)->isPropagationStopped()) {
        echo 'It was stopped!;
    }

.. hint::

    When emitting events, the event that was emitted is returned from the
    emitter. This allows you to easily chain calls as shown in the above
    example.

Event Subscribers
-----------------

Event subscribers are classes that implement the
``GuzzleHttp\Common\EventSubsriberInterface`` object. They are used to register
one or more event listeners to methods of the class. Event subscribers tell
event emitters exactly which events to listen to and what method to invoke on
the class when the event is triggered using the static method,
``getSubscribedEvents()``.

The following example registers event listeners to the ``before`` and
``complete`` event of a request. When the ``before`` event is emitted, the
``onBefore`` instance method of the subscriber is invoked. When the
``complete`` event is emitted, the ``onComplete`` event of the subscriber is
invoked. Each array value in the ``getSubscribedEvents()`` return value MUST
contain the name of the method to invoke and can optionally contain the
priority of the listener (as shown in the ``before`` listener in the example).

.. code-block:: php

    use Guzzle\Common\EventEmitterInterface;
    use Guzzle\Common\EventSubscriberInterface;
    use Guzzle\Http\Event\BeforeEvent;
    use Guzzle\Http\Event\CompleteEvent;

    class SimpleSubscriber implements EventSubscriberInterface
    {
        public static function getSubscribedEvents()
        {
            return [
                'before'   => ['onBefore', 100], // Provide name and optional priority
                'complete' => ['onComplete']
            ]
        }

        public function onBefore(BeforeEvent $event, $name, EmitterInterface $emitter)
        {
            echo 'Before!';
        }

        public function onComplete(CompleteEvent $event, $name, EmitterInterface $emitter)
        {
            echo 'Complete!';
        }
    }

Working With Request Events
===========================

Requests emit lifecycle events when they are transferred.

before
------

The ``before`` event is emitted before a request is sent. The event emitter is
a ``GuzzleHttp\Event\BeforeEvent``.

headers
-------

The ``headers`` event is emitted after the headers of a response have been
received before any of the response body has been downloaded. The event
emitted is a ``GuzzleHttp\Event\HeadersEvent``.

complete
--------

The ``complete`` event is emitted after a transaction completes and an entire
response has been received. The event is a ``GuzzleHttp\Event\CompleteEvent``.

error
-----

The ``error`` event is emitted when a request fails (whether it's from a
networking error or an HTTP protocol error). The event emitted is a
``GuzzleHttp\Event\ErrorEvent``.
