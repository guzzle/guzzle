=======
Clients
=======

Clients are used to create requests, create transactions, send requests
through an HTTP adapter, and return a response. You can add default request
options to a client that are applied to every request (e.g., default headers,
default query string parameters, etc.), and you can add event listeners and
subscribers to every request created by a client.

Creating a client
=================

The constructor of a client accepts an associative array of configuration
options.

base_url
    Configures a base URL for the client so that requests created
    using a relative URL are combined with the ``base_url`` of the client
    according to section `5.2 of RFC 3986 <http://tools.ietf.org/html/rfc3986#section-5.2>`_.

    .. code-block:: php

        // Create a client with a base URL
        $client = new GuzzleHttp\Client(['base_url' => 'https://github.com']);
        // Send a request to https://github.com/notifications
        $response = $client->get('/notifications');

    `Absolute URLs <http://tools.ietf.org/html/rfc3986#section-4.3>`_ sent
    through a client will not use the base URL of the client.

adapter
    Configures the HTTP adapter (``GuzzleHttp\Adapter\AdapterInterface``) used
    to transfer the HTTP requests of a client. Guzzle will, by default, utilize
    a stacked adapter that chooses the best adapter to use based on the provided
    request options and based on the extensions available in the environment. If
    cURL is installed, it will be used as the default adapter. However, if a
    request has the ``stream`` request option, the PHP stream wrapper adapter
    will be used (assuming ``allow_url_fopen`` is enabled in your PHP
    environment).

parallel_adapter
    Just like the ``adapter`` option, you can choose to specify an adapter
    that is used to send requests in parallel
    (``GuzzleHttp\Adapter\ParallelAdapterInterface``). Guzzle will by default
    use cURL to send requests in parallel, but if cURL is not available it will
    use the PHP stream wrapper and simply send requests serially.

message_factory
    Specifies the factory used to create HTTP requests and responses
    (``GuzzleHttp\Message\MessageFactoryInterface``).

defaults
    Associative array of :ref:`request-options` that are applied to every
    request created by the client. This allows you to specify things like
    default headers (e.g., User-Agent), default query string parameters, SSL
    configurations, and any other supported request options.

emitter
    Specifies an event emitter (``GuzzleHttp\Event\EmitterInterface``) instance
    to be used by the client to emit request events. This option is useful if
    you need to inject an emitter with listeners/subscribers already attached.

Here's an example of creating a client with various options, including using
a mock adapter that just returns the result of a callable function and a
base URL that is a URI template with parameters.

.. code-block:: php

    use GuzzleHttp\Client;

    $client = new Client([
        'base_url' => ['https://api.twitter.com/{version}/', ['version' => 'v1.1']],
        'defaults' => [
            'headers' => ['Foo' => 'Bar'],
            'query'   => ['testing' => '123'],
            'auth'    => ['username', 'password'],
            'proxy'   => 'tcp://localhost:80'
        ]
    ]);

Sending Requests
================

Requests can be created using various methods of a client. You can create
**and** send requests using one of the following methods:

- ``GuzzleHttp\Client::get``: Sends a GET request.
- ``GuzzleHttp\Client::head``: Sends a HEAD request
- ``GuzzleHttp\Client::post``: Sends a POST request
- ``GuzzleHttp\Client::put``: Sends a PUT request
- ``GuzzleHttp\Client::delete``: Sends a DELETE request
- ``GuzzleHttp\Client::options``: Sends an OPTIONS request

Each of the above methods accepts a URL as the first argument and an optional
associative array of :ref:`request-options` as the second argument.

.. code-block:: php

    $client = new GuzzleHttp\Client();

    $client->put('http://httpbin.org', [
        'headers' => ['X-Foo' => 'Bar'],
        'body' => 'this is the body!',
        'save_to' => '/path/to/local/file',
        'allow_redirects' => false,
        'timeout' => 5
    ]);

Error Handling
--------------

When a recoverable error is encountered while calling the ``send()`` method of
a client, a ``GuzzleHttp\Exception\RequestException`` is thrown.

