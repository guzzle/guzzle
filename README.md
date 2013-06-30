Guzzle, PHP HTTP client and webservice framework
================================================

[![Latest Stable Version](https://poser.pugx.org/guzzle/guzzle/version.png)](https://packagist.org/packages/guzzle/guzzle) [![Composer Downloads](https://poser.pugx.org/guzzle/guzzle/d/total.png)](https://packagist.org/packages/guzzle/guzzle) [![Build Status](https://secure.travis-ci.org/guzzle/guzzle.png?branch=master)](http://travis-ci.org/guzzle/guzzle)

Guzzle is a PHP HTTP client and framework for building RESTful web service clients.

- Extremely powerful API provides all the power of cURL with a simple interface.
- Truly take advantage of HTTP/1.1 with persistent connections, connection pooling, and parallel requests.
- Service description DSL allows you build awesome web service clients faster.
- Symfony2 event-based plugin system allows you to completely modify the behavior of a request.

Get answers with: [Documentation](http://www.guzzlephp.org/), [Forums](https://groups.google.com/forum/?hl=en#!forum/guzzle), IRC ([#guzzlephp](irc://irc.freenode.net/#guzzlephp) @ irc.freenode.net)

```php
// Really simple using a static facade
Guzzle\Http\StaticClient::mount();
$response = Guzzle::get('http://guzzlephp.org');

// More control using a client class
$client = new \Guzzle\Http\Client('http://guzzlephp.org');
$request = $client->get('/');
$response = $request->send();
```

### Installing via Composer

The recommended way to install Guzzle is through [Composer](http://getcomposer.org).

```bash
# Install Composer
curl -sS https://getcomposer.org/installer | php

# Add Guzzle as a dependency
php composer.phar require guzzle/guzzle:~3.7
```

After installing, you need to require Composer's autoloader:

```php
require 'vendor/autoload.php';
```

### Installing via phar

[Download the phar](http://guzzlephp.org/guzzle.phar) and include it in your project
([minimal phar](http://guzzlephp.org/guzzle-min.phar))

Features
--------

- Supports GET, HEAD, POST, DELETE, PUT, PATCH, OPTIONS, and any other custom HTTP method
- Allows full access to request and response headers
- Persistent connections are implicitly managed by Guzzle, resulting in huge performance benefits
- [Send requests in parallel](http://guzzlephp.org/tour/http.html#send-http-requests-in-parallel)
- Cookie sessions can be maintained between requests using the
  [CookiePlugin](http://guzzlephp.org/tour/http.html#cookie-session-plugin)
- Allows custom [entity bodies](http://guzzlephp.org/tour/http.html#entity-bodies), including sending data from a PHP
  stream and downloading data to a PHP stream
- Responses can be cached and served from cache using the
  [caching forward proxy plugin](http://guzzlephp.org/tour/http.html#php-based-caching-forward-proxy)
- Failed requests can be retried using
  [truncated exponential backoff](http://guzzlephp.org/tour/http.html#truncated-exponential-backoff) with custom retry
  policies
- Entity bodies can be validated automatically using Content-MD5 headers and the
  [MD5 hash validator plugin](http://guzzlephp.org/tour/http.html#md5-hash-validator-plugin)
- All data sent over the wire can be logged using the
  [LogPlugin](http://guzzlephp.org/tour/http.html#over-the-wire-logging)
- Subject/Observer signal slot system for unobtrusively
  [modifying request behavior](http://guzzlephp.org/guide/http/creating_plugins.html)
- Supports all of the features of libcurl including authentication, compression, redirects, SSL, proxies, etc
- Web service client framework for building future-proof interfaces to web services
- Includes a [service description DSL](http://guzzlephp.org/guide/service/service_descriptions.html) for quickly
  building webservice clients
- Full support for [URI templates](http://tools.ietf.org/html/rfc6570)
- Advanced batching functionality to efficiently send requests or commands in parallel with customizable batch sizes
  and transfer strategies

HTTP basics
-----------

```php
<?php

use Guzzle\Http\Client;

$client = new Client('http://www.example.com/api/v1/key/{key}', [
    'key' => '***'
]);

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
$response = $client->put('upload/text', [
    'X-Header' => 'My Header'
], 'body of the request')->send();

// Send a PUT request using the contents of a PHP stream as the body
// Send using an absolute URL (overrides the base URL)
$response = $client->put('http://www.example.com/upload', [
    'X-Header' => 'My Header'
], fopen('http://www.test.com/', 'r'));

// Create a POST request with a file upload (notice the @ symbol):
$request = $client->post('http://localhost:8983/solr/update', null, [
    'custom_field' => 'my value',
    'file' => '@/path/to/documents.xml'
]);

// Create a POST request and add the POST files manually
$request = $client->post('http://localhost:8983/solr/update')
    ->addPostFiles(['file' => '/path/to/documents.xml']);

// Responses are objects
echo $response->getStatusCode() . ' ' . $response->getReasonPhrase() . "\n";

// Requests and responses can be cast to a string to show the raw HTTP message
echo $request . "\n\n" . $response;

// Create a request based on an HTTP message
$request = RequestFactory::fromMessage(
    "PUT / HTTP/1.1\r\n" .
    "Host: test.com:8081\r\n" .
    "Content-Type: text/plain" .
    "Transfer-Encoding: chunked\r\n" .
    "\r\n" .
    "this is the body"
);
```

Using the static client facade
------------------------------

You can use Guzzle through a static client to make it even easier to send simple HTTP requests.

```php
<?php

// Use the static client directly:
$response = Guzzle\Http\StaticClient::get('http://www.google.com');

// Or, mount the client to \Guzzle to make it easier to use
Guzzle\Http\StaticClient::mount();

$response = Guzzle::get('http://guzzlephp.org');

// Custom options can be passed into requests created by the static client
$response = Guzzle::post('http://guzzlephp.org', [
    'headers' => ['X-Foo' => 'Bar']
    'body'    => ['Foo' => 'Bar'],
    'query'   => ['Test' => 123],
    'timeout' => 10,
    'debug'   => true,
    'save_to' => '/path/to/file.html'
]);
```

### Available request options:

* headers: Associative array of headers
* query: Associative array of query string values to add to the request
* body: Body of a request, including an EntityBody, string, or array when sending POST requests. Setting a body for a
  GET request will set where the response body is downloaded.
* auth: Array of HTTP authentication parameters to use with the request. The array must contain the
  username in index [0], the password in index [2], and can optionally contain the authentication type in index [3].
  The authentication types are: "Basic", "Digest". The default auth type is "Basic".
* cookies: Associative array of cookies
* allow_redirects: Set to false to disable redirects
* save_to: String, fopen resource, or EntityBody object used to store the body of the response
* events: Associative array mapping event names to a closure or array of (priority, closure)
* plugins: Array of plugins to add to the request
* exceptions: Set to false to disable throwing exceptions on an HTTP level error (e.g. 404, 500, etc)
* timeout: Float describing the timeout of the request in seconds
* connect_timeout: Float describing the number of seconds to wait while trying to connect. Use 0 to wait
  indefinitely.
* verify: Set to true to enable SSL cert validation (the default), false to disable, or supply the path to a CA
  bundle to enable verification using a custom certificate.
* proxy: Specify an HTTP proxy (e.g. "http://username:password@192.168.16.1:10")
* debug: Set to true to display all data sent over the wire

These options can also be used when creating requests using a standard client:

```php
$client = new Guzzle\Http\Client();
// Create a request with a timeout of 10 seconds
$request = $client->get('http://guzzlephp.org', [], ['timeout' => 10]);
$response = $request->send();
```

Unit testing
------------

Guzzle uses PHPUnit for unit testing. In order to run the unit tests, you'll first need
to install the dependencies of the project using Composer: `php composer.phar install --dev`.
You can then run the tests using `vendor/bin/phpunit`.

If you are running the tests with xdebug enabled, you may encounter the following issue: 'Fatal error: Maximum function nesting level of '100' reached, aborting!'. This can be resolved by adding 'xdebug.max_nesting_level = 200' to your php.ini file.

The PECL extensions, uri_template and pecl_http will be required to ensure all the tests can run.
