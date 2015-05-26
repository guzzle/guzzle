Guzzle, PHP HTTP client
=======================

[![Build Status](https://secure.travis-ci.org/guzzle/guzzle.svg?branch=master)](http://travis-ci.org/guzzle/guzzle)

Guzzle is a PHP HTTP client that makes it easy to send HTTP requests and
trivial to integrate with web services.

- Simple interface for building query strings, POST requests, streaming large
  uploads, streaming large downloads, using HTTP cookies, uploading JSON data,
  etc...
- Can send both synchronous and asynchronous requests using the same interface.
- Uses PSR-7 interfaces for requests, responses, and streams. This allows you
  to utilize other PSR-7 compatible libraries with Guzzle.
- Abstracts away the underlying HTTP transport, allowing you to write
  environment and transport agnostic code; i.e., no hard dependency on cURL,
  PHP streams, sockets, or non-blocking event loops.
- Middleware system allows you to augment and compose client behavior.

```php
$client = new GuzzleHttp\Client();
$res = $client->get('https://api.github.com/user', ['auth' =>  ['user', 'pass']]);
echo $res->getStatusCode();
// "200"
echo $res->getHeader('content-type');
// 'application/json; charset=utf8'
echo $res->getBody();
// {"type":"User"...'

// Send an asynchronous request.
$request = new \GuzzleHttp\Psr7\Request('GET', 'http://httpbin.org');
$promise = $client->sendAsync($req)->then(function ($response) {
    echo 'I completed! ' . $response;
});
$promise->wait();
```

## Help and docs

- [Documentation](http://guzzlephp.org/)
- [stackoverflow](http://stackoverflow.com/questions/tagged/guzzle)
- [Gitter](https://gitter.im/guzzle/guzzle)


## Installing Guzzle

The recommended way to install Guzzle is through
[Composer](http://getcomposer.org).

```bash
# Install Composer
curl -sS https://getcomposer.org/installer | php
```

Next, run the Composer command to install the latest stable version of Guzzle:

```bash
composer.phar require guzzlehttp/guzzle
```

After installing, you need to require Composer's autoloader:

```php
require 'vendor/autoload.php';
```

You can then later update Guzzle using composer:

 ```bash
composer.phar update
 ```


## Version Guidance

- Guzzle 3.x (`guzzle/guzzle`) is now EOL and will no longer be maintained.
  Requires PHP 5.3+.
- Guzzle 4.x (`guzzlehttp/guzzle`) is now EOL and will no longer be maintained.
  Requires PHP 5.4+.
- Guzzle 5.x (`guzzlehttp/guzzle`) is still maintained under the `5.3` branch.
  Requires PHP 5.4+.
- Guzzle 6.x (`guzzlehttp/guzzle`) is the latest stable version of Guzzle. This
  is the only version that is PSR-7 compatible. Requires PHP 5.5+.
