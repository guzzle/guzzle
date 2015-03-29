===============
Request Options
===============

You can customize requests created and transferred by a client using
**request options**. Request options control various aspects of a request
including, headers, query string parameters, timeout settings, the body of a
request, and much more.

All of the following examples use the following client:

.. code-block:: php

    $client = new GuzzleHttp\Client(['base_uri' => 'http://httpbin.org']);

.. _allow_redirects-option:

allow_redirects
---------------

:Summary: Describes the redirect behavior of a request
:Types:
        - bool
        - array
:Default:

::

    [
        'max'       => 5,
        'strict'    => false,
        'referer'   => true,
        'protocols' => ['http', 'https']
    ]

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
number of redirects, provide a 'strict' key value to specify whether or not to
use strict RFC compliant redirects (meaning redirect POST requests with POST
requests vs. doing what most browsers do which is redirect POST requests with
GET requests), provide a 'referer' key to specify whether or not the "Referer"
header should be added when redirecting, and provide a 'protocols' array that
specifies which protocols are supported for redirects (defaults to
``['http', 'https']``).

.. code-block:: php

    $res = $client->get('/redirect/3', [
        'allow_redirects' => [
            'max'       => 10,       // allow at most 10 redirects.
            'strict'    => true,     // use "strict" RFC compliant redirects.
            'referer'   => true,     // add a Referer header
            'protocols' => ['https'] // only allow https URLs
        ]
    ]);
    echo $res->getStatusCode();
    // 200


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
        Use `basic HTTP authentication <http://www.ietf.org/rfc/rfc2069.txt>`_
        in the ``Authorization`` header (the default setting used if none is
        specified).

    .. code-block:: php

        $client->get('/get', ['auth' => ['username', 'password']]);

    digest
        Use `digest authentication <http://www.ietf.org/rfc/rfc2069.txt>`_
        (must be supported by the HTTP handler).

    .. code-block:: php

        $client->get('/get', ['auth' => ['username', 'password', 'digest']]);

    .. note::

        This is currently only supported when using the cURL handler, but
        creating a replacement that can be used with any HTTP handler is
        planned.


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

- ``GuzzleHttp\Stream\StreamInterface``

  .. code-block:: php

      // You can send requests that use a Guzzle stream object as the body
      $stream = GuzzleHttp\Psr7\stream_for('contents...');
      $client->post('/post', ['body' => $stream]);


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

    This setting must be supported by the HTTP handler used to send a request.
    ``connect_timeout`` is currently only supported by the built-in cURL
    handler.


.. _debug-option:

debug
-----

:Summary: Set to ``true`` or set to a PHP stream returned by ``fopen()`` to
    enable debug output with the handler used to send a request. For example,
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

.. _decode_content-option:

decode_content
--------------

:Summary: Specify whether or not ``Content-Encoding`` responses (gzip,
    deflate, etc.) are automatically decoded.
:Types:
        - string
        - bool
:Default: ``true``

This option can be used to control how content-encoded response bodies are
handled. By default, ``decode_content`` is set to true, meaning any gzipped
or deflated response will be decoded by Guzzle.

When set to ``false``, the body of a response is never decoded, meaning the
bytes pass through the handler unchanged.

.. code-block:: php

    // Request gzipped data, but do not decode it while downloading
    $client->get('/foo.js', [
        'headers'        => ['Accept-Encoding' => 'gzip'],
        'decode_content' => false
    ]);

When set to a string, the bytes of a response are decoded and the string value
provided to the ``decode_content`` option is passed as the ``Accept-Encoding``
header of the request.

.. code-block:: php

    // Pass "gzip" as the Accept-Encoding header.
    $client->get('/foo.js', ['decode_content' => 'gzip']);


.. _delay-option:

delay
-----

:Summary: The number of milliseconds to delay before sending the request. This
    is often used for delaying before retrying a request. Handlers SHOULD
    implement this if possible, but it is not a strict requirement.
:Types: integer/float
:Default: 0


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
    implemented by Guzzle HTTP handlers used by a client.


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


.. _http-errors-option:

http_errors
-----------

:Summary: Set to ``false`` to disable throwing exceptions on an HTTP protocol
    errors (i.e., 4xx and 5xx responses). Exceptions are thrown by default when
    HTTP protocol errors are encountered.
:Types: bool
:Default: ``true``

.. code-block:: php

    $client->get('/status/500');
    // Throws a GuzzleHttp\Exception\ServerException

    $res = $client->get('/status/500', ['http_errors' => false]);
    echo $res->getStatusCode();
    // 500


json
----

:Summary: The ``json`` option is used to easily upload JSON encoded data as the
    body of a request. A Content-Type header of ``application/json`` will be
    added if no Content-Type header is already present on the message.
:Types:
    Any PHP type that can be operated on by PHP's ``json_encode()`` function.
:Default: None

.. code-block:: php

    $request = $client->createRequest('PUT', '/put', ['json' => ['foo' => 'bar']]);
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


query
-----

:Summary: Associative array of query string values to add to the request.
:Types:
    - array
    - string
:Default: None

.. code-block:: php

    // Send a GET request to /get?foo=bar
    $client->get('/get', ['query' => ['foo' => 'bar']]);

Query strings specified in the ``query`` option will overwrite an query string
values supplied in the URI of a request.

.. code-block:: php

    // Send a GET request to /get?foo=bar
    $client->get('/get?abc=123', ['query' => ['foo' => 'bar']]);


.. _sink-option:

sink
----

:Summary: Specify where the body of a response will be saved.
:Types:
    - string
    - ``fopen()`` resource
    - ``GuzzleHttp\Stream\StreamInterface``
:Default: PHP temp stream

Pass a string to specify the path to a file that will store the contents of the
response body:

.. code-block:: php

    $client->get('/stream/20', ['sink' => '/path/to/file']);

Pass a resource returned from ``fopen()`` to write the response to a PHP stream:

.. code-block:: php

    $resource = fopen('/path/to/file', 'w');
    $client->get('/stream/20', ['sink' => $resource]);

Pass a ``Psr\Http\Message\StreamableInterface`` object to stream the response
body to an open PSR-7 stream.

.. code-block:: php

    $resource = fopen('/path/to/file', 'w');
    $stream = GuzzleHttp\Psr7\stream_for($resource);
    $client->get('/stream/20', ['save_to' => $stream]);

.. note::

    The ``save_to`` request option has been deprecated in favor of the
    ``sink`` request option. Providing the ``save_to`` option is now an alias
    of ``sink``.


.. _stack-option:

stack
-----

:Summary: A function that accepts a ``GuzzleHttp\HandlerStack`` object before
    a request is sent. The function may mutate the handler stack to add custom
    conditional middleware before sending each request. This is useful when you
    wish to only apply middleware conditionally, or if the middleware needs to
    be in a certain position in the handler stack relative to other middleware
    added by Guzzle (e.g., 'allow_redirects', 'http_errors', 'cookies', and
    'prepare_body').
:Types: callable
:Default: None

Each time a request is created, Guzzle will clone the client's handler stack
and use it to send the request. Guzzle adds a few middleware to the request's
handler stack based on the provided request options.

- If the ``allow_redirects`` option is set, Guzzle will add a middleware with
  the name ``allow_redirects``.
- If the ``http_errors`` request option is set, Guzzle will add a middleware
  with the name ``http_errors``.
- If the ``cookies`` request option is set, Guzzle will add a middleware with
  the name ``cookies``.
- Finally, Guzzle will always add a middleware called ``prepare_body`` which is
  used to add a Content-Length and Content-Type header if needed.

If you need to add middleware before or after any of these default middlewares,
you can use the ``stack`` request option to add middleware before or after
them by name.

.. code-block:: php

    use Psr\Http\Message\RequestInterface;
    use GuzzleHttp\HandlerStack;

    $client->get('/', [
        'stack' => function (HandlerStack $stack, array $options) {
            // Add a custom middleware to each request after the redirect
            // middleware.
            $stack->after('redirect', function ($handler) {
                return function (RequestInterface, array $options) use ($handler) {
                    // Create a modified request.
                    $request = $request->withHeader('X-Foo' => 'Bar');
                    // Send the request using the next handler.
                    return $handler($request, $options);
                }
            });
        }
    ]);


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

    ``ssl_key`` is implemented by HTTP handlers. This is currently only
    supported by the cURL handler, but might be supported by other third-part
    handlers.


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

    Streaming response support must be implemented by the HTTP handler used by
    a client. This option might not be supported by every HTTP handler, but the
    interface of the response object remains the same regardless of whether or
    not it is supported by the handler.


.. _verify-option:

verify
------

:Summary: Describes the SSL certificate verification behavior of a request.

    - Set to ``true`` to enable SSL certificate verification and use the default
      CA bundle provided by operating system.
    - Set to ``false`` to disable certificate verification (this is insecure!).
    - Set to a string to provide the path to a CA bundle to enable verification
      using a custom certificate.
:Types:
    - bool
    - string
:Default: ``true``

.. code-block:: php

    // Use the system's CA bundle (this is the default setting)
    $client->get('/', ['verify' => true]);

    // Use a custom SSL certificate on disk.
    $client->get('/', ['verify' => '/path/to/cert.pem']);

    // Disable validation entirely (don't do this!).
    $client->get('/', ['verify' => false]);

Not all system's have a known CA bundle on disk. For example, Windows and
OS X do not have a single common location for CA bundles. When setting
"verify" to ``true``, Guzzle will do its best to find the most appropriate
CA bundle on your system. When using cURL or the PHP stream wrapper on PHP
versions >= 5.6, this happens by default. When using the PHP stream
wrapper on versions < 5.6, Guzzle tries to find your CA bundle in the
following order:

1. Check if ``openssl.cafile`` is set in your php.ini file.
2. Check if ``curl.cainfo`` is set in your php.ini file.
3. Check if ``/etc/pki/tls/certs/ca-bundle.crt`` exists (Red Hat, CentOS,
   Fedora; provided by the ca-certificates package)
4. Check if ``/etc/ssl/certs/ca-certificates.crt`` exists (Ubuntu, Debian;
   provided by the ca-certificates package)
5. Check if ``/usr/local/share/certs/ca-root-nss.crt`` exists (FreeBSD;
   provided by the ca_root_nss package)
6. Check if ``/usr/local/etc/openssl/cert.pem`` (OS X; provided by homebrew)
7. Check if ``C:\windows\system32\curl-ca-bundle.crt`` exists (Windows)
8. Check if ``C:\windows\curl-ca-bundle.crt`` exists (Windows)

The result of this lookup is cached in memory so that subsequent calls
in the same process will return very quickly. However, when sending only
a single request per-process in something like Apache, you should consider
setting the ``openssl.cafile`` environment variable to the path on disk
to the file so that this entire process is skipped.

If you do not need a specific certificate bundle, then Mozilla provides a
commonly used CA bundle which can be downloaded
`here <https://raw.githubusercontent.com/bagder/ca-bundle/master/ca-bundle.crt>`_
(provided by the maintainer of cURL). Once you have a CA bundle available on
disk, you can set the "openssl.cafile" PHP ini setting to point to the path to
the file, allowing you to omit the "verify" request option. Much more detail on
SSL certificates can be found on the
`cURL website <http://curl.haxx.se/docs/sslcerts.html>`_.


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
