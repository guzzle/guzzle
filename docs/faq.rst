===
FAQ
===

Why should I use Guzzle?
========================

Guzzle makes it easy to send HTTP requests and super simple to integrate with
web services. Guzzle manages things like persistent connections, represents
query strings as collections, makes it simple to send streaming POST requests
with fields and files, and abstracts away the underlying HTTP transport layer.
By providing an object oriented interface for HTTP clients, requests, responses,
headers, and message bodies, Guzzle makes it so that you no longer need to fool
around with cURL options, stream contexts, or sockets.

**Asynchronous and Synchronous Requests**

Guzzle allows you to send both asynchronous and synchronous requests using the
same interface and no direct dependency on an event loop. This flexibility
allows Guzzle to send an HTTP request using the most appropriate HTTP handler
based on the request being sent. For example, when sending synchronous
requests, Guzzle will by default send requests using cURL easy handles to
ensure you're using the fastest possible method for serially transferring HTTP
requests. When sending asynchronous requests, Guzzle might use cURL's multi
interface or any other asynchronous handler you configure. When you request
streaming data, Guzzle will by default use PHP's stream wrapper.

**Streams**

Request and response message bodies use :doc:`Guzzle Streams <streams>`,
allowing you to stream data without needing to load it all into memory.
Guzzle's stream layer provides a large suite of functionality:

- You can modify streams at runtime using custom or a number of
  pre-made decorators.
- You can emit progress events as data is read from a stream.
- You can validate the integrity of a stream using a rolling hash as data is
  read from a stream.

**Event System and Plugins**

Guzzle's  event system allows you to completely modify the behavior of a client
or request at runtime to cater them for any API. You can send a request with a
client, and the client can do things like automatically retry your request if
it fails, automatically redirect, log HTTP messages that are sent over the
wire, emit progress events as data is uploaded and downloaded, sign requests
using OAuth 1.0, verify the integrity of messages before and after they are
sent over the wire, and anything else you might need.

**Testable**

Another important aspect of Guzzle is that it's really
:doc:`easy to test clients <testing>`. You can mock HTTP responses and when
testing an handler implementation, Guzzle provides a mock node.js web server.

**Ecosystem**

Guzzle has a large `ecosystem of plugins <http://guzzle.readthedocs.org/en/latest/index.html#http-components>`_,
including `service descriptions <https://github.com/guzzle/guzzle-services>`_
which allows you to abstract web services using service descriptions. These
service descriptions define how to serialize an HTTP request and how to parse
an HTTP response into a more meaningful model object.

- `Guzzle Command <https://github.com/guzzle/command>`_: Provides the building
  blocks for service description abstraction.
- `Guzzle Services <https://github.com/guzzle/guzzle-services>`_: Provides an
  implementation of "Guzzle Command" that utlizes Guzzle's service description
  format.

Does Guzzle require cURL?
=========================

No. Guzzle can use any HTTP handler to send requests. This means that Guzzle
can be used with cURL, PHP's stream wrapper, sockets, and non-blocking libraries
like `React <http://reactphp.org/>`_. You just need to configure a
`RingPHP <http://guzzle-ring.readthedocs.org/en/latest/>`_ handler to use a
different method of sending requests.

.. note::

    Guzzle has historically only utilized cURL to send HTTP requests. cURL is
    an amazing HTTP client (arguably the best), and Guzzle will continue to use
    it by default when it is available. It is rare, but some developers don't
    have cURL installed on their systems or run into version specific issues.
    By allowing swappable HTTP handlers, Guzzle is now much more customizable
    and able to adapt to fit the needs of more developers.

Can Guzzle send asynchronous requests?
======================================

Yes. Pass the ``future`` true request option to a request to send it
asynchronously. Guzzle will then return a ``GuzzleHttp\Message\FutureResponse``
object that can be used synchronously by accessing the response object like a
normal response, and it can be used asynchronoulsy using a promise that is
notified when the response is resolved with a real response or rejected with an
exception.

.. code-block:: php

    $request = $client->createRequest('GET', ['future' => true']);
    $client->send($request)->then(function ($response) {
        echo 'Got a response! ' . $response;
    });

You can force an asynchronous response to complete using the ``wait()`` method
of a response.

.. code-block:: php

    $request = $client->createRequest('GET', ['future' => true']);
    $futureResponse = $client->send($request);
    $futureResponse->wait();

How can I add custom cURL options?
==================================

cURL offer a huge number of `customizable options <http://us1.php.net/curl_setopt>`_.
While Guzzle normalizes many of these options across different handlers, there
are times when you need to set custom cURL options. This can be accomplished
by passing an associative array of cURL settings in the **curl** key of the
**config** request option.

For example, let's say you need to customize the outgoing network interface
used with a client.

.. code-block:: php

    $client->get('/', [
        'config' => [
            'curl' => [
                CURLOPT_INTERFACE => 'xxx.xxx.xxx.xxx'
            ]
        ]
    ]);

How can I add custom stream context options?
============================================

You can pass custom `stream context options <http://www.php.net/manual/en/context.php>`_
using the **stream_context** key of the **config** request option. The
**stream_context** array is an associative array where each key is a PHP
transport, and each value is an associative array of transport options.

For example, let's say you need to customize the outgoing network interface
used with a client and allow self-signed certificates.

.. code-block:: php

    $client->get('/', [
        'stream' => true,
        'config' => [
            'stream_context' => [
                'ssl' => [
                    'allow_self_signed' => true
                ],
                'socket' => [
                    'bindto' => 'xxx.xxx.xxx.xxx'
                ]
            ]
        ]
    ]);

Why am I getting an SSL verification error?
===========================================

You need to specify the path on disk to the CA bundle used by Guzzle for
verifying the peer certificate. See :ref:`verify-option`.

What is this Maximum function nesting error?
============================================

    Maximum function nesting level of '100' reached, aborting

You could run into this error if you have the XDebug extension installed and
you execute a lot of requests in callbacks.  This error message comes
specifically from the XDebug extension. PHP itself does not have a function
nesting limit. Change this setting in your php.ini to increase the limit::

    xdebug.max_nesting_level = 1000

Why am I getting a 417 error response?
======================================

This can occur for a number of reasons, but if you are sending PUT, POST, or
PATCH requests with an ``Expect: 100-Continue`` header, a server that does not
support this header will return a 417 response. You can work around this by
setting the ``expect`` request option to ``false``:

.. code-block:: php

    $client = new GuzzleHttp\Client();

    // Disable the expect header on a single request
    $response = $client->put('/', [], 'the body', [
        'expect' => false
    ]);

    // Disable the expect header on all client requests
    $client->setDefaultOption('expect', false)
