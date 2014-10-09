=============
Ring Adapters
=============

Guzzle uses Guzzle-Ring adapters to send HTTP requests over the wire.
Guzzle-Ring provides a low-level library that can be used to "glue" Guzzle with
any transport method you choose. By default, Guzzle utilizes cURL and PHP's
stream wrappers to send HTTP requests.

Guzzle-Ring adapters makes it extremely simple to integrate Guzzle with any
HTTP transport. For example, you could quite easily bridge Guzzle and React
to use Guzzle in React's event loop.

Using an Adapter
----------------

You can change the adapter used by a client using the `adapter` option in the
``GuzzleHttp\Client`` constructor.

.. code-block:: php

    use GuzzleHttp\Client;
    use GuzzleHttp\Ring\Client\MockAdapter;

    // Create a mock adapter that always returns a 200 response.
    $adapter = new MockAdapter(['status' => 200]);

    // Configure to client to use the mock adapter.
    $client = new Client(['adapter' => $adapter]);

At its core, adapters are simply PHP callables that accept a request array
and return a ``GuzzleHttp\Ring\Future\FutureArrayInterface``. This future array
can be used just like a normal PHP array, causing it to block, or you can use
the promise interface using the ``then()`` method of the future. Guzzle hooks
up to the Guzzle-Ring project using a very simple bridge class
(``GuzzleHttp\RingBridge``).

Creating an Adapter
-------------------

See the `Guzzle-Ring <http://guzzle-ring.readthedocs.org>`_ project
documentation for more information on creating custom adapters that can be
used with Guzzle clients.
