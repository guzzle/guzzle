Guzzle, PHP HTTP client and webservice framework
================================================

[![Build Status](https://secure.travis-ci.org/guzzle/guzzle.png?branch=master)](http://travis-ci.org/guzzle/guzzle)

Guzzle is a PHP HTTP client that makes it easy to send HTTP requests and
trivial to integrate with web services.

- Manages things like persistent connections, represents query strings as
  collections, simplifies sending streaming POST requests with fields and
  files, and abstracts away the underlying HTTP transport layer.
- Can send both synchronous and asynchronous requests using the same interface
  without requiring a dependency on a specific event loop.
- Pluggable HTTP adapters allows Guzzle to integrate with any method you choose
  for sending HTTP requests over the wire (e.g., cURL, sockets, PHP's stream
  wrapper, non-blocking event loops like ReactPHP.
- Guzzle makes it so that you no longer need to fool around with cURL options,
  stream contexts, or sockets.

```php
$client = new GuzzleHttp\Client();
$response = $client->get('http://guzzlephp.org');
$res = $client->get('https://api.github.com/user', ['auth' =>  ['user', 'pass']]);
echo $res->getStatusCode();
// "200"
echo $res->getHeader('content-type');
// 'application/json; charset=utf8'
echo $res->getBody();
// {"type":"User"...'
var_export($res->json());
// Outputs the JSON decoded data

// Send an asynchronous request.
$req = $client->createRequest('GET', 'http://httpbin.org', ['future' => true]);
$client->send($req)->then(function ($response) {
    echo 'I completed! ' . $response;
});
```

Get more information and answers with the
[Documentation](http://guzzlephp.org/),
[Forums](https://groups.google.com/forum/?hl=en#!forum/guzzle),
and [Gitter](https://gitter.im/guzzle/guzzle).

### Installing via Composer

The recommended way to install Guzzle is through
[Composer](http://getcomposer.org).

```bash
# Install Composer
curl -sS https://getcomposer.org/installer | php
```

Next, update your project's composer.json file to include Guzzle:

```javascript
{
    "require": {
        "guzzlehttp/guzzle": "~5.0"
    }
}
```

After installing, you need to require Composer's autoloader:

```php
require 'vendor/autoload.php';
```

### Documentation

More information can be found in the online documentation at
http://guzzlephp.org/.