.. code-block:: php

    use GuzzleHttp\Client;
    use GuzzleHttp\Exception\RequestException;

    $client = new Client();

    try {
        $client->get('http://httpbin.org');
    } catch (RequestException $e) {
        echo $e->getRequest() . "\n";
        if ($e->hasResponse()) {
            echo $e->getResponse() . "\n";
        }
    }

``GuzzleHttp\Exception\RequestException`` always contains a
``GuzzleHttp\Message\RequestInterface`` object that can be accessed using the
exception's ``getRequest()`` method.

A response might be present in the exception. In the event of a networking
error, no response will be received. You can check if a ``RequestException``
has a response using the ``hasResponse()`` method. If the exception has a
response, then you can access the associated
``GuzzleHttp\Message\ResponseInterface`` using the ``getResponse()`` method of
the exception.

HTTP Errors
~~~~~~~~~~~

If the ``exceptions`` request option is not set to ``false``, then exceptions
are thrown for HTTP protocol errors as well:
``GuzzleHttp\Exception\ClientErrorResponseException`` for 4xx level HTTP
responses and ``GuzzleHttp\Exception\ServerException`` for 5xx level responses,
both of which extend from ``GuzzleHttp\Exception\BadResponseException``.

Creating Requests
-----------------

You can create a request without sending it. This is useful for building up
requests over time or sending requests in parallel.

.. code-block:: php

    $request = $client->createRequest('GET', 'http://httpbin.org', [
        'headers' => ['X-Foo' => 'Bar']
    ]);

    // Modify the request as needed
    $request->setHeader('Baz', 'bar');

After creating a request, you can send it with the client's ``send()`` method.

.. code-block:: php

    $response = $client->send($request);

Sending Requests in Parallel
============================

You can send requests in parallel using a client object's ``sendAll()`` method.
The ``sendAll()`` method accepts an array or ``\Iterator`` that contains
``GuzzleHttp\Message\RequestInterface`` objects. In addition to providing the
requests to send, you can also specify an associative array of options that
will affect the transfer.

.. code-block:: php

    $requests = [
        $client->createRequest('GET', 'http://httpbin.org'),
        $client->createRequest('DELETE', 'http://httpbin.org/delete'),
        $client->createRequest('PUT', 'http://httpbin.org/put', ['body' => 'test'])
    ];

    $client->sendAll($requests);

The ``sendAll()`` method accepts the following associative array of options:

- **parallel**: Integer representing the maximum number of requests that are
  allowed to be sent in parallel.
- **before**: Callable or array representing the event listeners to add to
  each request's :ref:`before_event` event.
- **complete**: Callable or array representing the event listeners to add to
  each request's :ref:`complete_event` event.
- **error**: Callable or array representing the event listeners to add to
  each request's :ref:`error_event` event.

The "before", "complete", and "error" event options accept a callable or an
array of associative arrays where each associative array contains a "fn" key
with a callable value, an optional "priority" key representing the event
priority (with a default value of 0), and an optional "once" key that can be
set to true so that the event listener will be removed from the request after
it is first triggered.

.. code-block:: php

    use GuzzleHttp\Event\CompleteEvent;

    // Add a single event listener using a callable.
    $client->sendAll($requests, [
        'complete' => function (CompleteEvent $event) {
            echo 'Completed request to ' . $event->getRequest()->getUrl() . "\n";
            echo 'Response: ' . $event->getResponse()->getBody() . "\n\n";
        }
    ]);

    // The above is equivalent to the following, but the following structure
    // allows you to add multiple event listeners to the same event name.
    $client->sendAll($requests, [
        'complete' => [
            [
                'fn'       => function (CompleteEvent $event) { /* ... */ },
                'priority' => 0,    // Optional
                'once'     => false // Optional
            ]
        ]
    ]);

Asynchronous Response Handling
------------------------------

When sending requests in parallel, the request/response/error lifecycle must be
handled asynchronously. This means that you give the ``sendAll()`` method
multiple requests and handle the response or errors that is associated with the
request using event callbacks.

