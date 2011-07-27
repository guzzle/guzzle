Guzzle - a PHP framework for HTTP and building RESTful webservice clients
=========================================================================

Guzzle is an HTTP client for PHP that ships with a RESTful web service client framework that will help you quickly build web service clients::

    <?php
    use Guzzle\Http\Message\RequestFactory;

    // Send a GET request
    $request = RequestFactory::get('http://www.example.com/');
    $response = $request->send();

    // More succinctly, send a HEAD request followed by DELETE request
    $response = RequestFactory::head('http://www.example.com/')->send();
    $response = RequestFactory::delete('http://www.example.com/')->send();

    // Send a PUT request with custom headers
    $response = RequestFactory::put('http://www.example.com/upload', array(
        'X-Header' => 'My Header'
    ), 'body of the request');

    // Send a PUT request using the contents of a PHP stream as the body
    $response = RequestFactory::put('http://www.example.com/upload', array(
        'X-Header' => 'My Header'
    ), fopen('http://www.test.com/', 'r'));

    // Send a POST request with a file upload (notice the @ symbol):
    $request = RequestFactory::post('http://localhost:8983/solr/update', null, array (
        'custom_field' => 'my value',
        'file' => '@/path/to/documents.xml'
    ));
    $response = $request->send();

    // Send a POST request and add the POST files manually
    $request = RequestFactory::post('http://localhost:8983/solr/update')
        ->addPostFiles(array(
            'file' => '/path/to/documents.xml'
        ));
    $response = $request->send();

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

Features
--------

* Supports GET, HEAD, POST, DELETE, and PUT methods
* Persistent connections are implicitly managed by Guzzle, resulting in huge performance benefits
* Allows custom entity bodies to be sent in PUT and POST requests, including sending data from a PHP stream
* Allows full access to request HTTP headers
* Responses can be cached and served from cache using the CachePlugin
* Failed requests can be retried using truncated exponential backoff using the ExponentialBackoffPlugin
* Entity bodies can be validated automatically using Content-MD5 headers
* All data sent over the wire can be logged using the LogPlugin
* Cookie sessions can be maintained between requests using the CookiePlugin
* Send requests in parallel
* Supports HTTPS and SSL certificate validation
* Requests can be sent through a proxy
* Automatically requests compressed data and automatically decompresses data
* Supports authentication methods provided by cURL (Basic, Digest, GSS Negotiate, NTLM)
* Transparently follows redirects
* Subject/Observer signal slot system for modifying request behavior
* Request signal slot events for before/progress/complete/failure/etc...

Guzzle makes writing services an easy task by providing a simple pattern to follow:

#. Extend the default client class
#. Create commands for each API action or generate commands dynamically using an XML service description.
#. Add the service definition to your services.xml file

Most web service clients follow a specific pattern: create a client class, create methods for each action that can be taken on the API, create a cURL handle to transfer an HTTP request to the client, parse the response, implement error handling, and return the result. You've probably had to interact with an API that either doesn't have a PHP client or the currently available PHP clients are not up to an acceptable level of quality. When facing these types of situations, you probably find yourself writing a webservice that lacks most of the advanced features mentioned by Michael. It wouldn't make sense to spend all that time writing those features-- it's just a simple webservice client for just one API... But then you build another client... and another. Suddenly you find yourself with several web service clients to maintain, each client a God class, each reeking of code duplication and lacking most, if not all, of the aforementioned features. Enter Guzzle.

Guzzle is used in production at `SHOEBACCA.com <http://www.shoebacca.com/>`_, a mutli-million dollar e-commerce company.  Guzzle has 100% code coverage; every line of Guzzle has been tested using PHPUnit.

Creating a simple web service client
------------------------------------

