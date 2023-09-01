================
Guzzle and PSR-7
================

Guzzle utilizes PSR-7 as the HTTP message interface. This allows Guzzle to work
with any other library that utilizes PSR-7 message interfaces.

Guzzle is an HTTP client that sends HTTP requests to a server and receives HTTP
responses. Both requests and responses are referred to as messages.

Guzzle relies on the ``guzzlehttp/psr7`` Composer package for its message
implementation of PSR-7.

You can create a request using the ``GuzzleHttp\Psr7\Request`` class:

.. code-block:: php

    use GuzzleHttp\Psr7\Request;

    $request = new Request('GET', 'http://httpbin.org/get');

    // You can provide other optional constructor arguments.
    $headers = ['X-Foo' => 'Bar'];
    $body = 'hello!';
    $request = new Request('PUT', 'http://httpbin.org/put', $headers, $body);

You can create a response using the ``GuzzleHttp\Psr7\Response`` class:

.. code-block:: php

    use GuzzleHttp\Psr7\Response;

    // The constructor requires no arguments.
    $response = new Response();
    echo $response->getStatusCode(); // 200
    echo $response->getProtocolVersion(); // 1.1

    // You can supply any number of optional arguments.
    $status = 200;
    $headers = ['X-Foo' => 'Bar'];
    $body = 'hello!';
    $protocol = '1.1';
    $response = new Response($status, $headers, $body, $protocol);


Headers
=======

Both request and response messages contain HTTP headers.


Accessing Headers
-----------------

You can check if a request or response has a specific header using the
``hasHeader()`` method.

.. code-block:: php

    use GuzzleHttp\Psr7;

    $request = new Psr7\Request('GET', '/', ['X-Foo' => 'bar']);

    if ($request->hasHeader('X-Foo')) {
        echo 'It is there';
    }

You can retrieve all the header values as an array of strings using
``getHeader()``.

.. code-block:: php

    $request->getHeader('X-Foo'); // ['bar']

    // Retrieving a missing header returns an empty array.
    $request->getHeader('X-Bar'); // []

You can iterate over the headers of a message using the ``getHeaders()``
method.

.. code-block:: php

    foreach ($request->getHeaders() as $name => $values) {
        echo $name . ': ' . implode(', ', $values) . "\r\n";
    }


Complex Headers
---------------

Some headers contain additional key value pair information. For example, Link
headers contain a link and several key value pairs:

::

    <http://foo.com>; rel="thing"; type="image/jpeg"

Guzzle provides a convenience feature that can be used to parse these types of
headers:

.. code-block:: php

    use GuzzleHttp\Psr7;

    $request = new Psr7\Request('GET', '/', [
        'Link' => '<http:/.../front.jpeg>; rel="front"; type="image/jpeg"'
    ]);

    $parsed = Psr7\Header::parse($request->getHeader('Link'));
    var_export($parsed);

Will output:

.. code-block:: php

    array (
      0 =>
      array (
        0 => '<http:/.../front.jpeg>',
        'rel' => 'front',
        'type' => 'image/jpeg',
      ),
    )

The result contains a hash of key value pairs. Header values that have no key
(i.e., the link) are indexed numerically while headers parts that form a key
value pair are added as a key value pair.


Body
====

Both request and response messages can contain a body.

You can retrieve the body of a message using the ``getBody()`` method:

.. code-block:: php

    $response = GuzzleHttp\get('http://httpbin.org/get');
    echo $response->getBody();
    // JSON string: { ... }

The body used in request and response objects is a
``Psr\Http\Message\StreamInterface``. This stream is used for both
uploading data and downloading data. Guzzle will, by default, store the body of
a message in a stream that uses PHP temp streams. When the size of the body
exceeds 2 MB, the stream will automatically switch to storing data on disk
rather than in memory (protecting your application from memory exhaustion).

The easiest way to create a body for a message is using the ``streamFor``
method from the ``GuzzleHttp\Psr7\Utils`` class --
``Utils::streamFor``. This method accepts strings, resources,
callables, iterators, other streamables, and returns an instance of
``Psr\Http\Message\StreamInterface``.

The body of a request or response can be cast to a string or you can read and
write bytes off of the stream as needed.

.. code-block:: php

    use GuzzleHttp\Stream\Stream;
    $response = $client->request('GET', 'http://httpbin.org/get');

    echo $response->getBody()->read(4);
    echo $response->getBody()->read(4);
    echo $response->getBody()->read(1024);
    var_export($response->eof());


