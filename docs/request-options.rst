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
            'max'             => 5,
            'strict'          => false,
            'referer'         => false,
            'protocols'       => ['http', 'https'],
            'track_redirects' => false
        ]

:Constant: ``GuzzleHttp\RequestOptions::ALLOW_REDIRECTS``

Set to ``false`` to disable redirects.

.. code-block:: php

    $res = $client->request('GET', '/redirect/3', ['allow_redirects' => false]);
    echo $res->getStatusCode();
    // 302

Set to ``true`` (the default setting) to enable normal redirects with a maximum
number of 5 redirects.

.. code-block:: php

    $res = $client->request('GET', '/redirect/3');
    echo $res->getStatusCode();
    // 200

You can also pass an associative array containing the following key value
pairs:

- max: (int, default=5) maximum number of allowed redirects.
- strict: (bool, default=false) Set to true to use strict redirects.
  Strict RFC compliant redirects mean that POST redirect requests are sent as
  POST requests vs. doing what most browsers do which is redirect POST requests
  with GET requests.
- referer: (bool, default=false) Set to true to enable adding the Referer
  header when redirecting.
- protocols: (array, default=['http', 'https']) Specified which protocols are
  allowed for redirect requests.
- on_redirect: (callable) PHP callable that is invoked when a redirect
  is encountered. The callable is invoked with the original request and the
  redirect response that was received. Any return value from the on_redirect
  function is ignored.
- track_redirects: (bool) When set to ``true``, each redirected URI and status
  code encountered will be tracked in the ``X-Guzzle-Redirect-History`` and
  ``X-Guzzle-Redirect-Status-History`` headers respectively. All URIs and
  status codes will be stored in the order which the redirects were encountered.

  Note: When tracking redirects the ``X-Guzzle-Redirect-History`` header will
  exclude the initial request's URI and the ``X-Guzzle-Redirect-Status-History``
  header will exclude the final status code.

.. code-block:: php

    use Psr\Http\Message\RequestInterface;
    use Psr\Http\Message\ResponseInterface;
    use Psr\Http\Message\UriInterface;

    $onRedirect = function(
        RequestInterface $request,
        ResponseInterface $response,
        UriInterface $uri
    ) {
        echo 'Redirecting! ' . $request->getUri() . ' to ' . $uri . "\n";
    };

    $res = $client->request('GET', '/redirect/3', [
        'allow_redirects' => [
            'max'             => 10,        // allow at most 10 redirects.
            'strict'          => true,      // use "strict" RFC compliant redirects.
            'referer'         => true,      // add a Referer header
            'protocols'       => ['https'], // only allow https URLs
            'on_redirect'     => $onRedirect,
            'track_redirects' => true
        ]
    ]);

    echo $res->getStatusCode();
    // 200

    echo $res->getHeaderLine('X-Guzzle-Redirect-History');
    // http://first-redirect, http://second-redirect, etc...

    echo $res->getHeaderLine('X-Guzzle-Redirect-Status-History');
    // 301, 302, etc...

.. warning::

    This option only has an effect if your handler has the
    ``GuzzleHttp\Middleware::redirect`` middleware. This middleware is added
    by default when a client is created with no handler, and is added by
    default when creating a handler with ``GuzzleHttp\HandlerStack::create``.

.. note::

    This option has **no** effect when making requests using ``GuzzleHttp\Client::sendRequest()``. In order to stay compliant with PSR-18 any redirect response is returned as is.


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
:Constant: ``GuzzleHttp\RequestOptions::AUTH``

The built-in authentication types are as follows:

basic
    Use `basic HTTP authentication <http://www.ietf.org/rfc/rfc7617.txt>`_
    in the ``Authorization`` header (the default setting used if none is
    specified).

.. code-block:: php

    $client->request('GET', '/get', ['auth' => ['username', 'password']]);

digest
    Use `digest authentication <http://www.ietf.org/rfc/rfc2069.txt>`_
    (must be supported by the HTTP handler).

.. code-block:: php

    $client->request('GET', '/get', [
        'auth' => ['username', 'password', 'digest']
    ]);

.. note::

    This is currently only supported when using the cURL handler, but
    creating a replacement that can be used with any HTTP handler is
    planned.

