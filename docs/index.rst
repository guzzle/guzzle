.. title:: Guzzle | PHP HTTP client and framework for consuming RESTful web services

======
Guzzle
======

Guzzle is a PHP HTTP client that makes it easy to work with HTTP/1.1 and takes
the pain out of consuming web services.

- Pluggable HTTP adapters that can send requests serially or in parallel
- Doesn't require cURL, but uses cURL by default
- Streams data for both uploads and downloads
- Provides event hooks & plugins for cookies, caching, logging, OAuth, mocks, etc...
- Keep-Alive & connection pooling
- SSL Verification
- Automatic decompression of response bodies
- Streaming multipart file uploads
- Connection timeouts

.. code-block:: php

    $client = new GuzzleHttp\Client();
    $response = $client->get('http://guzzlephp.org');
    $res = $client->get('https://api.github.com/user', ['auth' =>  ['user', 'pass']]);
    echo $res->statusCode();
    // 200
    echo $res->getHeader('content-type');
    // 'application/json; charset=utf8'
    echo $res->getBody();
    // {"type":"User"...'
    var_export($res->json());
    // Outputs the JSON decoded data

User guide
----------

.. toctree::
    :maxdepth: 2

    overview
    quickstart
    clients
    http-messages
    events
    streams
    faq

Libraries
---------

There are a number of libraries that can be used on top of or alongside
Guzzle. Here is a list of components that makeup Guzzle itself, official
libraries provided by the Guzzle organization, and commonly used libraries
provided by third party developers.

.. toctree::
    :maxdepth: 2

    libraries/components
    libraries/guzzle
    libraries/guzzle-service
    libraries/third-party

API Documentation
-----------------

.. toctree::
    :maxdepth: 2

    api
    migrating-to-4