Requests
========

Requests are sent from a client to a server. Requests include the method to
be applied to a resource, the identifier of the resource, and the protocol
version to use.


Request Methods
---------------

When creating a request, you are expected to provide the HTTP method you wish
to perform. You can specify any method you'd like, including a custom method
that might not be part of RFC 7231 (like "MOVE").

.. code-block:: php

    // Create a request using a completely custom HTTP method
    $request = new \GuzzleHttp\Psr7\Request('MOVE', 'http://httpbin.org/move');

    echo $request->getMethod();
    // MOVE

You can create and send a request using methods on a client that map to the
HTTP method you wish to use.

:GET: ``$client->get('http://httpbin.org/get', [/** options **/])``
:POST: ``$client->post('http://httpbin.org/post', [/** options **/])``
:HEAD: ``$client->head('http://httpbin.org/get', [/** options **/])``
:PUT: ``$client->put('http://httpbin.org/put', [/** options **/])``
:DELETE: ``$client->delete('http://httpbin.org/delete', [/** options **/])``
:OPTIONS: ``$client->options('http://httpbin.org/get', [/** options **/])``
:PATCH: ``$client->patch('http://httpbin.org/put', [/** options **/])``

For example:

.. code-block:: php

    $response = $client->patch('http://httpbin.org/patch', ['body' => 'content']);


Request URI
-----------

The request URI is represented by a ``Psr\Http\Message\UriInterface`` object.
Guzzle provides an implementation of this interface using the
``GuzzleHttp\Psr7\Uri`` class.

When creating a request, you can provide the URI as a string or an instance of
``Psr\Http\Message\UriInterface``.

.. code-block:: php

    $response = $client->request('GET', 'http://httpbin.org/get?q=foo');


Scheme
------

The `scheme <https://datatracker.ietf.org/doc/html/rfc3986#section-3.1>`_ of a
request specifies the protocol to use when sending the request. When using
Guzzle, the scheme can be set to "http" or "https".

.. code-block:: php

    $request = new Request('GET', 'http://httpbin.org');
    echo $request->getUri()->getScheme(); // http
    echo $request->getUri(); // http://httpbin.org


Host
----

The host is accessible using the URI owned by the request or by accessing the
Host header.

.. code-block:: php

    $request = new Request('GET', 'http://httpbin.org');
    echo $request->getUri()->getHost(); // httpbin.org
    echo $request->getHeader('Host'); // httpbin.org


Port
----

No port is necessary when using the "http" or "https" schemes.

.. code-block:: php

    $request = new Request('GET', 'http://httpbin.org:8080');
    echo $request->getUri()->getPort(); // 8080
    echo $request->getUri(); // http://httpbin.org:8080


Path
----

The path of a request is accessible via the URI object.

.. code-block:: php

    $request = new Request('GET', 'http://httpbin.org/get');
    echo $request->getUri()->getPath(); // /get

The contents of the path will be automatically filtered to ensure that only
allowed characters are present in the path. Any characters that are not allowed
in the path will be percent-encoded according to
`RFC 3986 section 3.3 <https://datatracker.ietf.org/doc/html/rfc3986#section-3.3>`_


Query string
------------

The query string of a request can be accessed using the ``getQuery()`` of the
URI object owned by the request.

.. code-block:: php

    $request = new Request('GET', 'http://httpbin.org/?foo=bar');
    echo $request->getUri()->getQuery(); // foo=bar

The contents of the query string will be automatically filtered to ensure that
only allowed characters are present in the query string. Any characters that
are not allowed in the query string will be percent-encoded according to
`RFC 3986 section 3.4 <https://datatracker.ietf.org/doc/html/rfc3986#section-3.4>`_


Responses
=========

Responses are the HTTP messages a client receives from a server after sending
an HTTP request message.


Start-Line
----------

The start-line of a response contains the protocol and protocol version,
status code, and reason phrase.

.. code-block:: php

    $client = new \GuzzleHttp\Client();
    $response = $client->request('GET', 'http://httpbin.org/get');

    echo $response->getStatusCode(); // 200
    echo $response->getReasonPhrase(); // OK
    echo $response->getProtocolVersion(); // 1.1


Body
----

As described earlier, you can get the body of a response using the
``getBody()`` method.

