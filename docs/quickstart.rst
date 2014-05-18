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

    $response = GuzzleHttp\post('http://httpbin.org/post', [
        'headers' => ['X-Foo' => 'Bar'],
        'body'    => ['field_name' => 'value']
    ]);

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

The procedural API is simple but not very testable; it's best left for quick
prototyping. If you want to use Guzzle in a more flexible and testable way,
then you'll need to use a ``GuzzleHttp\ClientInterface`` object.

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

In the previous examples, we retrieved a ``$response`` variable. This value is
actually a ``GuzzleHttp\Message\ResponseInterface`` object and contains lots
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

You can more easily work with JSON responses using the ``json()`` method of a
response.

.. code-block:: php

    $response = $client->get('https://github.com/timeline.json');
    $json = $response->json();
    var_dump($json[0]['repository']);

Guzzle internally uses PHP's ``json_decode()`` function to parse responses. If
Guzzle is unable to parse the JSON response body, then a
``GuzzleHttp\Exception\ParseException`` is thrown.

XML Responses
~~~~~~~~~~~~~

You can use a response's ``xml()`` method to more easily work with responses
that contain XML data.

.. code-block:: php

    $response = $client->get('https://github.com/mtdowling.atom');
    $xml = $response->xml();
    echo $xml->id;
    // tag:github.com,2008:/mtdowling

Guzzle internally uses a ``SimpleXMLElement`` object to parse responses. If
Guzzle is unable to parse the XML response body, then a
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

    $client->get('http://httpbin.org', [
        'query' => ['foo' => 'bar']
    ]);

And finally, you can build up the query string of a request as needed by
calling the ``getQuery()`` method of a request and modifying the request's
``GuzzleHttp\Query`` object as needed.

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

.. _headers:

Request and Response Headers
----------------------------

You can specify request headers when sending or creating requests with a
client. In the following example, we send the ``X-Foo-Header`` with a value of
``value`` by setting the ``headers`` request option.

.. code-block:: php

    $response = $client->get('http://httpbin.org/get', [
        'headers' => ['X-Foo-Header' => 'value']
    ]);

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

Uploading Data
==============

Guzzle provides several methods of uploading data.

You can send requests that contain a stream of data by passing a string,
resource returned from ``fopen``, or a ``GuzzleHttp\Stream\StreamInterface``
object to the ``body`` request option.

.. code-block:: php

    $r = $client->post('http://httpbin.org/post', ['body' => 'raw data']);

You can easily upload JSON data using the ``json`` request option.

.. code-block:: php

    $r = $client->put('http://httpbin.org/put', ['json' => ['foo' => 'bar']]);

POST Requests
-------------

In addition to specifying the raw data of a request using the ``body`` request
option, Guzzle provides helpful abstractions over sending POST data.

Sending POST Fields
~~~~~~~~~~~~~~~~~~~

Sending ``application/x-www-form-urlencoded`` POST requests requires that you
specify the body of a POST request as an array.

.. code-block:: php

    $response = $client->post('http://httpbin.org/post', [
        'body' => [
            'field_name' => 'abc',
            'other_field' => '123'
        ]
    ]);

You can also build up POST requests before sending them.

.. code-block:: php

    $request = $client->createRequest('POST', 'http://httpbin.org/post');
    $postBody = $request->getBody();

    // $postBody is an instance of GuzzleHttp\Post\PostBodyInterface
    $postBody->setField('foo', 'bar');
    echo $postBody->getField('foo');
    // 'bar'

    echo json_encode($postBody->getFields());
    // {"foo": "bar"}

    // Send the POST request
    $response = $client->send($request);

Sending POST Files
~~~~~~~~~~~~~~~~~~

Sending ``multipart/form-data`` POST requests (POST requests that contain
files) is the same as sending ``application/x-www-form-urlencoded``, except
some of the array values of the POST fields map to PHP ``fopen`` resources, or
``GuzzleHttp\Stream\StreamInterface``, or
``GuzzleHttp\Post\PostFileInterface`` objects.

