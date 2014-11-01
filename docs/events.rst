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

    When specifying an event priority, you can pass ``"first"`` or ``"last"`` to
    dynamically specify the priority based on the current event priorities
    associated with the given event name in the emitter. Use ``"first"`` to set
    the priority to the current highest priority plus one. Use ``"last"`` to
    set the priority to the current lowest event priority minus one. It is
    important to remember that these dynamic priorities are calculated only at
    the point of insertion into the emitter and they are not rearranged after
    subsequent listeners are added to an emitter.

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
"progress", "complete", "error", etc.), what callable to invoke when the
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
have not yet been triggered will not be triggered. You can check to see if the
propagation of an event was stopped using the ``isPropagationStopped()`` method
of the event.

.. code-block:: php

    $client = new GuzzleHttp\Client();
    $emitter = $client->getEmitter();
    // Note: assume that the $errorEvent was created
    if ($emitter->emit('error', $errorEvent)->isPropagationStopped()) {
        echo 'It was stopped!';
    }

.. hint::

    When emitting events, the event that was emitted is returned from the
    emitter. This allows you to easily chain calls as shown in the above
    example.

Event Subscribers
-----------------

Event subscribers are classes that implement the
``GuzzleHttp\Common\EventSubscriberInterface`` object. They are used to register
one or more event listeners to methods of the class. Event subscribers tell
event emitters exactly which events to listen to and what method to invoke on
the class when the event is triggered by called the ``getEvents()`` method of
a subscriber.

The following example registers event listeners to the ``before`` and
``complete`` event of a request. When the ``before`` event is emitted, the
``onBefore`` instance method of the subscriber is invoked. When the
``complete`` event is emitted, the ``onComplete`` event of the subscriber is
invoked. Each array value in the ``getEvents()`` return value MUST
contain the name of the method to invoke and can optionally contain the
priority of the listener (as shown in the ``before`` listener in the example).

.. code-block:: php

    use GuzzleHttp\Event\EmitterInterface;
    use GuzzleHttp\Event\SubscriberInterface;
    use GuzzleHttp\Event\BeforeEvent;
    use GuzzleHttp\Event\CompleteEvent;

    class SimpleSubscriber implements SubscriberInterface
    {
        public function getEvents()
        {
            return [
                // Provide name and optional priority
                'before'   => ['onBefore', 100],
                'complete' => ['onComplete'],
                // You can pass a list of listeners with different priorities
                'error'    => [['beforeError', 'first'], ['afterError', 'last']]
            ];
        }

        public function onBefore(BeforeEvent $event, $name)
        {
            echo 'Before!';
        }

        public function onComplete(CompleteEvent $event, $name)
        {
            echo 'Complete!';
        }
    }

.. note::

    You can specify event priorities using integers or ``"first"`` and
    ``"last"`` to dynamically determine the priority.

Event Priorities
================

When adding event listeners or subscribers, you can provide an optional event
priority. This priority is used to determine how early or late a listener is
triggered. Specifying the correct priority is an important aspect of ensuring
a listener behaves as expected. For example, if you wanted to ensure that
cookies associated with a redirect were added to a cookie jar, you'd need to
make sure that the listener that collects the cookies is triggered before the
listener that performs the redirect.

In order to help make the process of determining the correct event priority of
a listener easier, Guzzle provides several pre-determined named event
priorities. These priorities are exposed as constants on the
``GuzzleHttp\Event\RequestEvents`` object.

last
    Use ``"last"`` as an event priority to set the priority to the current
    lowest event priority minus one.

first
    Use ``"first"`` as an event priority to set the priority to the current
    highest priority plus one.

``GuzzleHttp\Event\RequestEvents::EARLY``
    Used when you want a listener to be triggered as early as possible in the
    event chain.

``GuzzleHttp\Event\RequestEvents::LATE``
    Used when you want a listener to be to be triggered as late as possible in
    the event chain.

