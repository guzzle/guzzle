# Quick Start

This page provides a quick introduction to Guzzle and introductory examples. If you have not already installed Guzzle, head over to the [installation](overview.md#installation) section.

## Making a Request

You can send requests with Guzzle using a `GuzzleHttp\ClientInterface` object.

### Creating a Client

```php
use GuzzleHttp\Client;

$client = new Client([
    // Base URI is used with relative requests
    'base_uri' => 'http://httpbin.org',
    // You can set any number of default request options.
    'timeout'  => 2.0,
]);
```

Clients are immutable in Guzzle, which means that you cannot change the defaults used by a client after it's created.

The client constructor accepts an associative array of options:

`base_uri`
(string\|UriInterface) Base URI of the client that is merged into relative URIs. Can be a string or instance of UriInterface. When a relative URI is provided to a client, the client will combine the base URI with the relative URI using the rules described in [RFC 3986, section 5.2](https://datatracker.ietf.org/doc/html/rfc3986#section-5.2).

```php
// Create a client with a base URI
$client = new GuzzleHttp\Client(['base_uri' => 'https://foo.com/api/']);
// Send a request to https://foo.com/api/test
$response = $client->request('GET', 'test');
// Send a request to https://foo.com/root
$response = $client->request('GET', '/root');
```

Don't feel like reading RFC 3986? Here are some quick examples on how a `base_uri` is resolved with another URI.

| base_uri              | URI              | Result                   |
|-----------------------|------------------|--------------------------|
| `http://foo.com`      | `/bar`           | `http://foo.com/bar`     |
| `http://foo.com/foo`  | `/bar`           | `http://foo.com/bar`     |
| `http://foo.com/foo`  | `bar`            | `http://foo.com/bar`     |
| `http://foo.com/foo/` | `bar`            | `http://foo.com/foo/bar` |
| `http://foo.com/foo/` | `/bar`           | `http://foo.com/bar`     |
| `http://foo.com`      | `http://baz.com` | `http://baz.com`         |
| `http://foo.com/?bar` | `bar`            | `http://foo.com/bar`     |

`handler`
(callable) Function that transfers HTTP requests over the wire. The function is called with a `Psr7\Http\Message\RequestInterface` and array of transfer options, and must return a `GuzzleHttp\Promise\PromiseInterface` that is fulfilled with a `Psr7\Http\Message\ResponseInterface` on success.

`...`
(mixed) All other options passed to the constructor are used as default request options with every request created by the client.

### Sending Requests

Named shortcut methods on the client make common synchronous requests concise:

```php
$response = $client->get('http://httpbin.org/get');
$response = $client->delete('http://httpbin.org/delete');
$response = $client->head('http://httpbin.org/get');
$response = $client->patch('http://httpbin.org/patch');
$response = $client->post('http://httpbin.org/post');
$response = $client->put('http://httpbin.org/put');
```

For other HTTP methods, use `request()` with the method name:

```php
$response = $client->request('OPTIONS', 'http://httpbin.org/get');
```

You can create a request and then send the request with the client when you're ready:

```php
use GuzzleHttp\Psr7\Request;

$request = new Request('PUT', 'http://httpbin.org/put');
$response = $client->send($request, ['timeout' => 2]);
```

Client objects provide a great deal of flexibility in how request are transferred including default request options, default handler stack middleware that are used by each request, and a base URI that allows you to send requests with relative URIs.

You can find out more about client middleware in [Middleware](middleware.md).

### Async Requests

You can send asynchronous requests using the named async shortcut methods
provided by a client:

```php
$promise = $client->getAsync('http://httpbin.org/get');
$promise = $client->deleteAsync('http://httpbin.org/delete');
$promise = $client->headAsync('http://httpbin.org/get');
$promise = $client->patchAsync('http://httpbin.org/patch');
$promise = $client->postAsync('http://httpbin.org/post');
$promise = $client->putAsync('http://httpbin.org/put');
```

You can also use the `sendAsync()` and `requestAsync()` methods of a client. Use
`requestAsync()` for asynchronous requests that do not have a named shortcut
method:

```php
use GuzzleHttp\Psr7\Request;

// Create a PSR-7 request object to send
$headers = ['X-Foo' => 'Bar'];
$body = 'Hello!';
$request = new Request('HEAD', 'http://httpbin.org/head', $headers, $body);
$promise = $client->sendAsync($request);

// Or, if you don't need to pass in a request instance:
$promise = $client->requestAsync('GET', 'http://httpbin.org/get');
$promise = $client->requestAsync('OPTIONS', 'http://httpbin.org/get');
```

The promise returned by these methods is a
`GuzzleHttp\Promise\PromiseInterface<Psr\Http\Message\ResponseInterface, mixed>`
provided by the [Guzzle Promises library](https://github.com/guzzle/promises/blob/3.0/README.md).
This means that you can chain `then()` calls off of the promise. These then
calls are either fulfilled with a successful
`Psr\Http\Message\ResponseInterface` or rejected with a reason. The reason is
often an exception from Guzzle's exception hierarchy, but custom handlers can
reject with other values.

```php
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Exception\TransferException;

$promise = $client->requestAsync('GET', 'http://httpbin.org/get');
$promise->then(
    function (ResponseInterface $res) {
        echo $res->getStatusCode();
    },
    function ($reason) {
        if ($reason instanceof TransferException) {
            echo $reason->getMessage();
        }
    }
);
```

### Concurrent Requests

You can send multiple requests concurrently using promises and asynchronous requests.

```php
use GuzzleHttp\Client;
use GuzzleHttp\Promise;

$client = new Client(['base_uri' => 'http://httpbin.org/']);

// Initiate each request but do not block
$promises = [
    'image' => $client->getAsync('/image'),
    'png'   => $client->getAsync('/image/png'),
    'jpeg'  => $client->getAsync('/image/jpeg'),
    'webp'  => $client->getAsync('/image/webp')
];

// Wait for the requests to complete; throws if any request is rejected.
$responses = Promise\Utils::unwrap($promises);

// You can access each response using the key of the promise
echo $responses['image']->getHeader('Content-Length')[0];
echo $responses['png']->getHeader('Content-Length')[0];

// Wait for the requests to complete, even if some of them fail
$responses = Promise\Utils::settle($promises)->wait();

// Values returned above are wrapped in an array with "state" (either fulfilled or rejected),
// plus "value" for fulfilled responses or "reason" for rejected requests.
echo $responses['image']['state']; // returns "fulfilled"
echo $responses['image']['value']->getHeader('Content-Length')[0];
echo $responses['png']['value']->getHeader('Content-Length')[0];
```

You can use the `GuzzleHttp\Pool` object when you have an indeterminate amount of requests you wish to send.

```php
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;

$client = new Client();

$requests = function ($total) {
    $uri = 'http://127.0.0.1:8126/guzzle-server/perf';
    for ($i = 0; $i < $total; $i++) {
        yield new Request('GET', $uri);
    }
};

$pool = new Pool($client, $requests(100), [
    'concurrency' => 5,
    'fulfilled' => function (ResponseInterface $response, $index, PromiseInterface $aggregate) {
        // this is delivered each successful response
    },
    'rejected' => function ($reason, $index, PromiseInterface $aggregate) {
        // this is delivered each failed request
    },
]);

// Initiate the transfers and create a promise
$promise = $pool->promise();

// Force the pool of requests to complete.
$promise->wait();
```

Or using a closure that will receive the merged request options and return either a response or a promise once the pool calls the closure.

```php
$client = new Client();

$requests = function ($total) use ($client) {
    $uri = 'http://127.0.0.1:8126/guzzle-server/perf';
    for ($i = 0; $i < $total; $i++) {
        yield function (array $options) use ($client, $uri) {
            return $client->getAsync($uri, $options);
        };
    }
};

$pool = new Pool($client, $requests(100));
```

### Batching Requests

When you have a fixed set of requests and just want them sent concurrently with the results collected for you, use the static `GuzzleHttp\Pool::batch()` helper. It sends the requests, blocks until they all settle, and returns an array containing each response — or the rejection reason for a failed transfer — in the same order as the requests.

```php
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;

$client = new Client();

$requests = [
    new Request('GET', 'http://httpbin.org/get'),
    new Request('GET', 'http://httpbin.org/status/500'),
];

$results = Pool::batch($client, $requests, ['concurrency' => 5]);

foreach ($results as $index => $result) {
    if ($result instanceof ResponseInterface) {
        echo $index . ': ' . $result->getStatusCode() . "\n";
    } else {
        // The transfer failed; $result is the rejection reason, usually an exception.
        echo $index . ': failed' . "\n";
    }
}
```

`Pool::batch()` accepts the same options as the `Pool` constructor (`concurrency`, `options`, `fulfilled`, and `rejected`). Because it keeps every request and response in memory, it is not suited to a very large or indeterminate number of requests; use the `Pool` object directly in that case.

## Using Responses

In the previous examples, we retrieved a `$response` variable or we were delivered a response from a promise. The response object implements a PSR-7 response, `Psr\Http\Message\ResponseInterface`, and contains lots of helpful information.

You can get the status code and reason phrase of the response:

```php
$code = $response->getStatusCode(); // 200
$reason = $response->getReasonPhrase(); // OK
```

You can retrieve headers from the response:

```php
// Check if a header exists.
if ($response->hasHeader('Content-Length')) {
    echo "It exists";
}

// Get a header from the response.
echo $response->getHeader('Content-Length')[0];

// Get all of the response headers.
foreach ($response->getHeaders() as $name => $values) {
    echo $name . ': ' . implode(', ', $values) . "\r\n";
}
```

The body of a response can be retrieved using the `getBody` method. The body can be used as a string, cast to a string, or used as a stream like object.

```php
$body = $response->getBody();
// Implicitly cast the body to a string and echo it
echo $body;
// Explicitly cast the body to a string
$stringBody = (string) $body;
// Read 10 bytes from the body
$tenBytes = $body->read(10);
// Read the remaining contents of the body as a string
$remainingBytes = $body->getContents();
```

## Query String Parameters

You can provide query string parameters with a request in several ways.

You can set query string parameters in the request's URI:

```php
$response = $client->request('GET', 'http://httpbin.org?foo=bar');
```

You can specify the query string parameters using the `query` request option as an array.

```php
$client->request('GET', 'http://httpbin.org', [
    'query' => ['foo' => 'bar']
]);
```

Providing the option as an array will use PHP's `http_build_query` function to format the query string.

And finally, you can provide the `query` request option as a string.

```php
$client->request('GET', 'http://httpbin.org', ['query' => 'foo=bar']);
```

## Uploading Data

Guzzle provides several ways to upload data, including raw request bodies, JSON, form fields, and multipart file uploads. See [Uploading Data](uploading-data.md).

## Cookies

Guzzle can manage cookies for you using a cookie jar. See [Cookies](cookies.md) for using, persisting, and inspecting cookies.

## Redirects

Guzzle will automatically follow redirects unless you tell it not to. You can customize the redirect behavior using the `allow_redirects` request option.

- Set to `true` to enable normal redirects with a maximum number of 5 redirects. This is the default setting.
- Set to `false` to disable redirects.
- Pass an associative array containing the 'max' key to specify the maximum number of redirects and optionally provide a 'strict' key value to specify whether or not to use strict RFC compliant redirects (meaning redirect POST requests with POST requests vs. doing what most browsers do which is redirect POST requests with GET requests).

See the [`allow_redirects` option](request-options.md#allow_redirects) for cross-origin redirect credential behavior.

```php
$response = $client->request('GET', 'http://github.com');
echo $response->getStatusCode();
// 200
```

The following example shows that redirects can be disabled.

```php
$response = $client->request('GET', 'http://github.com', [
    'allow_redirects' => false
]);
echo $response->getStatusCode();
// 301
```

## Exceptions

When a transfer fails, Guzzle throws an exception from its exception hierarchy. See [Exceptions](exceptions.md) for the hierarchy and how to catch transfer failures.

## Environment Variables

Guzzle exposes a few environment variables that can be used to customize the behavior of the library.

`HTTP_PROXY`
Defines the proxy to use when sending requests using the "http" protocol.

Note: because the HTTP_PROXY variable may contain arbitrary user input on some (CGI) environments, the variable is only used on the CLI SAPI. See <https://httpoxy.org> for more information.

`HTTPS_PROXY`
Defines the proxy to use when sending requests using the "https" protocol.

`NO_PROXY`
Defines hosts and IP rules for which a proxy should not be used. See the [`proxy` option](request-options.md#proxy).

### Relevant INI Settings

Guzzle can utilize PHP ini settings when configuring clients.

`openssl.cafile`
Specifies the path on disk to a CA file in PEM format to use when sending requests over "https". See: <https://wiki.php.net/rfc/tls-peer-verification#phpini_defaults>
