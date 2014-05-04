======================
Testing Guzzle Clients
======================

Guzzle provides several tools that will enable you to easily mock the HTTP
layer without needing to send requests over the internet.

* Mock subscriber
* Mock adapter
* Node.js web server for integration testing

Mock Subscriber
===============

When testing HTTP clients, you often need to simulate specific scenarios like
returning a successful response, returning an error, or returning specific
responses in a certain order. Because unit tests need to be predictable, easy
to bootstrap, and fast, hitting an actual remote API is a test smell.

Guzzle provides a mock subscriber that can be attached to clients or requests
that allows you to queue up a list of responses to use rather than hitting a
remote API.

.. code-block:: php

    use GuzzleHttp\Client;
    use GuzzleHttp\Subscriber\Mock;
    use GuzzleHttp\Message\Response;

    $client = new Client();

    // Create a mock subscriber and queue two responses.
    $mock = new Mock([
        new Response(200, ['X-Foo' => 'Bar']),         // Use response object
        "HTTP/1.1 202 OK\r\nContent-Length: 0\r\n\r\n"  // Use a response string
    ]);

    // Add the mock subscriber to the client.
    $client->getEmitter()->attach($mock);
    // The first request is intercepted with the first response.
    echo $client->get('/')->getStatusCode();
    //> 200
    // The second request is intercepted with the second response.
    echo $client->get('/')->getStatusCode();
    //> 202

When no more responses are in the queue and a request is sent, an
``OutOfBoundsException`` is thrown.

History Subscriber
==================

When using things like the ``Mock`` subscriber, you often need to know if the
requests you expected to send were sent exactly as you intended. While the mock
subscriber responds with mocked responses, the ``GuzzleHttp\Subscriber\History``
subscriber maintains a history of the requests that were sent by a client.

.. code-block:: php

    use GuzzleHttp\Client;
    use GuzzleHttp\Subscriber\History;

    $client = new Client();
    $history = new History();

    // Add the history subscriber to the client.
    $client->getEmitter()->attach($history);

    $client->get('http://httpbin.org/get');
    $client->head('http://httpbin.org/get');

    // Count the number of transactions
    echo count($history);
    //> 2
    // Get the last request
    $lastRequest = $history->getLastRequest();
    // Get the last response
    $lastRequest = $history->getLastResponse();

    // Iterate over the transactions that were sent
    foreach ($history as $transaction) {
        echo $transaction['request']->getMethod();
        //> GET, HEAD
        echo $transaction['response']->getStatusCode();
        //> 200, 200
    }

The history subscriber can also be printed, revealing the requests and
responses that were sent as a string, in order.

.. code-block:: php

    echo $history;

::

    > GET /get HTTP/1.1
    Host: httpbin.org
    User-Agent: Guzzle/4.0-dev curl/7.21.4 PHP/5.5.8

    < HTTP/1.1 200 OK
    Access-Control-Allow-Origin: *
    Content-Type: application/json
    Date: Tue, 25 Mar 2014 03:53:27 GMT
    Server: gunicorn/0.17.4
    Content-Length: 270
    Connection: keep-alive

    {
      "headers": {
        "Connection": "close",
        "X-Request-Id": "3d0f7d5c-c937-4394-8248-2b8e03fcccdb",
        "User-Agent": "Guzzle/4.0-dev curl/7.21.4 PHP/5.5.8",
        "Host": "httpbin.org"
      },
      "origin": "76.104.247.1",
      "args": {},
      "url": "http://httpbin.org/get"
    }

    > HEAD /get HTTP/1.1
    Host: httpbin.org
    User-Agent: Guzzle/4.0-dev curl/7.21.4 PHP/5.5.8

    < HTTP/1.1 200 OK
    Access-Control-Allow-Origin: *
    Content-length: 270
    Content-Type: application/json
    Date: Tue, 25 Mar 2014 03:53:27 GMT
    Server: gunicorn/0.17.4
    Connection: keep-alive

Mock Adapter
============

In addition to using the Mock subscriber, you can use the
``GuzzleHttp\Adapter\MockAdapter`` as the adapter of a client to return the
same response over and over or return the result of a callable function.

.. code-block:: php

    use GuzzleHttp\Client;
    use GuzzleHttp\Adapter\MockAdapter;
    use GuzzleHttp\Adapter\TransactionInterface;
    use GuzzleHttp\Message\Response;

    $mockAdapter = new MockAdapter(function (TransactionInterface $trans) {
        // You have access to the request
        $request = $trans->getRequest();
        // Return a response
        return new Response(200);
    });

    $client = new Client(['adapter' => $mockAdapter]);

Test Web Server
===============

Using mock responses is usually enough when testing a web service client. When
implementing custom :doc:`HTTP adapters <adapters>`, you'll need to send actual
HTTP requests in order to sufficiently test the adapter. However, a best
practice is to contact a local web server rather than a server over the
internet.

- Tests are more reliable
- Tests do not require a network connection
- Tests have no external dependencies

Using the test server
---------------------

Guzzle ships with a node.js test server that receives requests and returns
responses from a queue. The test server exposes a simple API that is used to
enqueue responses and inspect the requests that it has received.

In order to use the web server, you'll need to manually require
``tests/Server.php``. Any operation on the ``Server`` object will ensure that
the server is running and wait until it is able to receive requests before
returning.

.. code-block:: php

    // Require the test server (using something like this).
    require __DIR__ . '/../vendor/guzzlehttp/guzzle/tests/Server.php';

    use GuzzleHttp\Client;
    use GuzzleHttp\Tests\Server;

    // Start the server and queue a response
    Server::enqueue("HTTP/1.1 200 OK\r\n\Content-Length: 0r\n\r\n");

    $client = new Client(['base_url' => Server::$url]);
    echo $client->get('/foo')->getStatusCode();
    // 200

``GuzzleHttp\Tests\Server`` provides a static interface to the test server. You
can queue an HTTP response or an array of responses by calling
``Server::enqueue()``. This method accepts a string representing an HTTP
response message, a ``GuzzleHttp\Message\ResponseInterface``, or an array of
HTTP message strings / ``GuzzleHttp\Message\ResponseInterface`` objects.

.. code-block:: php

    // Queue single response
    Server::enqueue("HTTP/1.1 200 OK\r\n\Content-Length: 0r\n\r\n");

    // Clear the queue and queue an array of responses
    Server::enqueue([
        "HTTP/1.1 200 OK\r\n\Content-Length: 0r\n\r\n",
        "HTTP/1.1 404 Not Found\r\n\Content-Length: 0r\n\r\n"
    ]);

When a response is queued on the test server, the test server will remove any
previously queued responses. As the server receives requests, queued responses
are dequeued and returned to the request. When the queue is empty, the server
will return a 500 response.

You can inspect the requests that the server has retrieved by calling
``Server::received()``. This method accepts an optional ``$hydrate`` parameter
that specifies if you are retrieving an array of HTTP requests as strings or an
array of ``GuzzleHttp\Message\RequestInterface`` objects.

.. code-block:: php

    foreach (Server::received() as $response) {
        echo $response;
    }

You can clear the list of received requests from the web server using the
``Server::flush()`` method.

.. code-block:: php

    Server::flush();
    echo count(Server::received());
    // 0
