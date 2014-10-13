=============================
Request and Response Messages
=============================

Guzzle is an HTTP client that sends HTTP requests to a server and receives HTTP
responses. Both requests and responses are referred to as messages.

Headers
=======

Both request and response messages contain HTTP headers.

Complex Headers
---------------

Some headers contain additional key value pair information. For example, Link
headers contain a link and several key value pairs:

::

    <http://foo.com>; rel="thing"; type="image/jpeg"

Guzzle provides a convenience feature that can be used to parse these types of
headers:

.. code-block:: php

    use GuzzleHttp\Message\Request;

    $request = new Request('GET', '/', [
        'Link' => '<http:/.../front.jpeg>; rel="front"; type="image/jpeg"'
    ]);

    $parsed = Request::parseHeader($request, 'Link');
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

See :ref:`headers` for information on how the headers of a request and response
can be accessed and modified.

Body
====

Both request and response messages can contain a body.

You can check to see if a request or response has a body using the
``getBody()`` method:

.. code-block:: php

    $response = GuzzleHttp\get('http://httpbin.org/get');
    if ($response->getBody()) {
        echo $response->getBody();
        // JSON string: { ... }
    }

The body used in request and response objects is a
``GuzzleHttp\Stream\StreamInterface``. This stream is used for both uploading
data and downloading data. Guzzle will, by default, store the body of a message
in a stream that uses PHP temp streams. When the size of the body exceeds
2 MB, the stream will automatically switch to storing data on disk rather than
in memory (protecting your application from memory exhaustion).

You can change the body used in a request or response using the ``setBody()``
method:

.. code-block:: php

    use GuzzleHttp\Stream\Stream;
    $request = $client->createRequest('PUT', 'http://httpbin.org/put');
    $request->setBody(Stream::factory('foo'));

The easiest way to create a body for a request is using the static
``GuzzleHttp\Stream\Stream::factory()`` method. This method accepts various
inputs like strings, resources returned from ``fopen()``, and other
``GuzzleHttp\Stream\StreamInterface`` objects.

The body of a request or response can be cast to a string or you can read and
write bytes off of the stream as needed.

.. code-block:: php

    use GuzzleHttp\Stream\Stream;
    $request = $client->createRequest('PUT', 'http://httpbin.org/put', ['body' => 'testing...']);

    echo $request->getBody()->read(4);
    // test
    echo $request->getBody()->read(4);
    // ing.
    echo $request->getBody()->read(1024);
    // ..
    var_export($request->eof());
    // true

You can find out more about Guzzle stream objects in :doc:`streams`.

Requests
========

Requests are sent from a client to a server. Requests include the method to
be applied to a resource, the identifier of the resource, and the protocol
version to use.

Clients are used to create request messages. More precisely, clients use
a ``GuzzleHttp\Message\MessageFactoryInterface`` to create request messages.
You create requests with a client using the ``createRequest()`` method.

.. code-block:: php

    // Create a request but don't send it immediately
    $request = $client->createRequest('GET', 'http://httpbin.org/get');

Request Methods
---------------

When creating a request, you are expected to provide the HTTP method you wish
to perform. You can specify any method you'd like, including a custom method
that might not be part of RFC 7231 (like "MOVE").

.. code-block:: php

    // Create a request using a completely custom HTTP method
    $request = $client->createRequest('MOVE', 'http://httpbin.org/move', ['exceptions' => false]);

    echo $request->getMethod();
    // MOVE

    $response = $client->send($request);
    echo $response->getStatusCode();
    // 405

You can create and send a request using methods on a client that map to the
HTTP method you wish to use.

:GET: ``$client->get('http://httpbin.org/get', [/** options **/])``
:POST: ``$client->post('http://httpbin.org/post', [/** options **/])``
:HEAD: ``$client->head('http://httpbin.org/get', [/** options **/])``
:PUT: ``$client->put('http://httpbin.org/put', [/** options **/])``
:DELETE: ``$client->delete('http://httpbin.org/delete', [/** options **/])``
:OPTIONS: ``$client->options('http://httpbin.org/get', [/** options **/])``
:PATCH: ``$client->patch('http://httpbin.org/put', [/** options **/])``

.. code-block:: php

    $response = $client->patch('http://httpbin.org/patch', ['body' => 'content']);

Request URI
-----------

