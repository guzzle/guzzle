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
    $client->setConfig('defaults/expect', false)