The Guzzle ``Guzzle\Service\Client`` object can be used directly with a simple web service.  Robust web service clients should interact with a web service using command objects, but if you want to quickly interact with a web service, you can create a client and build your HTTP requests manually.  When creating a simple client, pass the base URL of the web service to the client's constructor.  In the following example, we are interacting with the Unfuddle API and issuing a GET request to retrieve a listing of tickets in the 123 project::

    <?php
    use Guzzle\Service\Client;

    $client = new Client('https://mydomain.unfuddle.com/api/v1');
    $request = $client->get('projects/{{project_id}}/tickets', array(
        'project_id' => '123'
    ));

    $request->setAuth('myusername', 'mypassword');
    $response = $request->send();

Notice that the URI provided to the client's ``get`` method is relative.  The path in the URI is also relative.  Relative paths will add to the path of the base URL of the client-- so in the example above, the path of the base URL is ``/api/v1``, the relative path is ``projects/123/tickets``, and the URL will ultimately become ``https://mydomain.unfuddle.com/api/v1/projects/123/tickets``.  If a relative path and a query string are provided, then the relative path will be appended to the base URL path, and the query string provided will be merged into the query string of the base URL.  If an absolute path is provided (e.g. /path/to/something), then the path specified in the base URL of the client will be replaced with the absolute path, and the query string provided will replace the query string of the base URL.  If an absolute URL is provided (e.g. ``http://www.test.com/path``), then the request will completely use the absolute URL as-is without merging in any of the URL parts specified in the base URL.

Templates can be specified in the client's get, head, delete, post, and put methods, which allow placeholders to be specified in the the request template that will be overwritten with an array of configuration data referenced by key.

All requests in the above client would need the basic HTTP authorization added after they are created.  You can automate this and add the authorization header to all requests generated by the client by adding a custom event to the client's event manager.  Another annoyance you can solve with Guzzle's event system is automatically creating SimpleXMLElement objects for a response when the content type is ``application/xml``.  Here's an example of creating a very simple Unfuddle client that lists the email addresses of everyone in your project (note: Guzzle has a robust Unfuddle client-- this is just an example)::

    <?php

    $client = new Client('https://mydomain.unfuddle.com/api/v1');
    $client->getEventManager()->attach(function($subject, $event, $context) {
        if ($event == 'request.create') {
            $context->setAuth('myusername', 'mypassword');
        } else if ($event == 'request.complete' && $context->isContentType('application/xml')) {
            // Hack the getInfo/setInfo methods
            $context->setInfo(
                array_merge(array(
                    'xml' => new \SimpleXMLElement($context->getBody(true)))
                ), $context->getInfo()
            );
        }
    });

    $response = $client->get('projects/{{project_id}}/people', array(
        'project_id' => '1'
    ))->send();

    foreach ($response->getInfo('xml')->person as $person) {
        echo $person->email . "\n";
    }

Installing Guzzle
-----------------

    git clone http://github.com/guzzle/guzzle

You will need to add Guzzle to your application's autoloader.  Guzzle ships with a few select classes from other vendors, one of which is the Symfony2 universal class loader.  If your application does not already use an autoloader, you can use the autoloader distributed with Guzzle::

    <?php

    require_once '/path/to/guzzle/library/vendor/Symfony/Component/ClassLoader/UniversalClassLoader.php';

    $classLoader = new \Symfony\Component\ClassLoader\UniversalClassLoader();
    $classLoader->registerNamespaces(array(
        'Guzzle' => '/path/to/guzzle/src'
    ));
    $classLoader->register();

Installing services
-------------------

Current Services
~~~~~~~~~~~~~~~~

Guzzle services are distributed separately from the Guzzle framework.  Guzzle officially supports a few webservice clients (these clients are currently what we use at SHOEBACCA.com), and hopefully there will be third-party created services coming soon:

* `Amazon Webservices (AWS) <https://github.com/guzzle/guzzle-aws>`_

    * Amazon S3
    * Amazon SimpleDB
    * Amazon SQS
    * Amazon MWS

* `Unfuddle <https://github.com/guzzle/guzzle-unfuddle>`_
* `Cardinal Commerce <https://github.com/guzzle/guzzle-cardinal-commerce>`_

When installing a Guzzle service, check the service's installation instructions for specific examples on how to install the service.

Autoloading Services
~~~~~~~~~~~~~~~~~~~~