The resource you are requesting with an HTTP request is identified by the
path of the request, the query string, and the "Host" header of the request.

When creating a request, you can provide the entire resource URI as a URL.

.. code-block:: php

    $response = $client->get('http://httbin.org/get?q=foo');

Using the above code, you will send a request that uses ``httpbin.org`` as
the Host header, sends the request over port 80, uses ``/get`` as the path,
and sends ``?q=foo`` as the query string. All of this is parsed automatically
from the provided URI.

Sometimes you don't know what the entire request will be when it is created.
In these cases, you can modify the request as needed before sending it using
the ``createRequest()`` method of the client and methods on the request that
allow you to change it.

.. code-block:: php

    $request = $client->createRequest('GET', 'http://httbin.org');

You can change the path of the request using ``setPath()``:

.. code-block:: php

    $request->setPath('/get');
    echo $request->getPath();
    // /get
    echo $request->getUrl();
    // http://httpbin.com/get

Scheme
~~~~~~

The `scheme <http://tools.ietf.org/html/rfc3986#section-3.1>`_ of a request
specifies the protocol to use when sending the request. When using Guzzle, the
scheme can be set to "http" or "https".

You can change the scheme of the request using the ``setScheme()`` method:

.. code-block:: php

    $request = $client->createRequest('GET', 'http://httbin.org');
    $request->setScheme('https');
    echo $request->getScheme();
    // https
    echo $request->getUrl();
    // https://httpbin.com/get

Port
~~~~

No port is necessary when using the "http" or "https" schemes, but you can
override the port using ``setPort()``. If you need to modify the port used with
the specified scheme from the default setting, then you must use the
``setPort()`` method.

.. code-block:: php

    $request = $client->createRequest('GET', 'http://httbin.org');
    $request->setPort(8080);
    echo $request->getPort();
    // 8080
    echo $request->getUrl();
    // https://httpbin.com:8080/get

    // Set the port back to the default value for the scheme
    $request->setPort(443);
    echo $request->getUrl();
    // https://httpbin.com/get

Query string
~~~~~~~~~~~~

You can get the query string of the request using the ``getQuery()`` method.
This method returns a ``GuzzleHttp\Query`` object. A Query object can be
accessed like a PHP array, iterated in a foreach statement like a PHP array,
and cast to a string.

.. code-block:: php

    $request = $client->createRequest('GET', 'http://httbin.org');
    $query = $request->getQuery();
    $query['foo'] = 'bar';
    $query['baz'] = 'bam';
    $query['bam'] = ['test' => 'abc'];

    echo $request->getQuery();
    // foo=bar&baz=bam&bam%5Btest%5D=abc

    echo $request->getQuery()['foo'];
    // bar
    echo $request->getQuery()->get('foo');
    // bar
    echo $request->getQuery()->get('foo');
    // bar

    var_export($request->getQuery()['bam']);
    // array('test' => 'abc')

    foreach ($query as $key => $value) {
        var_export($value);
    }

    echo $request->getUrl();
    // https://httpbin.com/get?foo=bar&baz=bam&bam%5Btest%5D=abc

Query Aggregators
^^^^^^^^^^^^^^^^^

Query objects can store scalar values or arrays of values. When an array of
values is added to a query object, the query object uses a query aggregator to
convert the complex structure into a string. Query objects will use
`PHP style query strings <http://www.php.net/http_build_query>`_ when complex
query string parameters are converted to a string. You can customize how
complex query string parameters are aggregated using the ``setAggregator()``
method of a query string object.

.. code-block:: php

    $query->setAggregator($query::duplicateAggregator());

In the above example, we've changed the query object to use the
"duplicateAggregator". This aggregator will allow duplicate entries to appear
in a query string rather than appending "[n]" to each value. So if you had a
query string with ``['a' => ['b', 'c']]``, the duplicate aggregator would
convert this to "a=b&a=c" while the default aggregator would convert this to
"a[0]=b&a[1]=c" (with urlencoded brackets).

The ``setAggregator()`` method accepts a ``callable`` which is used to convert
a deeply nested array of query string variables into a flattened array of key
value pairs. The callable accepts an array of query data and returns a
flattened array of key value pairs where each value is an array of strings.
You can use the ``GuzzleHttp\Query::walkQuery()`` static function to easily
create custom query aggregators.

Host
~~~~

You can change the host header of the request in a predictable way using the
``setHost()`` method of a request:

.. code-block:: php

    $request->setHost('www.google.com');
    echo $request->getHost();
    // www.google.com
    echo $request->getUrl();
    // https://www.google.com/get?foo=bar&baz=bam

.. note::

    The Host header can also be changed by modifying the Host header of a
    request directly, but modifying the Host header directly could result in
    sending a request to a different Host than what is specified in the Host
    header (sometimes this is actually the desired behavior).

Resource
~~~~~~~~

You can use the ``getResource()`` method of a request to return the path and
query string of a request in a single string.

.. code-block:: php

    $request = $client->createRequest('GET', 'http://httpbin.org/get?baz=bar');
    echo $request->getResource();
    // /get?baz=bar

Request Config
--------------

Request messages contain a configuration collection that can be used by
event listeners and HTTP handlers to modify how a request behaves or is
transferred over the wire. For example, many of the request options that are
specified when creating a request are actually set as config options that are
only acted upon by handlers and listeners when the request is sent.

You can get access to the request's config object using the ``getConfig()``
method of a request.

.. code-block:: php

    $request = $client->createRequest('GET', '/');
    $config = $request->getConfig();

The config object is a ``GuzzleHttp\Common\Collection`` object that acts like
an associative array. You can grab values from the collection using array like
access. You can also modify and remove values using array like access.

.. code-block:: php

    $config['foo'] = 'bar';
    echo $config['foo'];
    // bar

    var_export(isset($config['foo']));
    // true

    unset($config['foo']);
    var_export(isset($config['foo']));
    // false

    var_export($config['foo']);
    // NULL

HTTP handlers and event listeners can expose additional customization options
through request config settings. For example, in order to specify custom cURL
options to the cURL handler, you need to specify an associative array in the
``curl`` ``config`` request option.

.. code-block:: php

    $client->get('/', [
        'config' => [
            'curl' => [
                CURLOPT_HTTPAUTH => CURLAUTH_NTLM,
                CURLOPT_USERPWD  => 'username:password'
            ]
        ]
    ]);

Consult the HTTP handlers and event listeners you are using to see if they
allow customization through request configuration options.

Event Emitter
-------------

Request objects implement ``GuzzleHttp\Common\HasEmitterInterface``, so they
have a method called ``getEmitter()`` that can be used to get an event emitter
used by the request. Any listener or subscriber attached to a request will only
be triggered for the lifecycle events of a specific request. Conversely, adding
an event listener or subscriber to a client will listen to all lifecycle events
of all requests created by the client.

See :doc:`events` for more information.

Responses
=========

Responses are the HTTP messages a client receives from a server after sending
an HTTP request message.

Start-Line
----------

The start-line of a response contains the protocol and protocol version,
status code, and reason phrase.

.. code-block:: php

    $response = GuzzleHttp\get('http://httpbin.org/get');
    echo $response->getStatusCode();
    // 200
    echo $response->getReasonPhrase();
    // OK
    echo $response->getProtocolVersion();
    // 1.1

Body
----

As described earlier, you can get the body of a response using the
``getBody()`` method.

.. code-block:: php

    if ($body = $response->getBody()) {
        echo $body;
        // Cast to a string: { ... }
        $body->seek(0);
        // Rewind the body
        $body->read(1024);
        // Read bytes of the body
    }

When working with JSON responses, you can use the ``json()`` method of a
response:

.. code-block:: php

    $json = $response->json();

.. note::

    Guzzle uses the ``json_decode()`` method of PHP and uses arrays rather than
    ``stdClass`` objects for objects.

You can use the ``xml()`` method when working with XML data.

.. code-block:: php

    $xml = $response->xml();

.. note::

    Guzzle uses the ``SimpleXMLElement`` objects when converting response
    bodies to XML.

Effective URL
-------------

The URL that was ultimately accessed that returned a response can be accessed
using the ``getEffectiveUrl()`` method of a response. This method will return
the URL of a request or the URL of the last redirected URL if any redirects
occurred while transferring a request.

.. code-block:: php

    $response = GuzzleHttp\get('http://httpbin.org/get');
    echo $response->getEffectiveUrl();
    // http://httpbin.org/get

    $response = GuzzleHttp\get('http://httpbin.org/redirect-to?url=http://www.google.com');
    echo $response->getEffectiveUrl();
    // http://www.google.com
