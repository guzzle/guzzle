.. title:: Guzzle | PHP HTTP client and framework for consuming RESTful web services

======
Guzzle
======

Guzzle is a PHP HTTP client that is easy to customize.

- Pluggable HTTP adapters for sending requests serially or in parallel
- Does not require cURL, but ships with a built-in cURL adapter that provides
  parallel requests and persistent connections.
- Streams request and response bodies.
- Event driven customization hooks.
- Small core library.
- Plugins for caching, logging, OAuth, mocks, and more.

.. code-block:: php

    $client = new GuzzleHttp\Client();
    $response = $client->get('http://guzzlephp.org');

User guide
----------

.. toctree::
    :maxdepth: 2

    overview
    quickstart
    clients
    requests
    streams
    subscribers
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