.. code-block:: php

    use GuzzleHttp\Event\ErrorEvent;

    $client->sendAll($requests, [
        'complete' => function (CompleteEvent $event) {
            echo 'Completed request to ' . $event->getRequest()->getUrl() . "\n";
            echo 'Response: ' . $event->getResponse()->getBody() . "\n\n";
            // Do something with the completion of the request...
        },
        'error' => function (ErrorEvent $event) {
            echo 'Request failed: ' . $event->getRequest()->getUrl() . "\n";
            echo $event->getException();
            // Do something to handle the error...
        }
    ]);

The ``GuzzleHttp\Event\ErrorEvent`` event object is emitted when an error
occurs during a transfer. With this event, you have access to the request that
was sent, the response that was received (if one was received), access to
transfer statistics, and the ability to intercept the exception with a
different ``GuzzleHttp\Message\ResponseInterface`` object. See :doc:`events`
for more information.

Handling Errors After Transferring
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

It sometimes might be easier to handle all of the errors that occurred during a
transfer after all of the requests have been sent. Here we are adding each
failed request to an array that we can use to process errors later.

.. code-block:: php

    use GuzzleHttp\Event\ErrorEvent;

    $errors = [];
    $client->sendAll($requests, [
        'error' => function (ErrorEvent $event) use (&$errors) {
            $errors[] = $event;
        }
    ]);

    foreach ($errors as $error) {
        // Handle the error...
    }

Throwing Errors Immediately
~~~~~~~~~~~~~~~~~~~~~~~~~~~

It sometimes is useful to throw exceptions immediately when the occur. The
following example shows how to use an event listener to throw exceptions
immeditaley and prevent subsequent requests from being sent.

.. code-block:: php

    use GuzzleHttp\Event\ErrorEvent;

    $client->sendAll($requests, [
        'error' => function (ErrorEvent $event) {
            throw $event->getException();
        }
    ]);

.. _batch-requests:

Batching Requests
-----------------

Sometimes you just want to send a few requests in parallel and then process
the results all at once after they've been sent. Guzzle provides a convenience
function ``GuzzleHttp\batch()`` that makes this very simple:

.. code-block:: php

    $client = new GuzzleHttp\Client();

    $requests = [
        $client->createRequest('GET', 'http://httpbin.org/get'),
        $client->createRequest('HEAD', 'http://httpbin.org/get'),
        $client->createRequest('PUT', 'http://httpbin.org/put'),
    ];

    $results = GuzzleHttp\batch($client, $requests);

    // Results is an SplObjectStorage object where each request is a key
    foreach ($results as $request) {
        echo $request->getUrl() . "\n";
        // Get the result (either a ResponseInterface or RequestException)
        $result = $results[$request];
        if ($result instanceof ResponseInterface) {
            // Interact with the response directly
            echo $result->getStatusCode();
        } else {
            // Get the exception message
            echo $result->getMessage();
        }
    }

``GuzzleHttp\batch()`` accepts an optional associative array of options in the
third argument that allows you to specify the 'before', 'complete' and 'error'
events as well as specify the maximum number of requests to send in parallel
using the 'parallel' option key. This options array is the exact same format as
the options array exposed in ``GuzzleHttp\ClientInterface::sendAll()``.

.. _request-options:

Request Options
===============

You can customize requests created by a client using **request options**.
Request options control various aspects of a request including, headers,
query string parameters, timeout settings, the body of a request, and much
more.

All of the following examples use the following client:

.. code-block:: php

    $client = new GuzzleHttp\Client(['base_url' => 'http://httpbin.org']);

headers
-------

:Summary: Associative array of headers to add to the request. Each key is the
    name of a header, and each value is a string or array of strings
    representing the header field values.
:Types: array
:Defaults: None

.. code-block:: php

    // Set various headers on a request
    $client->get('/get', [
        'headers' => [
            'User-Agent' => 'testing/1.0',
            'Accept'     => 'application/json',
            'X-Foo'      => ['Bar', 'Baz']
        ]
    ]);

