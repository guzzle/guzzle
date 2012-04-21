Guzzle, PHP HTTP client and webservice framework
================================================

Guzzle is a game changer in the world of PHP HTTP clients. Guzzle allows you to truly reap the benefits of the HTTP/1.1 spec. No other library makes it easier to manage persistent connections or send requests in parallel.</p>

In addition to taking the pain out of HTTP, Guzzle provides a lightweight framework for creating web service clients.  Most web service clients follow a specific pattern: create a client class, create methods for each action, create and execute a cURL handle, parse the response, implement error handling, and return the result. Guzzle takes the redundancy out of this process and gives you the tools you need to quickly build a web service client.

Start <strong>truly</strong> consuming HTTP with Guzzle.

- [Download the phar](http://guzzlephp.org/guzzle.phar) and include it in your project ([minimal phar](http://guzzlephp.org/guzzle-min.phar))
- Docs: [www.guzzlephp.org](http://www.guzzlephp.org/)
- Forum: https://groups.google.com/forum/?hl=en#!forum/guzzle
- IRC: [#guzzlephp](irc://irc.freenode.net/#guzzlephp) channel on irc.freenode.net

[![Build Status](https://secure.travis-ci.org/guzzle/guzzle.png)](http://travis-ci.org/guzzle/guzzle)

Features
--------

- Supports GET, HEAD, POST, DELETE, PUT, PATCH, OPTIONS, and any custom verbs
- Allows full access to request and response headers
- Persistent connections are implicitly managed by Guzzle, resulting in huge performance benefits
- Send requests in parallel
- Cookie sessions can be maintained between requests using the CookiePlugin
- Allows custom entity bodies, including sending data from a PHP stream
- Responses can be cached and served from cache using the caching reverse proxy plugin
- Failed requests can be retried using truncated exponential backoff
- Entity bodies can be validated automatically using Content-MD5 headers
- All data sent over the wire can be logged using the LogPlugin
- Automatically requests compressed data and automatically decompresses data
- Subject/Observer signal slot system for unobtrusively modifying request behavior
- Supports all of the features of libcurl including authentication, redirects, SSL, proxies, etc
- Web service client framework for building future-proof interfaces to web services
- Full support for [URI templates](http://tools.ietf.org/html/draft-gregorio-uritemplate-08)

HTTP basics
-----------

```php
<?php

use Guzzle\Http\Client;

$client = new Client('http://www.example.com/api/v1/key/{{key}}', array(
    'key' => '***'
));

// Issue a path using a relative URL to the client's base URL
// Sends to http://www.example.com/api/v1/key/***/users
$request = $client->get('users');
$response = $request->send();

// Relative URL that overwrites the path of the base URL
$request = $client->get('/test/123.php?a=b');

// Issue a head request on the base URL
$response = $client->head()->send();
// Delete user 123
$response = $client->delete('users/123')->send();

// Send a PUT request with custom headers
$response = $client->put('upload/text', array(
    'X-Header' => 'My Header'
), 'body of the request')->send();

// Send a PUT request using the contents of a PHP stream as the body
// Send using an absolute URL (overrides the base URL)
$response = $client->put('http://www.example.com/upload', array(
    'X-Header' => 'My Header'
), fopen('http://www.test.com/', 'r'));

// Create a POST request with a file upload (notice the @ symbol):
$request = $client->post('http://localhost:8983/solr/update', null, array (
    'custom_field' => 'my value',
    'file' => '@/path/to/documents.xml'
));

// Create a POST request and add the POST files manually
$request = $client->post('http://localhost:8983/solr/update')
    ->addPostFiles(array(
        'file' => '/path/to/documents.xml'
    ));

// Responses are objects
echo $response->getStatusCode() . ' ' . $response->getReasonPhrase() . "\n";

// Requests and responses can be cast to a string to show the raw HTTP message
echo $request . "\n\n" . $response;

// Create a request based on an HTTP message
$request = RequestFactory::fromMessage(
    "PUT / HTTP/1.1\r\n" .
    "Host: test.com:8081\r\n" .
    "Content-Type: text/plain"
    "Transfer-Encoding: chunked\r\n" .
    "\r\n" .
    "this is the body"
);
```

Send requests in parallel
-------------------------

```php
<?php

try {
    $client = new Guzzle\Http\Client('http://www.myapi.com/api/v1');
    $responses = $client->send(array(
        $client->get('users'),
        $client->head('messages/123'),
        $client->delete('orders/123')
    ));
} catch (Guzzle\Common\Exception\ExceptionCollection $e) {
    echo "The following requests encountered an exception: \n";
    foreach ($e as $exception) {
        echo $exception->getRequest() . "\n" . $exception->getMessage() . "\n";
    }
}
```

URI templates
-------------

Guzzle supports the entire URI templates RFC.

```php
<?php

$client = new Guzzle\Http\Client('http://www.myapi.com/api/v1', array(
    'path' => '/path/to',
    'a'    => 'hi',
    'data' => array(
        'foo'  => 'bar',
        'mesa' => 'jarjar'
    )
));

$request = $client->get('http://www.test.com{+path}{?a,data*}');
```

The generated request URL would become: ``http://www.test.com/path/to?a=hi&foo=bar&mesa=jarajar``

You can specify URI templates and an array of additional template variables to use when creating requests:

```php
<?php

$client = new Guzzle\Http\Client('http://test.com', array(
    'a' => 'hi'
));

$request = $client->get(array('/{?a,b}', array(
    'b' => 'there'
));
```

The resulting URL would become ``http://test.com?a=hi&b=there``

Testing and contributing to Guzzle
----------------------------------

You will need to clone the Guzzle repository in order to be able to contribute to Guzzle:

```
git clone git@github.com:guzzle/guzzle.git
cd guzzle
```

Next you will need to make sure PHPUnit is configured, Composer is installed, and you have installed Guzzle's
testing dependencies.  This can be acheived very simply by using the `test-init` Phing task.  After running
this task, simply run PHPUnit.

```
phing test-init
phpunit
```

If you do not have Phing installed, you will need to perform the installation steps manually:

```
# Make sure Composer is installed
curl -s http://getcomposer.org/installer | php

# Install Guzzle's testing dependencies
php composer.phar install --dev

# Now kick off PHPUnit to run the tests
cp phpunit.xml.dist phpunit.xml
phpunit
```