.. code-block:: php

    $body = $response->getBody();
    echo $body;
    // Cast to a string: { ... }
    $body->seek(0);
    // Rewind the body
    $body->read(1024);
    // Read bytes of the body


Streams
=======

Guzzle uses PSR-7 stream objects to represent request and response message
bodies. These stream objects allow you to work with various types of data all
using a common interface.

HTTP messages consist of a start-line, headers, and a body. The body of an HTTP
message can be very small or extremely large. Attempting to represent the body
of a message as a string can easily consume more memory than intended because
the body must be stored completely in memory. Attempting to store the body of a
request or response in memory would preclude the use of that implementation from
being able to work with large message bodies. The StreamInterface is used in
order to hide the implementation details of where a stream of data is read from
or written to.

The PSR-7 ``Psr\Http\Message\StreamInterface`` exposes several methods
that enable streams to be read from, written to, and traversed effectively.

Streams expose their capabilities using three methods: ``isReadable()``,
``isWritable()``, and ``isSeekable()``. These methods can be used by stream
collaborators to determine if a stream is capable of their requirements.

Each stream instance has various capabilities: they can be read-only,
write-only, read-write, allow arbitrary random access (seeking forwards or
backwards to any location), or only allow sequential access (for example in the
case of a socket or pipe).

Guzzle uses the ``guzzlehttp/psr7`` package to provide stream support. More
information on using streams, creating streams, converting streams to PHP
stream resource, and stream decorators can be found in the
`Guzzle PSR-7 documentation <https://github.com/guzzle/psr7/blob/master/README.md>`_.


Creating Streams
----------------

The best way to create a stream is using the ``GuzzleHttp\Psr7\Utils::streamFor``
method. This method accepts strings, resources returned from ``fopen()``,
an object that implements ``__toString()``, iterators, callables, and instances
of ``Psr\Http\Message\StreamInterface``.

.. code-block:: php

    use GuzzleHttp\Psr7;

    $stream = Psr7\Utils::streamFor('string data');
    echo $stream;
    // string data
    echo $stream->read(3);
    // str
    echo $stream->getContents();
    // ing data
    var_export($stream->eof());
    // true
    var_export($stream->tell());
    // 11

You can create streams from iterators. The iterator can yield any number of
bytes per iteration. Any excess bytes returned by the iterator that were not
requested by a stream consumer will be buffered until a subsequent read.

.. code-block:: php

    use GuzzleHttp\Psr7;

    $generator = function ($bytes) {
        for ($i = 0; $i < $bytes; $i++) {
            yield '.';
        }
    };

    $iter = $generator(1024);
    $stream = Psr7\Utils::streamFor($iter);
    echo $stream->read(3); // ...


Metadata
--------

Streams expose stream metadata through the ``getMetadata()`` method. This
method provides the data you would retrieve when calling PHP's
`stream_get_meta_data() function <https://www.php.net/manual/en/function.stream-get-meta-data.php>`_,
and can optionally expose other custom data.

.. code-block:: php

    use GuzzleHttp\Psr7;

    $resource = Psr7\Utils::tryFopen('/path/to/file', 'r');
    $stream = Psr7\Utils::streamFor($resource);
    echo $stream->getMetadata('uri');
    // /path/to/file
    var_export($stream->isReadable());
    // true
    var_export($stream->isWritable());
    // false
    var_export($stream->isSeekable());
    // true


Stream Decorators
-----------------

Adding custom functionality to streams is very simple with stream decorators.
Guzzle provides several built-in decorators that provide additional stream
functionality.

- `AppendStream <https://github.com/guzzle/psr7#appendstream>`_
- `BufferStream <https://github.com/guzzle/psr7#bufferstream>`_
- `CachingStream <https://github.com/guzzle/psr7#cachingstream>`_
- `DroppingStream <https://github.com/guzzle/psr7#droppingstream>`_
- `FnStream <https://github.com/guzzle/psr7#fnstream>`_
- `InflateStream <https://github.com/guzzle/psr7#inflatestream>`_
- `LazyOpenStream <https://github.com/guzzle/psr7#lazyopenstream>`_
- `LimitStream <https://github.com/guzzle/psr7#limitstream>`_
- `MultipartStream <https://github.com/guzzle/psr7#multipartstream>`_
- `NoSeekStream <https://github.com/guzzle/psr7#noseekstream>`_
- `PumpStream <https://github.com/guzzle/psr7#pumpstream>`_