body
----

:Summary: The ``body`` option is used to control the body of an entity
    enclosing request (e.g., PUT, POST, PATCH).
:Types:
    - string
    - ``fopen()`` resource
    - ``GuzzleHttp\Stream\StreamInterface``
    - ``GuzzleHttp\Post\PostBodyInterface``
:Default: None

This setting can be set to any of the following types:

- string

  .. code-block:: php

      // You can send requests that use a string as the message body.
      $client->put('/put', ['body' => 'foo']);

- resource returned from ``fopen()``

  .. code-block:: php

      // You can send requests that use a stream resource as the body.
      $resource = fopen('http://httpbin.org', 'r');
      $client->put('/put', ['body' => $resource]);

- Array

  Use an array to send POST style requests that use a
  ``GuzzleHttp\Post\PostBodyInterface`` object as the body.

  .. code-block:: php

      // You can send requests that use a POST body containing fields & files.
      $client->post('/post', [
          'body' => [
              'field' => 'abc',
              'other_field' => '123',
              'file_name' => fopen('/path/to/file', 'r')
          ]
      ]);

- ``GuzzleHttp\Stream\StreamInterface``

  .. code-block:: php

      // You can send requests that use a Guzzle stream object as the body
      $stream = GuzzleHttp\Stream\Stream::factory('contents...');
      $client->post('/post', ['body' => $stream]);

json
----

:Summary: The ``json`` option is used to easily upload JSON encoded data as the
    body of a request. A Content-Type header of ``application/json`` will be
    added if no Content-Type header is already present on the message.
:Types:
    Any PHP type that can be operated on by PHP's ``json_encode()`` function.
:Default: None

.. code-block:: php

    $request = $client->createRequest('/put', ['json' => ['foo' => 'bar']]);
    echo $request->getHeader('Content-Type');
    // application/json
    echo $request->getBody();
    // {"foo":"bar"}

.. note::

    This request option does not support customizing the Content-Type header
    or any of the options from PHP's `json_encode() <http://www.php.net/manual/en/function.json-encode.php>`_
    function. If you need to customize these settings, then you must pass the
    JSON encoded data into the request yourself using the ``body`` request
    option and you must specify the correct Content-Type header using the
    ``headers`` request option.

query
-----

:Summary: Associative array of query string values to add to the request.
:Types:
    - array
    - ``GuzzleHttp\Query``
:Default: None

.. code-block:: php

    // Send a GET request to /get?foo=bar
    $client->get('/get', ['query' => ['foo' => 'bar']]);

Query strings specified in the ``query`` option are combined with any query
string values that are parsed from the URL.

.. code-block:: php

    // Send a GET request to /get?abc=123&foo=bar
    $client->get('/get?abc=123', ['query' => ['foo' => 'bar']]);

auth
----

:Summary: Pass an array of HTTP authentication parameters to use with the
    request. The array must contain the username in index [0], the password in
    index [1], and you can optionally provide a built-in authentication type in
    index [2]. Pass ``null`` to disable authentication for a request.
:Types:
    - array
    - string
    - null
:Default: None

The built-in authentication types are as follows:

basic
    Use `basic HTTP authentication <http://www.ietf.org/rfc/rfc2069.txt>`_ in
    the ``Authorization`` header (the default setting used if none is
    specified).

    .. code-block:: php

        $client->get('/get', ['auth' => ['username', 'password']]);

digest
    Use `digest authentication <http://www.ietf.org/rfc/rfc2069.txt>`_ (must be
    supported by the HTTP adapter).

    .. code-block:: php

        $client->get('/get', ['auth' => ['username', 'password', 'digest']]);

    *This is currently only supported when using the cURL adapter, but creating
    a replacement that can be used with any HTTP adapter is planned.*

.. important::

    The authentication type (whether it's provided as a string or as the third
    option in an array) is always converted to a lowercase string. Take this
    into account when implementing custom authentication types and when
    implementing custom message factories.

Custom Authentication Schemes
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