``GuzzleHttp\Event\RequestEvents::PREPARE_REQUEST``
    Used when you want a listener to be trigger while a request is being
    prepared during the ``before`` event. This event priority is used by the
    ``GuzzleHttp\Subscriber\Prepare`` event subscriber which is responsible for
    guessing a Content-Type, Content-Length, and Expect header of a request.
    You should subscribe after this event is triggered if you want to ensure
    that this subscriber has already been triggered.

``GuzzleHttp\Event\RequestEvents::SIGN_REQUEST``
    Used when you want a listener to be triggered when a request is about to be
    signed. Any listener triggered at this point should expect that the request
    object will no longer be mutated. If you are implementing a custom
    signature subscriber, then you should use this event priority to sign
    requests.

``GuzzleHttp\Event\RequestEvents::VERIFY_RESPONSE``
    Used when you want a listener to be triggered when a response is being
    validated during the ``complete`` event. The
    ``GuzzleHttp\Subscriber\HttpError`` event subscriber uses this event
    priority to check if an exception should be thrown due to a 4xx or 5xx
    level response status code. If you are doing any kind of verification of a
    response during the complete event, it should happen at this priority.

``GuzzleHttp\Event\RequestEvents::REDIRECT_RESPONSE``
    Used when you want a listener to be triggered when a response is being
    redirected during the ``complete`` event. The
    ``GuzzleHttp\Subscriber\Redirect`` event subscriber uses this event
    priority when performing redirects.

You can use the above event priorities as a guideline for determining the
priority of you event listeners. You can use these constants and add to or
subtract from them to ensure that a listener happens before or after the named
priority.

.. note::

    "first" and "last" priorities are not adjusted after they added to an
    emitter. For example, if you add a listener with a priority of "first",
    you can still add subsequent listeners with a higher priority which would
    be triggered before the listener added with a priority of "first".

Working With Request Events
===========================

Requests emit lifecycle events when they are transferred.

.. important::

    Excluding the ``end`` event, request lifecycle events may be triggered
    multiple times due to redirects, retries, or reusing a request multiple
    times. Use the ``once()`` method want the event to be triggered once. You
    can also remove an event listener from an emitter by using the emitter which
    is provided to the listener.

.. _before_event:

before
------

The ``before`` event is emitted before a request is sent. The event emitted is
a ``GuzzleHttp\Event\BeforeEvent``.

.. code-block:: php

    use GuzzleHttp\Client;
    use GuzzleHttp\Common\EmitterInterface;
    use GuzzleHttp\Event\BeforeEvent;

    $client = new Client(['base_url' => 'http://httpbin.org']);
    $request = $client->createRequest('GET', '/');
    $request->getEmitter()->on(
        'before',
        function (BeforeEvent $e, $name, EmitterInterface $emitter) {
            echo $name . "\n";
            // "before"
            echo $e->getRequest()->getMethod() . "\n";
            // "GET" / "POST" / "PUT" / etc.
            echo get_class($e->getClient());
            // "GuzzleHttp\Client"
        }
    );

You can intercept a request with a response before the request is sent over the
wire. The ``intercept()`` method of the ``BeforeEvent`` accepts a
``GuzzleHttp\Message\ResponseInterface``. Intercepting the event will prevent
the request from being sent over the wire and stops the propagation of the
``before`` event, preventing subsequent event listeners from being invoked.

.. code-block:: php

    use GuzzleHttp\Client;
    use GuzzleHttp\Event\BeforeEvent;
    use GuzzleHttp\Message\Response;

    $client = new Client(['base_url' => 'http://httpbin.org']);
    $request = $client->createRequest('GET', '/status/500');
    $request->getEmitter()->on('before', function (BeforeEvent $e) {
        $response = new Response(200);
        $e->intercept($response);
    });

    $response = $client->send($request);
    echo $response->getStatusCode();
    // 200

.. attention::

    Any exception encountered while executing the ``before`` event will trigger
    the ``error`` event of a request.

.. _complete_event:

complete
--------

The ``complete`` event is emitted after a transaction completes and an entire
response has been received. The event is a ``GuzzleHttp\Event\CompleteEvent``.

You can intercept the ``complete`` event with a different response if needed
using the ``intercept()`` method of the event. This can be useful, for example,
for changing the response for caching.