Services that are installed within the path to Guzzle under the Service folder will be autoloaded automatically using the autoloader settings configured for the Guzzle library (e.g. /Guzzle/Service/Aws).  If you install a Guzzle service outside of this directory structure, you will need to add the service to the autoloader.

Using Services
--------------

Let's say you want to use the Amazon S3 client from the Guzzle AWS service.

1. Create a services.xml file:

Create a services.xml that your ServiceBuilder will use to create service clients.  The services.xml file defines the clients you will be using and the arguments that will be passed into the client when it is constructed.  Each client + arguments combination is given a name and  referenced by name when retrieving a client from the ServiceBuilder.::

    <?xml version="1.0" ?>
    <guzzle>
        <clients>
            <!-- Abstract service to store AWS account credentials -->
            <client name="test.abstract.aws">
                <param name="access_key" value="12345" />
                <param name="secret_key" value="abcd" />
            </client>
            <!-- Concrete Amazon S3 client -->
            <client name="test.s3" class="Guzzle.Aws.S3.S3Client" extends="test.abstract.aws" />
        </clients>
    </guzzle>

2. Create a ServiceBuilder::

    <?php
    use Guzzle\Service\ServiceBuilder;

    $serviceBuilder = ServiceBuilder::factory('/path/to/services.xml');

3. Get the Amazon S3 client from the ServiceBuilder and execute a command::

    $client = $serviceBuilder['test.s3'];
    $command = $client->getCommand('object.get_object')
        ->setBucket('mybucket')
        ->setKey('mykey');

    // The result of the GetObject command returns an HTTP response object
    $httpResponse = $client->execute($command);
    echo $httpResponse->getBody();

The GetObject command just returns the HTTP response object when it is executed.  Other commands might return more valuable information when executed::

    $command = $client->getCommand('bucket.list_bucket')->setBucket('mybucket');
    $objects = $client->execute($command);

    // Iterate over every single object in the bucket
    // subsequent requests will be issued to retrieve
    // the next result of a truncated response
    foreach ($objects as $object) {
        echo "{$object['key']} {$object['size']}\n";
    }

    // You can get access to the HTTP request issued by the command and the response
    echo $command->getRequest();
    echo $command->getResponse();

The ListBucket command above returns a BucketIterator which will iterate over the entire contents of a bucket.  As you can see, commands can be as simple or complex as you want.

Send a request and retry using exponential backoff
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Here's an example of sending an HTTP request that will automatically retry transient failures using truncated exponential backoff::

    <?php
    use Guzzle\Http\Plugin\ExponentialBackoffPlugin;

    $request = RequestFactory::get('http://google.com/');
    $request->getEventManager()->attach(new ExponentialBackoffPlugin());
    $response = $request->send();

Over the wire logging
~~~~~~~~~~~~~~~~~~~~~

Use the ``Guzzle\Http\Plugin\LogPlugin`` to view all data sent over the wire, including entity bodies and redirects::

    <?php
    use Guzzle\Http\Message\RequestFactory;
    use Guzzle\Common\Log\ZendLogAdapter;
    use Guzzle\Http\Plugin\LogPlugin;

    $adapter = new ZendLogAdapter(new \Zend_Log(new \Zend_Log_Writer_Stream('php://output')));
    $logPlugin = new LogPlugin($adapter, LogPlugin::LOG_VERBOSE);
    $request = RequestFactory::get('http://google.com/');

    // Attach the plugin to the request
    $request->getEventManager()->attach($logPlugin);

    $request->send();

