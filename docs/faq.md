# FAQ

## Does Guzzle require cURL?

No. Guzzle can use any HTTP handler to send requests. This means that Guzzle can be used with cURL, PHP's stream wrapper, sockets, and non-blocking libraries like [React](https://reactphp.org/). You just need to configure an HTTP handler to use a different method of sending requests.

> [!NOTE]
> Guzzle has historically only utilized cURL to send HTTP requests. cURL is an amazing HTTP client (arguably the best), and Guzzle will continue to use it by default when it is available. It is rare, but some developers don't have cURL installed on their systems or run into version specific issues. By allowing swappable HTTP handlers, Guzzle is now much more customizable and able to adapt to fit the needs of more developers.

## Can Guzzle send asynchronous requests?

Yes. You can use the `requestAsync`, `sendAsync`, `getAsync`, `headAsync`, `putAsync`, `postAsync`, `deleteAsync`, and `patchAsync` methods of a client to send an asynchronous request. The client will return a `GuzzleHttp\Promise\PromiseInterface` object. You can chain `then` functions off of the promise for fulfilled responses and rejected reasons.

> [!NOTE]
> In Guzzle 7, `optionsAsync()` still works through deprecated `Client::__call()` compatibility. This deprecation does not affect named async shortcuts such as `getAsync()` and `postAsync()`, which are real client methods. Prefer `requestAsync('OPTIONS', ...)` in new code. `Client::__call()` is removed in Guzzle 8.

```php
$promise = $client->requestAsync('GET', 'http://httpbin.org/get');
$promise->then(function ($response) {
    echo 'Got a response! ' . $response->getStatusCode();
}, function ($reason) {
    // The rejection reason is often a Guzzle exception, but custom handlers can
    // reject with other values.
});
```

You can force an asynchronous response to complete using the `wait()` method of the returned promise. It returns the response on fulfillment and throws when the promise is rejected.

```php
$promise = $client->requestAsync('GET', 'http://httpbin.org/get');
$response = $promise->wait();
```

## How can I add custom cURL options?

cURL offers a huge number of
[customizable options](https://www.php.net/curl_setopt). While Guzzle
normalizes many of these options across different handlers, there are times
when you need to set custom cURL options. This can be accomplished by passing
an array keyed by allow-listed integer `CURLOPT_*` constants in the **curl**
key of a request. Raw cURL options outside the built-in cURL handlers'
allow-list are deprecated. The special `body_as_string` key is also recognized
by Guzzle's cURL handler.

For example, let's say you need to customize the outgoing network interface used
with a client.

```php
$client->request('GET', '/', [
    'curl' => [
        CURLOPT_INTERFACE => 'xxx.xxx.xxx.xxx'
    ]
]);
```

If you use asynchronous requests with cURL multi handler and want to tweak it,
additional options can be specified as an array keyed by integer `CURLMOPT_*`
constants in the **options** key of the `CurlMultiHandler` constructor.

```php
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Handler\CurlMultiHandler;

$client = new Client(['handler' => HandlerStack::create(new CurlMultiHandler([
    'options' => [
        CURLMOPT_MAX_TOTAL_CONNECTIONS => 50,
        CURLMOPT_MAX_HOST_CONNECTIONS => 5,
    ]
]))]);
```

Custom cURL request options remain active during redirects unless Guzzle
documents otherwise. See [`allow_redirects`](request-options.md#allow_redirects)
for cross-origin redirect credential behavior.

## How can I add custom stream context options?

You can pass allow-listed custom
[stream context options](https://www.php.net/manual/en/context.php) using the
**stream_context** key of the request option. The **stream_context** array is an
associative array where each key is a PHP transport, and each value is an
associative array of transport options. Stream context options outside the
built-in stream handler allow-list are deprecated.

For example, let's say you need to customize the outgoing network interface used
with a client.

```php
$client->request('GET', '/', [
    'stream' => true,
    'stream_context' => [
        'socket' => [
            'bindto' => 'xxx.xxx.xxx.xxx'
        ]
    ]
]);
```

Custom stream context options remain active during redirects unless Guzzle
documents otherwise. See [`allow_redirects`](request-options.md#allow_redirects)
for cross-origin redirect credential behavior.

## Why am I getting an SSL verification error?

You need to specify the path on disk to the CA bundle used by Guzzle for verifying the peer certificate. See the [`verify` option](request-options.md#verify).

## What is this Maximum function nesting error?

> Maximum function nesting level of '100' reached, aborting

You could run into this error if you have the XDebug extension installed and you execute a lot of requests in callbacks. This error message comes specifically from the XDebug extension. PHP itself does not have a function nesting limit. Change this setting in your php.ini to increase the limit:

    xdebug.max_nesting_level = 1000

## Why am I getting a 417 error response?

This can occur for a number of reasons, but if you are sending PUT, POST, or PATCH requests with an `Expect: 100-Continue` header, a server that does not support this header will return a 417 response. You can work around this by setting the `expect` request option to `false`:

```php
$client = new GuzzleHttp\Client();

// Disable the expect header on a single request
$response = $client->request('PUT', '/', ['expect' => false]);

// Disable the expect header on all client requests
$client = new GuzzleHttp\Client(['expect' => false]);
```

## How can I track redirected requests?

You can enable tracking of redirected URIs and status codes via the `track_redirects` option. Each redirected URI and status code will be stored in the `X-Guzzle-Redirect-History` and the `X-Guzzle-Redirect-Status-History` header respectively.

The initial request's URI and the final status code will be excluded from the results. With this in mind you should be able to easily track a request's full redirect path.

For example, let's say you need to track redirects and provide both results together in a single report:

```php
// First you configure Guzzle with redirect tracking and make a request
$client = new Client([
    RequestOptions::ALLOW_REDIRECTS => [
        'max'             => 10,        // allow at most 10 redirects.
        'strict'          => true,      // use "strict" RFC compliant redirects.
        'referer'         => true,      // add a Referer header
        'track_redirects' => true,
    ],
]);
$initialRequest = '/redirect/3'; // Store the request URI for later use
$response = $client->request('GET', $initialRequest); // Make your request

// Retrieve both Redirect History headers
$redirectUriHistory = $response->getHeader('X-Guzzle-Redirect-History'); // retrieve Redirect URI history
$redirectCodeHistory = $response->getHeader('X-Guzzle-Redirect-Status-History'); // retrieve Redirect HTTP Status history

// Add the initial URI requested to the (beginning of) URI history
array_unshift($redirectUriHistory, $initialRequest);

// Add the final HTTP status code to the end of HTTP response history
array_push($redirectCodeHistory, $response->getStatusCode());

// (Optional) Combine the items of each array into a single result set
$fullRedirectReport = [];
foreach ($redirectUriHistory as $key => $value) {
    $fullRedirectReport[$key] = ['location' => $value, 'code' => $redirectCodeHistory[$key]];
}
echo json_encode($fullRedirectReport);
```