You can also provide a string representing a custom authentication type name.
When using a custom authentication type string, you will need to implement
the authentication method in an event listener that checks the ``auth`` request
option of a request before it is sent. Authentication listeners that require
a request is not modified after they are signed should have a very low priority
to ensure that they are fired last or near last in the event chain.

.. code-block:: php

    use GuzzleHttp\Event\BeforeEvent;
    use GuzzleHttp\Event\RequestEvents;

    /**
     * Custom authentication listener that handles the "foo" auth type.
     *
     * Listens to the "before" event of a request and only modifies the request
     * when the "auth" config setting of the request is "foo".
     */
    class FooAuth implements GuzzleHttp\Event\SubscriberInterface
    {
        private $password;

        public function __construct($password)
        {
            $this->password = $password;
        }

        public function getEvents()
        {
            return ['before' => ['sign', RequestEvents::SIGN_REQUEST]];
        }

        public function sign(BeforeEvent $e)
        {
            if ($e->getRequest()->getConfig()['auth'] == 'foo') {
                $e->getRequest()->setHeader('X-Foo', 'Foo ' . $this->password);
            }
        }
    }

    $client->getEmitter()->attach(new FooAuth('password'));
    $client->get('/', ['auth' => 'foo']);

Adapter Specific Authentication Schemes
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

If you need to use authentication methods provided by cURL (e.g., NTLM, GSS,
etc.), then you need to specify a curl adapter option in the ``options``
request option array. See :ref:`config-option` for more information.

.. _cookies-option:

cookies
-------

:Summary: Specifies whether or not cookies are used in a request or what cookie
    jar to use or what cookies to send.
:Types:
    - bool
    - array
    - ``GuzzleHttp\Cookie\CookieJarInterface``
:Default: None

Set to ``true`` to use a shared cookie session associated with the client.

.. code-block:: php

    // Enable cookies using the shared cookie jar of the client.
    $client->get('/get', ['cookies' => true]);

Pass an associative array containing cookies to send in the request and start a
new cookie session.

.. code-block:: php

    // Enable cookies and send specific cookies
    $client->get('/get', ['cookies' => ['foo' => 'bar']]);

Set to a ``GuzzleHttp\Cookie\CookieJarInterface`` object to use an existing
cookie jar.

.. code-block:: php

    $jar = new GuzzleHttp\Cookie\CookieJar();
    $client->get('/get', ['cookies' => $jar]);

.. _allow_redirects-option:

allow_redirects
---------------

:Summary: Describes the redirect behavior of a request
:Types:
    - bool
    - array
:Default: ``['max' => 5, 'strict' => false, 'referer' => true]``

Set to ``false`` to disable redirects.

.. code-block:: php

    $res = $client->get('/redirect/3', ['allow_redirects' => false]);
    echo $res->getStatusCode();
    // 302

Set to ``true`` (the default setting) to enable normal redirects with a maximum
number of 5 redirects.

.. code-block:: php

    $res = $client->get('/redirect/3');
    echo $res->getStatusCode();
    // 200

Pass an associative array containing the 'max' key to specify the maximum
number of redirects, optionally provide a 'strict' key value to specify
whether or not to use strict RFC compliant redirects (meaning redirect POST
requests with POST requests vs. doing what most browsers do which is redirect
POST requests with GET requests), and optionally provide a 'referer' key to
specify whether or not the "Referer" header should be added when redirecting.

.. code-block:: php

    $res = $client->get('/redirect/3', [
        'allow_redirects' => [
            'max'     => 10,
            'strict'  => true,
            'referer' => true
        ]
    ]);
    echo $res->getStatusCode();
    // 200

.. _save_to-option:

save_to
-------

:Summary: Specify where the body of a response will be saved.
:Types:
    - string
    - ``fopen()`` resource
    - ``GuzzleHttp\Stream\StreamInterface``
:Default: PHP temp stream

Pass a string to specify the path to a file that will store the contents of the
response body:

.. code-block:: php

    $client->get('/stream/20', ['save_to' => '/path/to/file']);

