PHP framework for HTTP and building RESTful webservice clients
==============================================================

Guzzle is a PHP 5.3+ HTTP client and framework for building web service clients.

Features
--------

* Supports GET, HEAD, POST, DELETE, PUT, and OPTIONS
* Allows full access to request and response headers
* Persistent connections are implicitly managed by Guzzle, resulting in huge performance benefits
* Send requests in parallel
* Cookie sessions can be maintained between requests using the CookiePlugin
* Allows custom entity bodies to be sent in PUT and POST requests, including sending data from a PHP stream
* Responses can be cached and served from cache using the caching reverse proxy plugin
* Failed requests can be retried using truncated exponential backoff
* Entity bodies can be validated automatically using Content-MD5 headers
* All data sent over the wire can be logged using the LogPlugin
* Automatically requests compressed data and automatically decompresses data
* Subject/Observer signal slot system for unobtrusively modifying request behavior
* Supports all of the features of libcurl including authentication, redirects, SSL, proxies, etc
* Web service client framework for building future-proof interfaces to web services

Code samples::
--------------

    <?php

    use Guzzle\Http\Message\RequestFactory;

    $request = RequestFactory::get('http://www.example.com/');
    $response = $request->send();

    $response = RequestFactory::head('http://www.example.com/')->send();
    $response = RequestFactory::delete('http://www.example.com/')->send();

    // Send a PUT request with custom headers
    $response = RequestFactory::put('http://www.example.com/upload', array(
        'X-Header' => 'My Header'
    ), 'body of the request')->send();

    // Send a PUT request using the contents of a PHP stream as the body
    $response = RequestFactory::put('http://www.example.com/upload', array(
        'X-Header' => 'My Header'
    ), fopen('http://www.test.com/', 'r'));

    // Create a POST request with a file upload (notice the @ symbol):
    $request = RequestFactory::post('http://localhost:8983/solr/update', null, array (
        'custom_field' => 'my value',
        'file' => '@/path/to/documents.xml'
    ));

    // Create a POST request and add the POST files manually
    $request = RequestFactory::post('http://localhost:8983/solr/update')
        ->addPostFiles(array(
            'file' => '/path/to/documents.xml'
        ));

    // Responses are objects
    echo $response->getStatusCode() . ' ' . $response->getReasonPhrase() . "\n";

    // Requests and responses can be cast to a string to show the raw HTTP message
    echo $request . "\n\n" . $response;

    // Create the request objects manually
    $getRequest = new Guzzle\Http\Message\Request('GET', 'http://www.example.com/');
    $putRequest = new Guzzle\Http\Message\EntityEnclosingRequest('PUT', 'http://www.example.com/');

    // Create a request based on an HTTP message
    $request = RequestFactory::fromMessage(
        "PUT / HTTP/1.1\r\n" .
        "Host: test.com:8081\r\n" .
        "Content-Type: text/plain"
        "Transfer-Encoding: chunked\r\n" .
        "\r\n" .
        "this is the body"
    );

Send requests in parallel::

    <?php
    use Guzzle\Http\Pool\Pool;
    use Guzzle\Http\Pool\PoolRequestException;

    $pool = new Pool();
    $pool->add(RequestFactory::get('http://www.google.com/'));
    $pool->add(RequestFactory::head('http://www.google.com/'));
    $pool->add(RequestFactory::get('https://www.github.com/'));

    try {
        $pool->send();
    } catch (PoolRequestException $e) {
        echo "The following requests encountered an exception: \n";
        foreach ($e as $exception) {
            echo $exception->getRequest() . "\n" . $exception->getMessage() . "\n";
        }
    }

Documentation
-------------

Read the full documentation at `www.guzzlephp.org <http://www.guzzlephp.org>`_