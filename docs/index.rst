.. title:: Guzzle | PHP HTTP client and framework for consuming RESTful web services

======
Guzzle
======

Guzzle is a PHP HTTP client that makes it easy to send HTTP requests and
trivial to integrate with web services.

- Manages things like persistent connections, simplifies sending streaming
  POST requests with fields and files, and abstracts away the underlying HTTP
  transport layer.
- Can send both synchronous and asynchronous requests.
- Pluggable HTTP handlers allows Guzzle to integrate with any method you choose
  for sending HTTP requests over the wire (e.g., cURL, sockets, PHP's stream
  wrapper, non-blocking event loops like `React <http://reactphp.org/>`_, etc.).
- Guzzle makes it so that you no longer need to fool around with cURL options,
  stream contexts, or sockets.
- Middleware system allows you to augment the behavior of a client.

.. code-block:: php

    $client = new GuzzleHttp\Client();
    $response = $client->get('http://guzzlephp.org');
    $res = $client->get('https://api.github.com/user', ['auth' =>  ['user', 'pass']]);
    echo $res->getStatusCode();
    // "200"
    echo $res->getHeader('content-type');
    // 'application/json; charset=utf8'
    echo $res->getBody();
    // {"type":"User"...'
    var_export($res->json());
    // Outputs the JSON decoded data

    // Send an asynchronous request.
    $promise = $client->getAsync('http://httpbin.org');
    $promise->then(function ($response) {
        echo 'I completed! ' . $response->getStatusCode();
    });


User guide
----------

.. toctree::
    :maxdepth: 2

    overview
    quickstart
    clients
    handlers-and-middleware
    psr7
    testing
    faq
