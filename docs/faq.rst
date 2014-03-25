===
FAQ
===

Is it possible to use Guzzle 3 and 4 in the same project?
=========================================================

Yes, because Guzzle 3 and 4 use different Packagist packages and different
namespaced. You simply need to add ``guzzle/guzzle`` (Guzzle 3) and
``guzzlehttp/guzzle`` (Guzzle 4+) to your project's composer.json file.

.. code-block:: javascript

    {
        "require": {
            "guzzle/guzzle": 3.*,
            "guzzlehttp/guzzle": 4.*
        }
    }

You might need to use Guzzle 3 and Guzzle 4 in the same project due to a
requirement of a legacy application or a dependency that has not yet migrated
to Guzzle 4.0.

How do I migrate from Guzzle 3 to 4?
====================================

See https://github.com/guzzle/guzzle/blob/master/UPGRADING.md#3x-to-40.

What is this Maximum function nesting error?
============================================

    Maximum function nesting level of '100' reached, aborting

You could run into this error if you have the XDebug extension installed and
you execute a lot of requests in callbacks.  This error message comes
specifically from the XDebug extension. PHP itself does not have a function
nesting limit. Change this setting in your php.ini to increase the limit::

    xdebug.max_nesting_level = 1000

[`source <http://stackoverflow.com/a/4293870/151504>`_]

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

How can I add custom cURL options?
==================================

cURL offer a huge number of `customizable options <http://us1.php.net/curl_setopt>`_.
While Guzzle normalizes many of these options across different adapters, there
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
