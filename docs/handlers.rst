================
RingPHP Handlers
================

Guzzle uses RingPHP handlers to send HTTP requests over the wire.
RingPHP provides a low-level library that can be used to "glue" Guzzle with
any transport method you choose. By default, Guzzle utilizes cURL and PHP's
stream wrappers to send HTTP requests.

RingPHP handlers makes it extremely simple to integrate Guzzle with any
HTTP transport. For example, you could quite easily bridge Guzzle and React
to use Guzzle in React's event loop.

Using a handler
---------------

You can change the handler used by a client using the ``handler`` option in the
``GuzzleHttp\Client`` constructor.

.. code-block:: php

    use GuzzleHttp\Client;
    use GuzzleHttp\Ring\Client\MockHandler;

    // Create a mock handler that always returns a 200 response.
    $handler = new MockHandler(['status' => 200]);

    // Configure to client to use the mock handler.
    $client = new Client(['handler' => $handler]);

At its core, handlers are simply PHP callables that accept a request array
and return a ``GuzzleHttp\Ring\Future\FutureArrayInterface``. This future array
can be used just like a normal PHP array, causing it to block, or you can use
the promise interface using the ``then()`` method of the future. Guzzle hooks
up to the RingPHP project using a very simple bridge class
(``GuzzleHttp\RingBridge``).

Creating a handler
------------------

See the `RingPHP <http://guzzle-ring.readthedocs.org>`_ project
documentation for more information on creating custom handlers that can be
used with Guzzle clients.