.. code-block:: php

    use GuzzleHttp\Client;
    use GuzzleHttp\Event\CompleteEvent;
    use GuzzleHttp\Message\Response;

    $client = new Client(['base_url' => 'http://httpbin.org']);
    $request = $client->createRequest('GET', '/status/302');
    $cachedResponse = new Response(200);

    $request->getEmitter()->on(
        'complete',
        function (CompleteEvent $e) use ($cachedResponse) {
            if ($e->getResponse()->getStatusCode() == 302) {
                // Intercept the original transaction with the new response
                $e->intercept($cachedResponse);
            }
        }
    );

    $response = $client->send($request);
    echo $response->getStatusCode();
    // 200

.. attention::

    Any ``GuzzleHttp\Exception\RequestException`` encountered while executing
    the ``complete`` event will trigger the ``error`` event of a request.

.. _error_event:

error
-----

The ``error`` event is emitted when a request fails (whether it's from a
networking error or an HTTP protocol error). The event emitted is a
``GuzzleHttp\Event\ErrorEvent``.

This event is useful for retrying failed requests. Here's an example of
retrying failed basic auth requests by re-sending the original request with
a username and password.

.. code-block:: php

    use GuzzleHttp\Client;
    use GuzzleHttp\Event\ErrorEvent;

    $client = new Client(['base_url' => 'http://httpbin.org']);
    $request = $client->createRequest('GET', '/basic-auth/foo/bar');
    $request->getEmitter()->on('error', function (ErrorEvent $e) {
        if ($e->getResponse()->getStatusCode() == 401) {
            // Add authentication stuff as needed and retry the request
            $e->getRequest()->setHeader('Authorization', 'Basic ' . base64_encode('foo:bar'));
            // Get the client of the event and retry the request
            $newResponse = $e->getClient()->send($e->getRequest());
            // Intercept the original transaction with the new response
            $e->intercept($newResponse);
        }
    });

.. attention::

    If an ``error`` event is intercepted with a response, then the ``complete``
    event of a request is triggered. If the ``complete`` event fails, then the
    ``error`` event is triggered once again.

.. _progress_event:

progress
--------

The ``progress`` event is emitted when data is uploaded or downloaded. The
event emitted is a ``GuzzleHttp\Event\ProgressEvent``.

You can access the emitted progress values using the corresponding public
properties of the event object:

- ``$downloadSize``: The number of bytes that will be downloaded (if known)
- ``$downloaded``: The number of bytes that have been downloaded
- ``$uploadSize``: The number of bytes that will be uploaded (if known)
- ``$uploaded``: The number of bytes that have been uploaded

This event cannot be intercepted.

.. code-block:: php

    use GuzzleHttp\Client;
    use GuzzleHttp\Event\ProgressEvent;

    $client = new Client(['base_url' => 'http://httpbin.org']);
    $request = $client->createRequest('PUT', '/put', [
        'body' => str_repeat('.', 100000)
    ]);

    $request->getEmitter()->on('progress', function (ProgressEvent $e) {
        echo 'Downloaded ' . $e->downloaded . ' of ' . $e->downloadSize . ' '
            . 'Uploaded ' . $e->uploaded . ' of ' . $e->uploadSize . "\r";
    });

    $client->send($request);
    echo "\n";

.. _end_event:

end
---

The ``end`` event is a terminal event, emitted once per request, that provides
access to the repsonse that was received or the exception that was encountered.
The event emitted is a ``GuzzleHttp\Event\EndEvent``.

This event can be intercepted, but keep in mind that the ``complete`` event
will not fire after intercepting this event.

.. code-block:: php

    use GuzzleHttp\Client;
    use GuzzleHttp\Event\EndEvent;

    $client = new Client(['base_url' => 'http://httpbin.org']);
    $request = $client->createRequest('PUT', '/put', [
        'body' => str_repeat('.', 100000)
    ]);

    $request->getEmitter()->on('end', function (EndEvent $e) {
        if ($e->getException()) {
            echo 'Error: ' . $e->getException()->getMessage();
        } else {
            echo 'Response: ' . $e->getResponse();
        }
    });

    $client->send($request);
    echo "\n";