The code sample above wraps a ``Zend_Log`` object using a ``Guzzle\Common\Log\ZendLogAdapter``.  After attaching the request to the plugin, all data sent over the wire will be logged to stdout.  The above code sample would output something like::

    2011-03-10T20:07:56-06:00 DEBUG (7): www.google.com - "GET / HTTP/1.1" - 200 0 - 0.195698 0 45887
    * About to connect() to google.com port 80 (#0)
    *   Trying 74.125.227.50... * connected
    * Connected to google.com (74.125.227.50) port 80 (#0)
    > GET / HTTP/1.1
    Accept: */*
    Accept-Encoding: deflate, gzip
    User-Agent: Guzzle/0.9 (Language=PHP/5.3.5; curl=7.21.2; Host=x86_64-apple-darwin10.4.0)
    Host: google.com

    < HTTP/1.1 301 Moved Permanently
    < Location: http://www.google.com/
    < Content-Type: text/html; charset=UTF-8
    < Date: Fri, 11 Mar 2011 02:06:32 GMT
    < Expires: Sun, 10 Apr 2011 02:06:32 GMT
    < Cache-Control: public, max-age=2592000
    < Server: gws
    < Content-Length: 219
    < X-XSS-Protection: 1; mode=block
    <
    * Ignoring the response-body
    * Connection #0 to host google.com left intact
    * Issue another request to this URL: 'http://www.google.com/'
    * About to connect() to www.google.com port 80 (#1)
    *   Trying 74.125.45.147... * connected
    * Connected to www.google.com (74.125.45.147) port 80 (#1)
    > GET / HTTP/1.1
    Host: www.google.com
    Accept: */*
    Accept-Encoding: deflate, gzip
    User-Agent: Guzzle/0.9 (Language=PHP/5.3.5; curl=7.21.2; Host=x86_64-apple-darwin10.4.0)

    < HTTP/1.1 200 OK
    < Date: Fri, 11 Mar 2011 02:06:32 GMT
    < Expires: -1
    < Cache-Control: private, max-age=0
    < Content-Type: text/html; charset=ISO-8859-1
    < Set-Cookie: PREF=ID=8a61470bce22ed5b:FF=0:TM=1299809192:LM=1299809192:S=axQwBxLyhXV7mbE3; expires=Sun, 10-Mar-2013 02:06:32 GMT; path=/; domain=.google.com
    < Set-Cookie: NID=44=qxXLtXgSKI2S9_mG7KbN7yR2atSje1B9Eft_CHTyjTuIivwE9kB1sATn_YPmBNhZHiNyxcP4_tIYnawjSNWeAepixK3CoKHw-RINrgGNSG3RfpAG7M-IKxHmLhJM6NeA; expires=Sat, 10-Sep-2011 02:06:32 GMT; path=/; domain=.google.com; HttpOnly
    < Server: gws
    < X-XSS-Protection: 1; mode=block
    < Transfer-Encoding: chunked
    <
    * Connection #1 to host www.google.com left intact
    <!doctype html><html><head>
    [...snipped]

PHP-based caching forward proxy
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Guzzle can leverage HTTP's caching specifications using the ``Guzzle\Http\Plugin\CachePlugin``.  The CachePlugin provides a private transparent proxy cache that caches HTTP responses.  The caching logic, based on `RFC 2616 <http://www.w3.org/Protocols/rfc2616/rfc2616-sec13.html>`_, uses HTTP headers to control caching behavior, cache lifetime, and supports ETag and Last-Modified based revalidation::

    <?php
    use Doctrine\Common\Cache\ArrayCache;
    use Guzzle\Common\Cache\DoctrineCacheAdapter;
    use Guzzle\Http\Plugin\CachePlugin;
    use Guzzle\Http\Message\RequestFactory;

    $adapter = new DoctrineCacheAdapter(new ArrayCache());
    $cache = new CachePlugin($adapter, true);

    $request = RequestFactory::get('http://www.wikipedia.org/');
    $request->getEventManager()->attach($cache);
    $request->send();

    // The next request will revalidate against the origin server to see if it
    // has been modified.  If a 304 response is recieved the response will be
    // served from cache
    $request->setState('new')->$request->send();

Guzzle doesn't try to reinvent the wheel when it comes to caching or logging.  Plenty of other frameworks, namely the `Zend Framework <http://framework.zend.com/>`_, have excellent solutions in place that you are probably already using in your applications.  Guzzle uses adapters for caching and logging.  Guzzle currently supports log adapters for the Zend Framework and Monolog, and cache adapters for `Doctrine 2.0 <http://www.doctrine-project.org/>`_ and the Zend Framework.