ntlm
    Use `Microsoft NTLM authentication <https://msdn.microsoft.com/en-us/library/windows/desktop/aa378749(v=vs.85).aspx>`_
    (must be supported by the HTTP handler).

.. code-block:: php

    $client->request('GET', '/get', [
        'auth' => ['username', 'password', 'ntlm']
    ]);

.. note::

    This is currently only supported when using the cURL handler.


body
----

:Summary: The ``body`` option is used to control the body of an entity
    enclosing request (e.g., PUT, POST, PATCH).
:Types:
    - string
    - ``fopen()`` resource
    - ``Psr\Http\Message\StreamInterface``
:Default: None
:Constant: ``GuzzleHttp\RequestOptions::BODY``

This setting can be set to any of the following types:

- string

  .. code-block:: php

      // You can send requests that use a string as the message body.
      $client->request('PUT', '/put', ['body' => 'foo']);

- resource returned from ``fopen()``

  .. code-block:: php

      // You can send requests that use a stream resource as the body.
      $resource = \GuzzleHttp\Psr7\Utils::tryFopen('http://httpbin.org', 'r');
      $client->request('PUT', '/put', ['body' => $resource]);

- ``Psr\Http\Message\StreamInterface``

  .. code-block:: php

      // You can send requests that use a Guzzle stream object as the body
      $stream = GuzzleHttp\Psr7\Utils::streamFor('contents...');
      $client->request('POST', '/post', ['body' => $stream]);

.. note::

    This option cannot be used with ``form_params``, ``multipart``, or ``json``


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
:Constant: ``GuzzleHttp\RequestOptions::CERT``

.. code-block:: php

    $client->request('GET', '/', ['cert' => ['/path/server.pem', 'password']]);


.. _cert_blob-option:

cert-blob
----

:Summary: Set to a string containing a formatted client side certificate.
        If a password is required, then set to an array containing the PEM certificate
        and the password.
        If the certificate format is 'DER' or 'P12' the type must be specified.
:Types:
        - string
        - array
:Default: None
:Constant: ``GuzzleHttp\RequestOptions::CERT_BLOB``

.. code-block:: php

    $client->request('GET', '/', [
        'cert_blob' => [
            'cert' => 'certificate',
            'password' => 'password',
            'type' => 'P12',
        ],
    ]);

.. note::

    ``cert_blob`` is implemented by HTTP handlers. This is currently only
    supported by the cURL handler, but might be supported by other third-part
    handlers.
    The option is available in PHP >= 8.1


.. _cookies-option:

cookies
-------

:Summary: Specifies whether or not cookies are used in a request or what cookie
        jar to use or what cookies to send.
:Types: ``GuzzleHttp\Cookie\CookieJarInterface``
:Default: None
:Constant: ``GuzzleHttp\RequestOptions::COOKIES``

You must specify the cookies option as a
``GuzzleHttp\Cookie\CookieJarInterface`` or ``false``.

.. code-block:: php

    $jar = new \GuzzleHttp\Cookie\CookieJar();
    $client->request('GET', '/get', ['cookies' => $jar]);

.. warning::

    This option only has an effect if your handler has the
    ``GuzzleHttp\Middleware::cookies`` middleware. This middleware is added
    by default when a client is created with no handler, and is added by
    default when creating a handler with ``GuzzleHttp\HandlerStack::create``.

.. tip::

    When creating a client, you can set the default cookie option to ``true``
    to use a shared cookie session associated with the client.


.. _connect_timeout-option:

connect_timeout
---------------

:Summary: Float describing the number of seconds to wait while trying to connect
        to a server. Use ``0`` to wait 300 seconds (the default behavior).
:Types: float
:Default: ``0``
:Constant: ``GuzzleHttp\RequestOptions::CONNECT_TIMEOUT``

.. code-block:: php

    // Timeout if the client fails to connect to the server in 3.14 seconds.
    $client->request('GET', '/delay/5', ['connect_timeout' => 3.14]);

.. note::

    This setting must be supported by the HTTP handler used to send a request.
    ``connect_timeout`` is currently only supported by the built-in cURL
    handler.


.. _crypto_method-option:

crypto_method
---------------