Pass a resource returned from ``fopen()`` to write the response to a PHP stream:

.. code-block:: php

    $resource = fopen('/path/to/file', 'w');
    $client->get('/stream/20', ['save_to' => $resource]);

Pass a ``GuzzleHttp\Stream\StreamInterface`` object to stream the response body
to an open Guzzle stream:

.. code-block:: php

    $resource = fopen('/path/to/file', 'w');
    $stream = GuzzleHttp\Stream\Stream::factory($resource);
    $client->get('/stream/20', ['save_to' => $stream]);

.. _events-option:

events
------

:Summary: An associative array mapping event names to a callable. Or an
    associative array containing the 'fn' key that maps to a callable, an
    optional 'priority' key used to specify the event priority, and an optional
    'once' key used to specify if the event should remove itself the first time
    it is triggered.
:Types: array
:Default: None

.. code-block:: php

    use GuzzleHttp\Event\BeforeEvent;
    use GuzzleHttp\Event\HeadersEvent;
    use GuzzleHttp\Event\CompleteEvent;
    use GuzzleHttp\Event\ErrorEvent;

    $client->get('/', [
        'events' => [
            'before' => function (BeforeEvent $e) { echo 'Before'; },
            'headers' => function (HeadersEvent $e) { echo 'Headers'; },
            'complete' => function (CompleteEvent $e) { echo 'Complete'; },
            'error' => function (ErrorEvent $e) { echo 'Error'; },
        ]
    ]);

Here's an example of using the associative array format for control over the
priority and whether or not an event should be triggered more than once.

.. code-block:: php

    $client->get('/', [
        'events' => [
            'before' => [
                'fn'       => function (BeforeEvent $e) { echo 'Before'; },
                'priority' => 100,
                'once'     => true
            ]
        ]
    ]);

.. _subscribers-option:

subscribers
-----------

:Summary: Array of event subscribers to add to the request. Each value in the
    array must be an instance of ``GuzzleHttp\Event\SubscriberInterface``.
:Types: array
:Default: None

.. code-block:: php

    use GuzzleHttp\Subscriber\History;
    use GuzzleHttp\Subscriber\Mock;
    use GuzzleHttp\Message\Response;

    $history = new History();
    $mock = new Mock([new Response(200)]);
    $client->get('/', ['subscribers' => [$history, $mock]]);

    echo $history;
    // Outputs the request and response history

.. _exceptions-option:

exceptions
----------

:Summary: Set to ``false`` to disable throwing exceptions on an HTTP protocol
    errors (i.e., 4xx and 5xx responses). Exceptions are thrown by default when
    HTTP protocol errors are encountered.
:Types: bool
:Default: ``true``

.. code-block:: php

    $client->get('/status/500');
    // Throws a GuzzleHttp\Exception\ServerException

    $res = $client->get('/status/500', ['exceptions' => false]);
    echo $res->getStatusCode();
    // 500

.. _timeout-option:

timeout
-------

:Summary: Float describing the timeout of the request in seconds. Use ``0``
    to wait indefinitely (the default behavior).
:Types: float
:Default: ``0``

.. code-block:: php

    // Timeout if a server does not return a response in 3.14 seconds.
    $client->get('/delay/5', ['timeout' => 3.14]);
    // PHP Fatal error:  Uncaught exception 'GuzzleHttp\Exception\RequestException'

.. _connect_timeout-option:

connect_timeout
---------------

:Summary: Float describing the number of seconds to wait while trying to connect
    to a server. Use ``0`` to wait indefinitely (the default behavior).
:Types: float
:Default: ``0``

.. code-block:: php

    // Timeout if the client fails to connect to the server in 3.14 seconds.
    $client->get('/delay/5', ['connect_timeout' => 3.14]);

.. note::

    This setting must be supported by the HTTP adapter used to send a request.
    ``connect_timeout`` is currently only supported by the built-in cURL
    adapter.

.. _verify-option:

verify
------

