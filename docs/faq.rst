===
FAQ
===

Does Guzzle require cURL?
=========================

No. Guzzle can use any HTTP handler to send requests. This means that Guzzle
can be used with cURL, PHP's stream wrapper, sockets, and non-blocking libraries
like `React <http://reactphp.org/>`_. You just need to configure an HTTP handler
to use a different method of sending requests.

.. note::

    Guzzle has historically only utilized cURL to send HTTP requests. cURL is
    an amazing HTTP client (arguably the best), and Guzzle will continue to use
    it by default when it is available. It is rare, but some developers don't
    have cURL installed on their systems or run into version specific issues.
    By allowing swappable HTTP handlers, Guzzle is now much more customizable
    and able to adapt to fit the needs of more developers.


Can Guzzle send asynchronous requests?
======================================

Yes. You can use the ``requestAsync``, ``sendAsync``, ``getAsync``,
``headAsync``, ``putAsync``, ``postAsync``, ``deleteAsync``, and ``patchAsync``
methods of a client to send an asynchronous request. The client will return a
``GuzzleHttp\Promise\PromiseInterface`` object. You can chain ``then``
functions off of the promise.

.. code-block:: php

    $promise = $client->requestAsync('GET', 'http://httpbin.org/get');
    $promise->then(function ($response) {
        echo 'Got a response! ' . $response->getStatusCode();
    });

You can force an asynchronous response to complete using the ``wait()`` method
of the returned promise.

.. code-block:: php

    $promise = $client->requestAsync('GET', 'http://httpbin.org/get');
    $response = $promise->wait();


How can I add custom cURL options?
==================================

cURL offer a huge number of `customizable options <http://us1.php.net/curl_setopt>`_.
While Guzzle normalizes many of these options across different handlers, there
are times when you need to set custom cURL options. This can be accomplished
by passing an associative array of cURL settings in the **curl** key of a
request.

For example, let's say you need to customize the outgoing network interface
used with a client.

.. code-block:: php

    $client->request('GET', '/', [
        'curl' => [
            CURLOPT_INTERFACE => 'xxx.xxx.xxx.xxx'
        ]
    ]);


How can I add custom stream context options?
============================================

You can pass custom `stream context options <http://www.php.net/manual/en/context.php>`_
using the **stream_context** key of the request option. The **stream_context**
array is an associative array where each key is a PHP transport, and each value
is an associative array of transport options.

For example, let's say you need to customize the outgoing network interface
used with a client and allow self-signed certificates.

.. code-block:: php

    $client->request('GET', '/', [
        'stream' => true,
        'stream_context' => [
            'ssl' => [
                'allow_self_signed' => true
            ],
            'socket' => [
                'bindto' => 'xxx.xxx.xxx.xxx'
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
you execute a lot of requests in callbacks. This error message comes
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
    $response = $client->request('PUT', '/', ['expect' => false]);

    // Disable the expect header on all client requests
    $client = new GuzzleHttp\Client(['expect' => false]);