:Summary: A value describing the minimum TLS protocol version to use.
:Types: int
:Default: None
:Constant: ``GuzzleHttp\RequestOptions::CRYPTO_METHOD``

.. code-block:: php

    $client->request('GET', '/foo', ['crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT]);

.. note::

    This setting must be set to one of the ``STREAM_CRYPTO_METHOD_TLS*_CLIENT``
    constants. PHP 7.4 or higher is required in order to use TLS 1.3, and cURL
    7.34.0 or higher is required in order to specify a crypto method, with cURL
    7.52.0 or higher being required to use TLS 1.3.


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
:Constant: ``GuzzleHttp\RequestOptions::DEBUG``

.. code-block:: php

    $client->request('GET', '/get', ['debug' => true]);

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
:Constant: ``GuzzleHttp\RequestOptions::DECODE_CONTENT``

This option can be used to control how content-encoded response bodies are
handled. By default, ``decode_content`` is set to true, meaning any gzipped
or deflated response will be decoded by Guzzle.

When set to ``false``, the body of a response is never decoded, meaning the
bytes pass through the handler unchanged.

.. code-block:: php

    // Request gzipped data, but do not decode it while downloading
    $client->request('GET', '/foo.js', [
        'headers'        => ['Accept-Encoding' => 'gzip'],
        'decode_content' => false
    ]);

When set to a string, the bytes of a response are decoded and the string value
provided to the ``decode_content`` option is passed as the ``Accept-Encoding``
header of the request.

.. code-block:: php

    // Pass "gzip" as the Accept-Encoding header.
    $client->request('GET', '/foo.js', ['decode_content' => 'gzip']);


.. _delay-option:

delay
-----

:Summary: The number of milliseconds to delay before sending the request.
:Types:
    - integer
    - float
:Default: null
:Constant: ``GuzzleHttp\RequestOptions::DELAY``


.. _expect-option:

expect
------

:Summary: Controls the behavior of the "Expect: 100-Continue" header.
:Types:
    - bool
    - integer
:Default: ``1048576``
:Constant: ``GuzzleHttp\RequestOptions::EXPECT``

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


force_ip_resolve
----------------

:Summary: Set to "v4" if you want the HTTP handlers to use only ipv4 protocol or "v6" for ipv6 protocol.
:Types: string
:Default: null
:Constant: ``GuzzleHttp\RequestOptions::FORCE_IP_RESOLVE``

.. code-block:: php

    // Force ipv4 protocol
    $client->request('GET', '/foo', ['force_ip_resolve' => 'v4']);

    // Force ipv6 protocol
    $client->request('GET', '/foo', ['force_ip_resolve' => 'v6']);

.. note::

    This setting must be supported by the HTTP handler used to send a request.
    ``force_ip_resolve`` is currently only supported by the built-in cURL
    and stream handlers.


form_params
-----------

:Summary: Used to send an `application/x-www-form-urlencoded` POST request.
:Types: array
:Constant: ``GuzzleHttp\RequestOptions::FORM_PARAMS``

Associative array of form field names to values where each value is a string or
array of strings. Sets the Content-Type header to
application/x-www-form-urlencoded when no Content-Type header is already
present.

.. code-block:: php

    $client->request('POST', '/post', [
        'form_params' => [
            'foo' => 'bar',
            'baz' => ['hi', 'there!']
        ]
    ]);

.. note::

    ``form_params`` cannot be used with the ``multipart`` option. You will need to use
    one or the other. Use ``form_params`` for ``application/x-www-form-urlencoded``
    requests, and ``multipart`` for ``multipart/form-data`` requests.

    This option cannot be used with ``body``, ``multipart``, or ``json``


headers
-------

:Summary: Associative array of headers to add to the request. Each key is the
    name of a header, and each value is a string or array of strings
    representing the header field values.
:Types: array
:Defaults: None
:Constant: ``GuzzleHttp\RequestOptions::HEADERS``

.. code-block:: php

    // Set various headers on a request
    $client->request('GET', '/get', [
        'headers' => [
            'User-Agent' => 'testing/1.0',
            'Accept'     => 'application/json',
            'X-Foo'      => ['Bar', 'Baz']
        ]
    ]);

Headers may be added as default options when creating a client. When headers
are used as default options, they are only applied if the request being created
does not already contain the specific header. This includes both requests passed
to the client in the ``send()`` and ``sendAsync()`` methods, and requests
created by the client (e.g., ``request()`` and ``requestAsync()``).

.. code-block:: php

    $client = new GuzzleHttp\Client(['headers' => ['X-Foo' => 'Bar']]);

    // Will send a request with the X-Foo header.
    $client->request('GET', '/get');

    // Sets the X-Foo header to "test", which prevents the default header
    // from being applied.
    $client->request('GET', '/get', ['headers' => ['X-Foo' => 'test']]);

    // Will disable adding in default headers.
    $client->request('GET', '/get', ['headers' => null]);

    // Will not overwrite the X-Foo header because it is in the message.
    use GuzzleHttp\Psr7\Request;
    $request = new Request('GET', 'http://foo.com', ['X-Foo' => 'test']);
    $client->send($request);

    // Will overwrite the X-Foo header with the request option provided in the
    // send method.
    use GuzzleHttp\Psr7\Request;
    $request = new Request('GET', 'http://foo.com', ['X-Foo' => 'test']);
    $client->send($request, ['headers' => ['X-Foo' => 'overwrite']]);


.. _http-errors-option:

http_errors
-----------

:Summary: Set to ``false`` to disable throwing exceptions on an HTTP protocol
    errors (i.e., 4xx and 5xx responses). Exceptions are thrown by default when
    HTTP protocol errors are encountered.
:Types: bool
:Default: ``true``
:Constant: ``GuzzleHttp\RequestOptions::HTTP_ERRORS``

.. code-block:: php

    $client->request('GET', '/status/500');
    // Throws a GuzzleHttp\Exception\ServerException

    $res = $client->request('GET', '/status/500', ['http_errors' => false]);
    echo $res->getStatusCode();
    // 500

.. warning::

    This option only has an effect if your handler has the
    ``GuzzleHttp\Middleware::httpErrors`` middleware. This middleware is added
    by default when a client is created with no handler, and is added by
    default when creating a handler with ``GuzzleHttp\HandlerStack::create``.


idn_conversion
--------------

:Summary: Internationalized Domain Name (IDN) support (enabled by default if
    ``intl`` extension is available).
:Types:
    - bool
    - int
:Default: ``true`` if ``intl`` extension is available (and ICU library is 4.6+ for PHP 7.2+), ``false`` otherwise
:Constant: ``GuzzleHttp\RequestOptions::IDN_CONVERSION``

.. code-block:: php

    $client->request('GET', 'https://яндекс.рф');
    // яндекс.рф is translated to xn--d1acpjx3f.xn--p1ai before passing it to the handler

    $res = $client->request('GET', 'https://яндекс.рф', ['idn_conversion' => false]);
    // The domain part (яндекс.рф) stays unmodified

Enables/disables IDN support, can also be used for precise control by combining
IDNA_* constants (except IDNA_ERROR_*), see ``$options`` parameter in
`idn_to_ascii() <https://www.php.net/manual/en/function.idn-to-ascii.php>`_
documentation for more details.


json
----

:Summary: The ``json`` option is used to easily upload JSON encoded data as the
    body of a request. A Content-Type header of ``application/json`` will be
    added if no Content-Type header is already present on the message.
:Types:
    Any PHP type that can be operated on by PHP's ``json_encode()`` function.
:Default: None
:Constant: ``GuzzleHttp\RequestOptions::JSON``

.. code-block:: php

    $response = $client->request('PUT', '/put', ['json' => ['foo' => 'bar']]);

Here's an example of using the ``tap`` middleware to see what request is sent
over the wire.

.. code-block:: php

    use GuzzleHttp\Middleware;

    // Create a middleware that echoes parts of the request.
    $tapMiddleware = Middleware::tap(function ($request) {
        echo $request->getHeaderLine('Content-Type');
        // application/json
        echo $request->getBody();
        // {"foo":"bar"}
    });

    // The $handler variable is the handler passed in the
    // options to the client constructor.
    $response = $client->request('PUT', '/put', [
        'json'    => ['foo' => 'bar'],
        'handler' => $tapMiddleware($handler)
    ]);

.. note::

    This request option does not support customizing the Content-Type header
    or any of the options from PHP's `json_encode() <http://www.php.net/manual/en/function.json-encode.php>`_
    function. If you need to customize these settings, then you must pass the
    JSON encoded data into the request yourself using the ``body`` request
    option and you must specify the correct Content-Type header using the
    ``headers`` request option.

    This option cannot be used with ``body``, ``form_params``, or ``multipart``


multipart
---------

:Summary: Sets the body of the request to a `multipart/form-data` form.
:Types: array
:Constant: ``GuzzleHttp\RequestOptions::MULTIPART``

The value of ``multipart`` is an array of associative arrays, each containing
the following key value pairs:

- ``name``: (string, required) the form field name
- ``contents``: (StreamInterface/resource/string, required) The data to use in
  the form element.
- ``headers``: (array) Optional associative array of custom headers to use with
  the form element.
- ``filename``: (string) Optional string to send as the filename in the part.

.. code-block:: php

    use GuzzleHttp\Psr7;

    $client->request('POST', '/post', [
        'multipart' => [
            [
                'name'     => 'foo',
                'contents' => 'data',
                'headers'  => ['X-Baz' => 'bar']
            ],
            [
                'name'     => 'baz',
                'contents' => Psr7\Utils::tryFopen('/path/to/file', 'r')
            ],
            [
                'name'     => 'qux',
                'contents' => Psr7\Utils::tryFopen('/path/to/file', 'r'),
                'filename' => 'custom_filename.txt'
            ],
        ]
    ]);

.. note::

    ``multipart`` cannot be used with the ``form_params`` option. You will need to
    use one or the other. Use ``form_params`` for ``application/x-www-form-urlencoded``
    requests, and ``multipart`` for ``multipart/form-data`` requests.

    This option cannot be used with ``body``, ``form_params``, or ``json``


.. _on-headers:

on_headers
----------

:Summary: A callable that is invoked when the HTTP headers of the response have
    been received but the body has not yet begun to download.
:Types: - callable
:Constant: ``GuzzleHttp\RequestOptions::ON_HEADERS``

The callable accepts a ``Psr\Http\Message\ResponseInterface`` object. If an exception
is thrown by the callable, then the promise associated with the response will
be rejected with a ``GuzzleHttp\Exception\RequestException`` that wraps the
exception that was thrown.

You may need to know what headers and status codes were received before data
can be written to the sink.

.. code-block:: php

    // Reject responses that are greater than 1024 bytes.
    $client->request('GET', 'http://httpbin.org/stream/1024', [
        'on_headers' => function (ResponseInterface $response) {
            if ($response->getHeaderLine('Content-Length') > 1024) {
                throw new \Exception('The file is too big!');
            }
        }
    ]);

.. note::

    When writing HTTP handlers, the ``on_headers`` function must be invoked
    before writing data to the body of the response.


.. _on_stats:

on_stats
--------

:Summary: ``on_stats`` allows you to get access to transfer statistics of a
    request and access the lower level transfer details of the handler
    associated with your client. ``on_stats`` is a callable that is invoked
    when a handler has finished sending a request. The callback is invoked
    with transfer statistics about the request, the response received, or the
    error encountered. Included in the data is the total amount of time taken
    to send the request.
:Types: - callable
:Constant: ``GuzzleHttp\RequestOptions::ON_STATS``

The callable accepts a ``GuzzleHttp\TransferStats`` object.

.. code-block:: php

    use GuzzleHttp\TransferStats;

    $client = new GuzzleHttp\Client();

    $client->request('GET', 'http://httpbin.org/stream/1024', [
        'on_stats' => function (TransferStats $stats) {
            echo $stats->getEffectiveUri() . "\n";
            echo $stats->getTransferTime() . "\n";
            var_dump($stats->getHandlerStats());

            // You must check if a response was received before using the
            // response object.
            if ($stats->hasResponse()) {
                echo $stats->getResponse()->getStatusCode();
            } else {
                // Error data is handler specific. You will need to know what
                // type of error data your handler uses before using this
                // value.
                var_dump($stats->getHandlerErrorData());
            }
        }
    ]);


progress
--------

:Summary: Defines a function to invoke when transfer progress is made.
:Types: - callable
:Default: None
:Constant: ``GuzzleHttp\RequestOptions::PROGRESS``

The function accepts the following positional arguments:

- the total number of bytes expected to be downloaded, zero if unknown
- the number of bytes downloaded so far
- the total number of bytes expected to be uploaded
- the number of bytes uploaded so far

.. code-block:: php

    // Send a GET request to /get?foo=bar
    $result = $client->request(
        'GET',
        '/',
        [
            'progress' => function(
                $downloadTotal,
                $downloadedBytes,
                $uploadTotal,
                $uploadedBytes
            ) {
                //do something
            },
        ]
    );


.. _proxy-option:

proxy
-----

:Summary: Pass a string to specify an HTTP proxy, or an array to specify
    different proxies for different protocols.
:Types:
    - string
    - array
:Default: None
:Constant: ``GuzzleHttp\RequestOptions::PROXY``

Pass a string to specify a proxy for all protocols.

.. code-block:: php

    $client->request('GET', '/', ['proxy' => 'http://localhost:8125']);

Pass an associative array to specify HTTP proxies for specific URI schemes
(i.e., "http", "https"). Provide a ``no`` key value pair to provide a list of
host names that should not be proxied to.

.. note::

    Guzzle will automatically populate this value with your environment's
    ``NO_PROXY`` environment variable. However, when providing a ``proxy``
    request option, it is up to you to provide the ``no`` value parsed from
    the ``NO_PROXY`` environment variable
    (e.g., ``explode(',', getenv('NO_PROXY'))``).

.. code-block:: php

    $client->request('GET', '/', [
        'proxy' => [
            'http'  => 'http://localhost:8125', // Use this proxy with "http"
            'https' => 'http://localhost:9124', // Use this proxy with "https",
            'no' => ['.mit.edu', 'foo.com']    // Don't use a proxy with these
        ]
    ]);

.. note::

    You can provide proxy URLs that contain a scheme, username, and password.
    For example, ``"http://username:password@192.168.16.1:10"``.


query
-----

:Summary: Associative array of query string values or query string to add to
    the request.
:Types:
    - array
    - string
:Default: None
:Constant: ``GuzzleHttp\RequestOptions::QUERY``

.. code-block:: php

    // Send a GET request to /get?foo=bar
    $client->request('GET', '/get', ['query' => ['foo' => 'bar']]);

Query strings specified in the ``query`` option will overwrite all query string
values supplied in the URI of a request.

.. code-block:: php

    // Send a GET request to /get?foo=bar
    $client->request('GET', '/get?abc=123', ['query' => ['foo' => 'bar']]);

read_timeout
------------

:Summary: Float describing the timeout to use when reading a streamed body
:Types: float
:Default: Defaults to the value of the ``default_socket_timeout`` PHP ini setting
:Constant: ``GuzzleHttp\RequestOptions::READ_TIMEOUT``

The timeout applies to individual read operations on a streamed body (when the ``stream`` option is enabled).

.. code-block:: php

    $response = $client->request('GET', '/stream', [
        'stream' => true,
        'read_timeout' => 10,
    ]);

    $body = $response->getBody();

    // Returns false on timeout
    $data = $body->read(1024);

    // Returns false on timeout
    $line = fgets($body->detach());

.. _sink-option:

sink
----

:Summary: Specify where the body of a response will be saved.
:Types:
    - string (path to file on disk)
    - ``fopen()`` resource
    - ``Psr\Http\Message\StreamInterface``

:Default: PHP temp stream
:Constant: ``GuzzleHttp\RequestOptions::SINK``

Pass a string to specify the path to a file that will store the contents of the
response body:

.. code-block:: php

    $client->request('GET', '/stream/20', ['sink' => '/path/to/file']);

Pass a resource returned from ``fopen()`` to write the response to a PHP stream:

.. code-block:: php

    $resource = \GuzzleHttp\Psr7\Utils::tryFopen('/path/to/file', 'w');
    $client->request('GET', '/stream/20', ['sink' => $resource]);

Pass a ``Psr\Http\Message\StreamInterface`` object to stream the response
body to an open PSR-7 stream.

.. code-block:: php

    $resource = \GuzzleHttp\Psr7\Utils::tryFopen('/path/to/file', 'w');
    $stream = \GuzzleHttp\Psr7\Utils::streamFor($resource);
    $client->request('GET', '/stream/20', ['save_to' => $stream]);

.. note::

    The ``save_to`` request option has been deprecated in favor of the
    ``sink`` request option. Providing the ``save_to`` option is now an alias
    of ``sink``.


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
:Constant: ``GuzzleHttp\RequestOptions::SSL_KEY``

.. note::

    ``ssl_key`` is implemented by HTTP handlers. This is currently only
    supported by the cURL handler, but might be supported by other third-part
    handlers.


.. _ssl_key_blob-option:

ssl_key_blob
-------

:Summary: Specify a string containing a private SSL key in PEM format.
        If a password is required, then set to an array containing the SSL key
        in the first array element followed by the password required for the
        certificate in the second element.
:Types:
        - string
        - array
:Default: None
:Constant: ``GuzzleHttp\RequestOptions::SSL_KEY_BLOB``

.. note::

    ``ssl_key_blob`` is implemented by HTTP handlers. This is currently only
    supported by the cURL handler, but might be supported by other third-part
    handlers.
    The option is available in PHP >= 8.1


.. _stream-option:

stream
------

:Summary: Set to ``true`` to stream a response rather than download it all
    up-front.
:Types: bool
:Default: ``false``
:Constant: ``GuzzleHttp\RequestOptions::STREAM``

.. code-block:: php

    $response = $client->request('GET', '/stream/20', ['stream' => true]);
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


synchronous
-----------

:Summary: Set to true to inform HTTP handlers that you intend on waiting on the
    response. This can be useful for optimizations.
:Types: bool
:Default: none
:Constant: ``GuzzleHttp\RequestOptions::SYNCHRONOUS``


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
:Constant: ``GuzzleHttp\RequestOptions::VERIFY``

.. code-block:: php

    // Use the system's CA bundle (this is the default setting)
    $client->request('GET', '/', ['verify' => true]);

    // Use a custom SSL certificate on disk.
    $client->request('GET', '/', ['verify' => '/path/to/cert.pem']);

    // Disable validation entirely (don't do this!).
    $client->request('GET', '/', ['verify' => false]);

If you do not need a specific certificate bundle, then Mozilla provides a
commonly used CA bundle which can be downloaded
`here <https://curl.haxx.se/ca/cacert.pem>`_
(provided by the maintainer of cURL). Once you have a CA bundle available on
disk, you can set the "openssl.cafile" PHP ini setting to point to the path to
the file, allowing you to omit the "verify" request option. Much more detail on
SSL certificates can be found on the
`cURL website <http://curl.haxx.se/docs/sslcerts.html>`_.


.. _verify_blob-option:

verify_blob
------

:Summary: Specify the CA bundle to use for SSL certificate verification. When this
        option is used certificate verification is enforced.
:Types: string
:Constant: ``GuzzleHttp\RequestOptions::VERIFY_BLOB``

.. code-block:: php

    $client->request('GET', '/', ['verify_blob' => 'certificates']);

.. note::

    ``verify_blob`` is implemented by HTTP handlers. This is currently only
    supported by the cURL handler, but might be supported by other third-part
    handlers.
    The option is available in PHP >= 8.2


.. _timeout-option:

timeout
-------

:Summary: Float describing the total timeout of the request in seconds. Use ``0``
        to wait indefinitely (the default behavior).
:Types: float
:Default: ``0``
:Constant: ``GuzzleHttp\RequestOptions::TIMEOUT``

.. code-block:: php

    // Timeout if a server does not return a response in 3.14 seconds.
    $client->request('GET', '/delay/5', ['timeout' => 3.14]);
    // PHP Fatal error:  Uncaught exception 'GuzzleHttp\Exception\TransferException'


.. _version-option:

version
-------

:Summary: Protocol version to use with the request.
:Types: string, float
:Default: ``1.1``
:Constant: ``GuzzleHttp\RequestOptions::VERSION``

.. code-block:: php

    // Force HTTP/1.0
    $request = $client->request('GET', '/get', ['version' => 1.0]);