.. code-block:: php

    use GuzzleHttp\Post\PostFile;

    $response = $client->post('http://httpbin.org/post', [
        'body' => [
            'field_name' => 'abc',
            'file_filed' => fopen('/path/to/file', 'r'),
            'other_file' => new PostFile('other_file', 'this is the content')
        ]
    ]);

Just like when sending POST fields, you can also build up POST requests with
files before sending them.

.. code-block:: php

    use GuzzleHttp\Post\PostFile;

    $request = $client->createRequest('POST', 'http://httpbin.org/post');
    $postBody = $request->getBody();
    $postBody->setField('foo', 'bar');
    $postBody->addFile(new PostFile('test', fopen('/path/to/file', 'r')));
    $response = $client->send($request);

Cookies
=======

Guzzle can maintain a cookie session for you if instructed using the
``cookies`` request option.

- Set to ``true`` to use a shared cookie session associated with the client.
- Pass an associative array containing cookies to send in the request and start
  a new cookie session.
- Set to a ``GuzzleHttp\Subscriber\CookieJar\CookieJarInterface`` object to uss
  an existing cookie jar.

Redirects
=========

Guzzle will automatically follow redirects unless you tell it not to. You can
customize the redirect behavior using the ``allow_redirects`` request option.

- Set to true to enable normal redirects with a maximum number of 5 redirects.
  This is the default setting.
- Set to false to disable redirects.
- Pass an associative array containing the 'max' key to specify the maximum
  number of redirects and optionally provide a 'strict' key value to specify
  whether or not to use strict RFC compliant redirects (meaning redirect POST
  requests with POST requests vs. doing what most browsers do which is
  redirect POST requests with GET requests).

.. code-block:: php

    $response = $client->get('http://github.com');
    echo $response->getStatusCode();
    // 200
    echo $response->getEffectiveUrl();
    // 'https://github.com/'

The following example shows that redirects can be disabled.

.. code-block:: php

    $response = $client->get('http://github.com', ['allow_redirects' => false]);
    echo $response->getStatusCode();
    // 301
    echo $response->getEffectiveUrl();
    // 'http://github.com/'

Exceptions
==========

Guzzle throws exceptions for errors that occur during a transfer.

- In the event of a networking error (connection timeout, DNS errors, etc.),
  a ``GuzzleHttp\Exception\RequestException`` is thrown. This exception
  extends from ``GuzzleHttp\Exception\TransferException``. Catching this
  exception will catch any exception that can be thrown while transferring
  (non-parallel) requests.

  .. code-block:: php

      use GuzzleHttp\Exception\RequestException;

      try {
          $client->get('https://github.com/_abc_123_404');
      } catch (RequestException $e) {
          echo $e->getRequest();
          if ($e->hasResponse()) {
              echo $e->getResponse();
          }
      }

- A ``GuzzleHttp\Exception\ClientErrorResponseException`` is thrown for 400
  level errors if the ``exceptions`` request option is set to true. This
  exception extends from ``GuzzleHttp\Exception\BadResponseException`` and
  ``GuzzleHttp\Exception\BadResponseException`` extends from
  ``GuzzleHttp\Exception\RequestException``.

  .. code-block:: php

      use GuzzleHttp\Exception\ClientErrorResponseException;

      try {
          $client->get('https://github.com/_abc_123_404');
      } catch (ClientErrorResponseException $e) {
          echo $e->getRequest();
          echo $e->getResponse();
      }

- A ``GuzzleHttp\Exception\ServerErrorResponse`` is thrown for 500 level
  errors if the ``exceptions`` request option is set to true. This
  exception extends from ``GuzzleHttp\Exception\BadResponseException``.
- A ``GuzzleHttp\Exception\TooManyRedirectsException`` is thrown when too
  many redirects are followed. This exception extends from ``GuzzleHttp\Exception\RequestException``.
- A ``GuzzleHttp\Exception\AdapterException`` is thrown when an error occurs
  in an HTTP adapter during a parallel request. This exception is only thrown
  when using the ``sendAll()`` method of a client.

All of the above exceptions extend from
``GuzzleHttp\Exception\TransferException``.
