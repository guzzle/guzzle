==========
Quickstart
==========

This page provides a quick introduction to Guzzle and introductory examples.
If you have not already installed, Guzzle, head over to the :ref:`installation`
page.

Make a Request
==============

You can send requests with Guzzle in one of two ways: through the procedural
API or using a ``GuzzleHttp\ClientInterface`` object. Using the procedural API
is an easy way to send a quick HTTP request. Using a Client object provides
much more flexibility in how requests are transferred and allows you to more
easily test the client.

Procedural API
--------------

Here's an example of sending a ``GET`` request using the procedural API.

.. code-block:: php

    $response = GuzzleHttp\get('https://github.com/timeline.json');

You can send all kinds of HTTP requests with the procedural API. Just call
the function that maps to the HTTP method name.

.. code-block:: php

    $response = GuzzleHttp\head('http://httpbin.org/get');
    $response = GuzzleHttp\post('http://httpbin.org/post');
    $response = GuzzleHttp\put('http://httpbin.org/put');
    $response = GuzzleHttp\delete('http://httpbin.org/delete');
    $response = GuzzleHttp\options('http://httpbin.org/get');

Creating a Client
-----------------

It's pretty simple, but it's not very testable. This procedural API is best
left for quick prototyping. If you want to use Guzzle in a more flexible and
testable way, then you'll need to use a ``GuzzleHttp\ClientInterface`` object.

.. code-block:: php

    use GuzzleHttp\Client;

    $client = new Client();
    $response = $client->get('https://github.com/timeline.json');

    // You can use the same methods you saw in the procedural API
    $response = $client->delete('http://httpbin.org/delete');
    $response = $client->head('http://httpbin.org/get');
    $response = $client->options('http://httpbin.org/get');
    $response = $client->patch('http://httpbin.org/patch');
    $response = $client->post('http://httpbin.org/post');
    $response = $client->put('http://httpbin.org/put');

You can create a request with a client and then send the request with the
client when you're ready.

.. code-block:: php

    $request = $client->createRequest('GET', 'http://www.foo.com');
    $response = $client->send($request);

Client objects provide a great deal of flexibility in how request are
transferred including default request options, subscribers that are attached
to each request, and a base URL that allows you to send requests with relative
URLs. You can find out all about clients in the :doc:`clients` page of the
documentation.

Using Responses
===============

In the previos examples, we retrieved a ``$response`` variable. This value is
actually a ``Guzzle\Http\Message\ResponseInterface`` object and contains lots
of helpful information.

You can get the status code and reason phrase of the response.

.. code-block:: php

    $code = $response->getStatusCode();
    // 200

    $reason = $response->getReasonPhrase();
    // OK

Response Body
-------------

The body of a response can be retrieved and cast to a string.

.. code-block:: php

    $body = $response->getBody();
    echo $body;
    // { "some_json_data" ...}

You can also read read bytes from body of a response like a stream.

.. code-block:: php

    $body = $response->getBody();

    while (!$body->eof()) {
        echo $body->read(1024);
    }

JSON Responses
~~~~~~~~~~~~~~

There's a built-in JSON parser that can be used when working with JSON data.

.. code-block:: php

    $response = $client->get('https://github.com/timeline.json');
    $json = $response->json();
    var_dump($json[0]['repository']);

If Guzzle is unable to parse the JSON response body, then a
``GuzzleHttp\Exception\ParseException`` is thrown.

XML Responses
~~~~~~~~~~~~~

There's a built-in XML parser that can be used when working with XML data.

.. code-block:: php

    $response = $client->get('https://github.com/mtdowling.atom');
    $xml = $response->xml();
    echo $xml->id;
    // tag:github.com,2008:/mtdowling

If Guzzle is unable to parse the XML response body, then a
``GuzzleHttp\Exception\ParseException`` is thrown.

Query String Parameters
=======================

Sending query string parameters with a request is easy. You can set query
string parameters in the request's URL.

.. code-block:: php

    $response = $client->get('http://httpbin.org?foo=bar');

You can also specify the query string parameters using the ``query`` request
option.

.. code-block:: php

    $client->get('http://httpbin.org', [], [
        'query' => ['foo' => 'bar']
    ]);

And finally, you can build up the query string of a request as needed by
calling the ``getQuery()`` method of a request and modifying the request's
``GuzzleHttp\Url\QueryString`` object as needed.

.. code-block:: php

    $request = $client->createRequest('GET', 'http://httpbin.org');
    $query = $request->getQuery();
    $query->set('foo', 'bar');

    // You can use the query string object like an array
    $query['baz'] = 'bam';

    // The query object can be cast to a string
    echo $query;
    // foo=bar&baz=bam

    // Setting a value to false or null will cause the "=" sign to be omitted
    $query['empty'] = null;
    echo $query;
    // foo=bar&baz=bam&empty

    // Use an empty string to include the "=" sign with an empty value
    $query['empty'] = '';
    echo $query;
    // foo=bar&baz=bam&empty=

Message Headers
---------------

You can view the headers of a response using header specific methods of a
response class. Headers work exactly the same way for request and response
object.

You can retrieve a header from a request or response using the ``getHeader()``
method of the object. This method is case-insensitive and by default will
return a string containing the header field value.

.. code-block:: php

    $response = $client->get('http://www.yahoo.com');
    $length = $response->getHeader('Content-Length');

Header fields that contain multiple values can be retrieved as a string or as
an array. Retrieving the field values as a string will naively concatenate all
of the header values together with a comma. Because not all header fields
should be represented this way (e.g., ``Set-Cookie``), you can pass an optional
flag to the ``getHeader()`` method to retrieve the header values as an array.

.. code-block:: php

    $values = $response->getHeader('Set-Cookie', true);
    foreach ($values as $value) {
        echo $value;
    }

You can test if a request or response has a specific header using the
``hasHeader()`` method. This method accepts a case-insensitive string and
returns true if the header is present or false if it is not.

You can retrieve all of the headers of a message using the ``getHeaders()``
method of a request or response. The return value is an associative array where
the keys represent the header name as it will be sent over the wire, and each
value is an array of strings associated with the header.

.. code-block:: php

    $headers = $response->getHeaders();
    foreach ($message->getHeaders() as $name => $values) {
        echo $name . ": " . implode(", ", $values);
    }

Modifying headers
-----------------

The headers of a message can be modified using the ``setHeader()``,
``addHeader()``, ``setHeaders()``, and ``removeHeader()`` methods of a request
or response object.

.. code-block:: php

    $request = $client->createRequest('GET', 'http://httpbin.org/get');

    // Set a single value for a header
    $request->setHeader('User-Agent', 'Testing!');

    // Set multiple values for a header in one call
    $request->setHeader('X-Foo', ['Baz', 'Bar']);

    // Add a header to the message
    $request->addHeader('X-Foo', 'Bam');

    echo $request->getHeader('X-Foo');
    // Baz, Bar, Bam

    // Remove a specific header using a case-insensitive name
    $request->removeHeader('x-foo');
    echo $request->getHeader('X-Foo');
    // Echoes an empty string: ''

POST Requests
=============



Sending POST fields
-------------------

Sending files
-------------

Cookies
=======

Redirects
=========

Exceptions
==========