:Summary: Describes the SSL certificate verification behavior of a request.
    Set to ``true`` to enable SSL certificate verification (the default). Set
    to ``false`` to disable certificate verification (this is insecure!). Set
    to a string to provide the path to a CA bundle to enable verification using
    a custom certificate.
:Types:
    - bool
    - string
:Default: ``true``

.. code-block:: php

    // Use a custom SSL certificate
    $client->get('/', ['verify' => '/path/to/cert.pem']);

    // Disable validation
    $client->get('/', ['verify' => false]);

.. _cert-option:

cert
----

:Summary: Set to a string to specify the path to a file containing a PEM
    formatted client side certificate. If a password is required, then set to
    an array containing the path to the PEM file in the first array element
    followed by the password required for the certificate in the second array
    element.
:Types:
    - string
    - array
:Default: None

.. code-block:: php

    $client->get('/', ['cert' => ['/path/server.pem', 'password']]);

.. _ssl_key-option:

ssl_key
-------

:Summary: Specify the path to a file containing a private SSL key in PEM
    format. If a password is required, then set to an array containing the path
    to the SSL key in the first array element followed by the password required
    for the certificate in the second element.
:Types:
    - string
    - array
:Default: None

.. note::

    ``ssl_key`` is implemented by HTTP adapters. This is currently only
    supported by the cURL adapter, but might be supported by other third-part
    adapters.

.. _proxy-option:

proxy
-----

:Summary: Pass a string to specify an HTTP proxy, or an array to specify
    different proxies for different protocols.
:Types:
    - string
    - array
:Default: None

Pass a string to specify a proxy for all protocols.

.. code-block:: php

    $client->get('/', ['proxy' => 'tcp://localhost:8125']);

Pass an associative array to specify HTTP proxies for specific URI schemes
(i.e., "http", "https").

.. code-block:: php

    $client->get('/', [
        'proxy' => [
            'http'  => 'tcp://localhost:8125', // Use this proxy with "http"
            'https' => 'tcp://localhost:9124'  // Use this proxy with "https"
        ]
    ]);

.. note::

    You can provide proxy URLs that contain a scheme, username, and password.
    For example, ``"http://username:password@192.168.16.1:10"``.

.. _debug-option:

debug
-----

:Summary: Set to ``true`` or set to a PHP stream returned by ``fopen()`` to
    enable debug output with the adapter used to send a request. For example,
    when using cURL to transfer requests, cURL's verbose of ``CURLOPT_VERBOSE``
    will be emitted. When using the PHP stream wrapper, stream wrapper
    notifications will be emitted. If set to true, the output is written to
    PHP's STDOUT. If a PHP stream is provided, output is written to the stream.
:Types:
    - bool
    - ``fopen()`` resource
:Default: None

.. code-block:: php

    $client->get('/get', ['debug' => true]);

Running the above example would output something like the following:

::

    * About to connect() to httpbin.org port 80 (#0)
    *   Trying 107.21.213.98... * Connected to httpbin.org (107.21.213.98) port 80 (#0)
    > GET /get HTTP/1.1
    Host: httpbin.org
    User-Agent: Guzzle/4.0 curl/7.21.4 PHP/5.5.7

    < HTTP/1.1 200 OK
    < Access-Control-Allow-Origin: *
    < Content-Type: application/json
    < Date: Sun, 16 Feb 2014 06:50:09 GMT
    < Server: gunicorn/0.17.4
    < Content-Length: 335
    < Connection: keep-alive
    <
    * Connection #0 to host httpbin.org left intact

.. _stream-option:

stream
------

:Summary: Set to ``true`` to stream a response rather than download it all
    up-front.
:Types: bool
:Default: ``false``

.. code-block:: php

    $response = $client->get('/stream/20', ['stream' => true]);
    // Read bytes off of the stream until the end of the stream is reached
    $body = $response->getBody();
    while (!$body->eof()) {
        echo $body->read(1024);
    }

.. note::

    Streaming response support must be implemented by the HTTP adapter used by
    a client. This option might not be supported by every HTTP adapter, but the
    interface of the response object remains the same regardless of whether or
    not it is supported by the adapter.

.. _expect-option:

expect
------

:Summary: Controls the behavior of the "Expect: 100-Continue" header.
:Types:
    - bool
    - integer
:Default: ``1048576``

Set to ``true`` to enable the "Expect: 100-Continue" header for all requests
that sends a body. Set to ``false`` to disable the "Expect: 100-Continue"
header for all requests. Set to a number so that the size of the payload must
be greater than the number in order to send the Expect header. Setting to a
number will send the Expect header for all requests in which the size of the
payload cannot be determined or where the body is not rewindable.

By default, Guzzle will add the "Expect: 100-Continue" header when the size of
the body of a request is greater than 1 MB and a request is using HTTP/1.1.

.. note::

    This option only takes effect when using HTTP/1.1. The HTTP/1.0 and
    HTTP/2.0 protocols do not support the "Expect: 100-Continue" header.
    Support for handling the "Expect: 100-Continue" workflow must be
    implemented by Guzzle HTTP adapters used by a client.

.. _version-option:

version
-------

:Summary: Protocol version to use with the request.
:Types: string, float
:Default: ``1.1``

.. code-block:: php

    // Force HTTP/1.0
    $request = $client->createRequest('GET', '/get', ['version' => 1.0]);
    echo $request->getProtocolVersion();
    // 1.0

.. _config-option:

config
------

:Summary: Associative array of config options that are forwarded to a request's
    configuration collection. These values are used as configuration options
    that can be consumed by plugins and adapters.
:Types: array
:Default: None

.. code-block:: php

    $request = $client->createRequest('GET', '/get', ['config' => ['foo' => 'bar']]);
    echo $request->getConfig('foo');
    // 'bar'

Some HTTP adapters allow you to specify custom adapter-specific settings. For
example, you can pass custom cURL options to requests by passing an associative
array in the ``config`` request option under the ``curl`` key.

.. code-block:: php

    // Use custom cURL options with the request. This example uses NTLM auth
    // to authenticate with a server.
    $client->get('/', [
        'config' => [
            'curl' => [
                CURLOPT_HTTPAUTH => CURLAUTH_NTLM,
                CURLOPT_USERPWD  => 'username:password'
            ]
        ]
    ]);

Event Subscribers
=================

Requests emit lifecycle events when they are transferred. A client object has a
``GuzzleHttp\Common\EventEmitter`` object that can be used to add event
*listeners* and event *subscribers* to all requests created by the client.

.. important::

    **Every** event listener or subscriber added to a client will be added to
    every request created by the client.

.. code-block:: php

    use GuzzleHttp\Client;
    use GuzzleHttp\Event\BeforeEvent;

    $client = new Client();

    // Add a listener that will echo out requests before they are sent
    $client->getEmitter()->on('before', function (BeforeEvent $e) {
        echo 'About to send request: ' . $e->getRequest();
    });

    $client->get('http://httpbin.org/get');
    // Outputs the request as a string because of the event

See :doc:`events` for more information on the event system used in Guzzle.

Environment Variables
=====================

Guzzle exposes a few environment variables that can be used to customize the
behavior of the library.

``GUZZLE_CURL_SELECT_TIMEOUT``
    Controls the duration in seconds that ``GuzzleHttp\Adapter\Curl\MultiAdapter``
    will use when selecting handles using ``curl_multi_select()``. Some systems
    have issues with PHP's implementation of ``curl_multi_select()`` where
    calling this function always results in waiting for the maximum duration of
    the timeout.
``HTTP_PROXY``
    Defines the proxy to use when sending requests using the "http" protocol.
``HTTPS_PROXY``
    Defines the proxy to use when sending requests using the "https" protocol.

Relevant ini Settings
---------------------

Guzzle can utilize PHP ini settings when configuring clients.

``openssl.cafile``
    Specifies the path on disk to a CA file in PEM format to use when sending
    requests over "https". See: https://wiki.php.net/rfc/tls-peer-verification#phpini_defaults
