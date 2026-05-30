# Request Options

You can customize requests created and transferred by a client using **request options**. Request options control various aspects of a request including, headers, query string parameters, timeout settings, the body of a request, and much more.

All of the following examples use the following client:

```php
$client = new GuzzleHttp\Client(['base_uri' => 'http://httpbin.org']);
```

## allow_redirects

Summary
Describes the redirect behavior of a request

Types
- bool
- array

Default

```php
[
    'max'             => 5,
    'strict'          => false,
    'referer'         => false,
    'protocols'       => ['http', 'https'],
    'track_redirects' => false,
]
```

Constant
`GuzzleHttp\RequestOptions::ALLOW_REDIRECTS`

Set to `false` to disable redirects.

```php
$res = $client->request('GET', '/redirect/3', ['allow_redirects' => false]);
echo $res->getStatusCode();
// 302
```

Set to `true` (the default setting) to enable normal redirects with a maximum number of 5 redirects.

```php
$res = $client->request('GET', '/redirect/3');
echo $res->getStatusCode();
// 200
```

You can also pass an associative array containing the following key value pairs:

- max: (int, default=5) maximum number of allowed redirects.

- strict: (bool, default=false) Set to true to use strict redirects. Strict RFC compliant redirects mean that POST redirect requests are sent as POST requests vs. doing what most browsers do which is redirect POST requests with GET requests.

- referer: (bool, default=false) Set to true to enable adding the Referer header when redirecting.

- protocols: (non-empty array of strings, default=`['http', 'https']`) Specifies which protocols are allowed for redirect requests. Redirect matching is case-sensitive; use `http` and `https`.

- on_redirect: (callable) PHP callable that is invoked when a redirect is encountered. The callable is invoked with the original request, the redirect response that was received, and the effective URI. Any return value from the on_redirect function is ignored.

- track_redirects: (bool) When set to `true`, each redirected URI and status code encountered will be tracked in the `X-Guzzle-Redirect-History` and `X-Guzzle-Redirect-Status-History` headers respectively. All URIs and status codes will be stored in the order which the redirects were encountered.

  Note: When tracking redirects the `X-Guzzle-Redirect-History` header will exclude the initial request's URI and the `X-Guzzle-Redirect-Status-History` header will exclude the final status code.

```php
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

$onRedirect = function(
    RequestInterface $request,
    ResponseInterface $response,
    UriInterface $uri
) {
    echo 'Redirecting! ' . $request->getUri() . ' to ' . $uri . "\n";
};

$res = $client->request('GET', '/redirect/3', [
    'allow_redirects' => [
        'max'             => 10,        // allow at most 10 redirects.
        'strict'          => true,      // use "strict" RFC compliant redirects.
        'referer'         => true,      // add a Referer header
        'protocols'       => ['https'], // only allow https URLs
        'on_redirect'     => $onRedirect,
        'track_redirects' => true
    ]
]);

echo $res->getStatusCode();
// 200

echo $res->getHeaderLine('X-Guzzle-Redirect-History');
// http://first-redirect, http://second-redirect, etc...

echo $res->getHeaderLine('X-Guzzle-Redirect-Status-History');
// 301, 302, etc...
```

> [!WARNING]
> This option only has an effect if your handler has the `GuzzleHttp\Middleware::redirect` middleware. This middleware is added by default when a client is created with no handler, and is added by default when creating a handler with `GuzzleHttp\HandlerStack::create`.

> [!NOTE]
> This option has **no** effect when making requests using `GuzzleHttp\Client::sendRequest()`. In order to stay compliant with PSR-18 any redirect response is returned as is.

### Cross-Origin Redirects

Guzzle considers a redirect cross-origin when the scheme, host, or effective port changes.

On cross-origin redirects, Guzzle removes origin-scoped HTTP credentials before sending the redirected request. This includes the `Authorization` and `Cookie` headers, the generic `auth` request option, and cURL HTTP authentication options such as `CURLOPT_HTTPAUTH` and `CURLOPT_USERPWD`.

Same-origin redirects preserve those values. Guzzle does not automatically remove transport identity or TLS client credential options solely because the redirect is cross-origin. In particular, TLS client authentication options such as `cert`, `ssl_key`, custom cURL TLS options, and stream context TLS options are not removed automatically on cross-origin redirects.

If TLS client credentials are only trusted for the original origin, disable automatic redirects and handle redirect responses manually, or use separate clients and request options for trusted origins.

## auth

Summary
Pass HTTP authentication parameters to use with the request. An array must contain the username in index `[0]`, the password in index `[1]`, and can optionally provide a built-in authentication type in index `[2]`. Pass `false` or `null` to disable authentication for a request. String values are passed through for custom handlers.

Types
- array
- string
- false
- null

Default
None

Constant
`GuzzleHttp\RequestOptions::AUTH`

The built-in authentication types are as follows:

basic
Use [basic HTTP authentication](http://www.ietf.org/rfc/rfc7617.txt) in the `Authorization` header (the default setting used if none is specified).

```php
$client->request('GET', '/get', ['auth' => ['username', 'password']]);
```

digest
Use [digest authentication](http://www.ietf.org/rfc/rfc2069.txt) (must be supported by the HTTP handler).

```php
$client->request('GET', '/get', [
    'auth' => ['username', 'password', 'digest']
]);
```

> [!NOTE]
> This is currently only supported when using the cURL handler, but creating a replacement that can be used with any HTTP handler is planned.

ntlm
Use [Microsoft NTLM authentication](https://msdn.microsoft.com/en-us/library/windows/desktop/aa378749(v=vs.85).aspx) (must be supported by the HTTP handler).

```php
$client->request('GET', '/get', [
    'auth' => ['username', 'password', 'ntlm']
]);
```

> [!NOTE]
> This is currently only supported when using the cURL handler.

## body

Summary
The `body` option is used to control the body of an entity enclosing request (e.g., PUT, POST, PATCH).

Types
- string
- `fopen()` resource
- `Psr\Http\Message\StreamInterface`
- callable
- `Iterator`
- `Stringable`
- int
- float
- bool
- null

Default
None

Constant
`GuzzleHttp\RequestOptions::BODY`

This setting can be set to any of the following types:

- string

  ```php
  // You can send requests that use a string as the message body.
  $client->request('PUT', '/put', ['body' => 'foo']);
  ```

- resource returned from `fopen()`

  ```php
  // You can send requests that use a stream resource as the body.
  $resource = \GuzzleHttp\Psr7\Utils::tryFopen('http://httpbin.org', 'r');
  $client->request('PUT', '/put', ['body' => $resource]);
  ```

- `Psr\Http\Message\StreamInterface`

  ```php
  // You can send requests that use a Guzzle stream object as the body
  $stream = GuzzleHttp\Psr7\Utils::streamFor('contents...');
  $client->request('POST', '/post', ['body' => $stream]);
  ```

Scalar, resource, and object values with `__toString()` are converted to PSR-7 streams using the configured `stream_factory`. Callable and iterator bodies use Guzzle's existing PSR-7 stream handling because PSR-17 does not define factories for those stream types. Callable bodies may be closures or invokable objects. Strings are always used as literal body contents, even when they name a callable. Callable arrays are arrays, and arrays are not valid `body` values. Request bodies that already implement `Psr\Http\Message\StreamInterface` are used as provided.

> [!NOTE]
> This option cannot be used with `form_params`, `multipart`, or `json`

## cert

Summary
Set to a string to specify the path to a file containing a client side certificate. PEM is the default certificate format. If a password is required, then set to an array containing the path to the certificate file in the first array element followed by the password required for the certificate in the second array element. A `null` password is treated the same as omitting it. Use [`cert_type`](#cert_type) to specify another supported certificate format.

Types
- string
- array

Default
None

Constant
`GuzzleHttp\RequestOptions::CERT`

```php
$client->request('GET', '/', ['cert' => ['/path/server.pem', 'password']]);
```

> [!NOTE]
> TLS client certificate options remain active during redirects. See [Cross-Origin Redirects](#cross-origin-redirects) for details.

## cert_type

Summary
Specify the SSL client certificate file type.

Types
- string

Default
`PEM`

Constant
`GuzzleHttp\RequestOptions::CERT_TYPE`

```php
$client->request('GET', '/', [
    'cert' => '/path/client.p12',
    'cert_type' => 'P12',
]);
```

The cURL handler passes this value to `CURLOPT_SSLCERTTYPE`. Supported values depend on libcurl and its TLS backend.

> [!NOTE]
> The stream handler supports only `PEM` certificate files.

## cookies

Summary
Specifies whether or not cookies are used in a request or what cookie jar to use or what cookies to send.

Types
- `GuzzleHttp\Cookie\CookieJarInterface`
- false

Default
None

Constant
`GuzzleHttp\RequestOptions::COOKIES`

You must specify the cookies option as a `GuzzleHttp\Cookie\CookieJarInterface` or `false`.

```php
$jar = new \GuzzleHttp\Cookie\CookieJar();
$client->request('GET', '/get', ['cookies' => $jar]);
```

> [!WARNING]
> This option only has an effect if your handler has the `GuzzleHttp\Middleware::cookies` middleware. This middleware is added by default when a client is created with no handler, and is added by default when creating a handler with `GuzzleHttp\HandlerStack::create`.

> [!TIP]
> When creating a client, you can set the default cookie option to `true` to use a shared cookie session associated with the client.

## connect_timeout

Summary
Number of seconds to wait while trying to connect to a server. Use `0` to wait 300 seconds (the default behavior). Positive values below `0.001` seconds are rejected by the built-in cURL handler.

Types
- int
- float

Default
`0`

Constant
`GuzzleHttp\RequestOptions::CONNECT_TIMEOUT`

```php
// Timeout if the client fails to connect to the server in 3.14 seconds.
$client->request('GET', '/delay/5', ['connect_timeout' => 3.14]);
```

> [!NOTE]
> `connect_timeout` is implemented by cURL handlers. The PHP stream handler
> does not provide a separate connection-timeout control; it accepts this option
> without effect so shared request configuration can enable a cURL connection
> timeout when cURL is available. Use `timeout` to configure the stream handler's
> overall stream timeout.

## crypto_method

Summary
A value describing the minimum TLS protocol version to use.

Types
int

Default
TLS 1.2 or newer for HTTPS requests sent by the built-in cURL and stream handlers.

Constant
`GuzzleHttp\RequestOptions::CRYPTO_METHOD`

```php
$client->request('GET', '/foo', ['crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT]);
```

> [!NOTE]
> This setting must be set to one of the `STREAM_CRYPTO_METHOD_TLS*_CLIENT` constants. It controls the minimum TLS protocol version. cURL 7.52.0 or higher is required to use TLS 1.3 with the cURL handler.

## curl

Summary
Raw cURL options to apply when using a built-in cURL handler.

Types
- array

Default
None

Constant
No `RequestOptions` constant is defined for this handler-specific option.

The array is keyed by integer or string cURL option names and values are passed to cURL after Guzzle applies request options. Raw cURL options that conflict with Guzzle-managed request handling are rejected by the built-in cURL handlers.

```php
$client->request('GET', '/', [
    'curl' => [
        CURLOPT_FRESH_CONNECT => true,
    ],
]);
```

## debug

Summary
Set to `true` or set to a PHP stream returned by `fopen()` to enable debug output with the handler used to send a request. For example, when using cURL to transfer requests, cURL's verbose of `CURLOPT_VERBOSE` will be emitted. When using the PHP stream wrapper, stream wrapper notifications will be emitted. If set to true, the output is written to PHP's STDOUT. If a PHP stream is provided, output is written to the stream.

Types
- bool
- `fopen()` resource

Default
None

Constant
`GuzzleHttp\RequestOptions::DEBUG`

```php
$client->request('GET', '/get', ['debug' => true]);
```

Running the above example would output something like the following:

    * About to connect() to httpbin.org port 80 (#0)
    *   Trying 107.21.213.98... * Connected to httpbin.org (107.21.213.98) port 80 (#0)
    > GET /get HTTP/1.1
    Host: httpbin.org
    User-Agent: GuzzleHttp/8

    < HTTP/1.1 200 OK
    < Access-Control-Allow-Origin: *
    < Content-Type: application/json
    < Date: Sun, 16 Feb 2014 06:50:09 GMT
    < Server: gunicorn/0.17.4
    < Content-Length: 335
    < Connection: keep-alive
    <
    * Connection #0 to host httpbin.org left intact

## decode_content

Summary
Specify whether or not `Content-Encoding` responses (gzip, deflate, etc.) are automatically decoded.

Types
- string
- bool

Default
`true`

Constant
`GuzzleHttp\RequestOptions::DECODE_CONTENT`

This option can be used to control how content-encoded response bodies are handled. By default, `decode_content` is set to true, meaning any gzipped or deflated response will be decoded by Guzzle.

When set to `false`, the body of a response is never decoded, meaning the bytes pass through the handler unchanged.

```php
// Request gzipped data, but do not decode it while downloading
$client->request('GET', '/foo.js', [
    'headers'        => ['Accept-Encoding' => 'gzip'],
    'decode_content' => false
]);
```

When set to a string, the bytes of a response are decoded and the string value provided to the `decode_content` option is passed as the `Accept-Encoding` header of the request.

```php
// Pass "gzip" as the Accept-Encoding header.
$client->request('GET', '/foo.js', ['decode_content' => 'gzip']);
```

> [!WARNING]
> The `Accept-Encoding` header will not be sent unless you provide it explicitly, or pass a string value to `decode_content`. That is [equivalent](https://www.rfc-editor.org/rfc/rfc9110#field.accept-encoding) to sending `Accept-Encoding: *`. Most servers will probably return an uncompressed body in response to that but some might opt to use a compression method that is not supported by your system.
>
> In order to enable compression, and to ensure that only supported encoding methods will be used, you should let curl send the `Accept-Encoding` header:
>
> ```php
> // Delegate choosing compression method to curl
> $client->request('GET', '/foo.js', [
>     'curl' => [
>         \CURLOPT_ENCODING => '',
>     ],
> ]);
> ```

## delay

Summary
The number of milliseconds to delay before sending the request.

Types
- integer
- float

Default
null

Constant
`GuzzleHttp\RequestOptions::DELAY`

## expect

Summary
Controls the behavior of the "Expect: 100-Continue" header.

Types
- bool
- integer

Default
`1048576`

Constant
`GuzzleHttp\RequestOptions::EXPECT`

Set to `true` to enable the "Expect: 100-Continue" header for all requests that sends a body. Set to `false` to disable the "Expect: 100-Continue" header for all requests. Set to a number so that the size of the payload must be greater than the number in order to send the Expect header. Setting to a number will send the Expect header for all requests in which the size of the payload cannot be determined or where the body is not rewindable.

By default, Guzzle will add the "Expect: 100-Continue" header when the size of the body of a request is greater than 1 MB and a request is using HTTP/1.1.

> [!NOTE]
> This option only takes effect when using HTTP/1.1. The HTTP/1.0, HTTP/2, and HTTP/3 protocols do not support the "Expect: 100-Continue" header. Support for handling the "Expect: 100-Continue" workflow must be implemented by Guzzle HTTP handlers used by a client.

## force_ip_resolve

Summary
Set to "v4" if you want the HTTP handlers to use only ipv4 protocol or "v6" for ipv6 protocol.

Types
string

Default
null

Constant
`GuzzleHttp\RequestOptions::FORCE_IP_RESOLVE`

```php
// Force ipv4 protocol
$client->request('GET', '/foo', ['force_ip_resolve' => 'v4']);

// Force ipv6 protocol
$client->request('GET', '/foo', ['force_ip_resolve' => 'v6']);
```

> [!NOTE]
> This setting must be supported by the HTTP handler used to send a request. `force_ip_resolve` is currently only supported by the built-in cURL and stream handlers.

## form_params

Summary
Used to send an `application/x-www-form-urlencoded` POST request.

Types
array

Constant
`GuzzleHttp\RequestOptions::FORM_PARAMS`

Array mapping form field names to scalar, `null`, or nested array values. Values are serialized with PHP's `http_build_query()`. Sets the Content-Type header to application/x-www-form-urlencoded when no Content-Type header is already present.

```php
$client->request('POST', '/post', [
    'form_params' => [
        'foo' => 'bar',
        'baz' => ['hi', 'there!']
    ]
]);
```

> [!NOTE]
> `form_params` cannot be used with the `multipart` option. You will need to use one or the other. Use `form_params` for `application/x-www-form-urlencoded` requests, and `multipart` for `multipart/form-data` requests.
>
> This option cannot be used with `body`, `multipart`, or `json`

## headers

Summary
Array keyed by header names to add to the request. List-style header arrays are rejected. PHP stores numeric-string header names as integer keys; when such keys are accepted, Guzzle casts header keys back to strings while applying them. Each value is a string or non-empty array of strings representing the header field values.

Types
- array
- null

Defaults
None

Constant
`GuzzleHttp\RequestOptions::HEADERS`

```php
// Set various headers on a request
$client->request('GET', '/get', [
    'headers' => [
        'User-Agent' => 'testing/1.0',
        'Accept'     => 'application/json',
        'X-Foo'      => ['Bar', 'Baz']
    ]
]);
```

Headers may be added as default options when creating a client. When headers are used as default options, they are only applied if the request being created does not already contain the specific header. This includes both requests passed to the client in the `send()` and `sendAsync()` methods, and requests created by the client (e.g., `request()` and `requestAsync()`).

```php
$client = new GuzzleHttp\Client(['headers' => ['X-Foo' => 'Bar']]);

// Will send a request with the X-Foo header.
$client->request('GET', '/get');

// Sets the X-Foo header to "test", which prevents the default header
// from being applied.
$client->request('GET', '/get', ['headers' => ['X-Foo' => 'test']]);

// Will disable adding in default headers.
$client->request('GET', '/get', ['headers' => null]);

// Will not overwrite the X-Foo header because it is in the message.
use GuzzleHttp\Psr7\Request;
$request = new Request('GET', 'http://foo.com', ['X-Foo' => 'test']);
$client->send($request);

// Will overwrite the X-Foo header with the request option provided in the
// send method.
use GuzzleHttp\Psr7\Request;
$request = new Request('GET', 'http://foo.com', ['X-Foo' => 'test']);
$client->send($request, ['headers' => ['X-Foo' => 'overwrite']]);
```

## http_errors

Summary
Set to `false` to disable throwing exceptions on an HTTP protocol errors (i.e., 4xx and 5xx responses). Exceptions are thrown by default when HTTP protocol errors are encountered.

Types
bool

Default
`true`

Constant
`GuzzleHttp\RequestOptions::HTTP_ERRORS`

```php
$client->request('GET', '/status/500');
// Throws a GuzzleHttp\Exception\ServerException

$res = $client->request('GET', '/status/500', ['http_errors' => false]);
echo $res->getStatusCode();
// 500
```

> [!WARNING]
> This option only has an effect if your handler has the `GuzzleHttp\Middleware::httpErrors` middleware. This middleware is added by default when a client is created with no handler, and is added by default when creating a handler with `GuzzleHttp\HandlerStack::create`.

## idn_conversion

Summary
Internationalized Domain Name (IDN) support.

Types
- bool
- int
- null

Default
`false`

Constant
`GuzzleHttp\RequestOptions::IDN_CONVERSION`

```php
$client->request('GET', 'https://яндекс.рф');
// яндекс.рф is translated to xn--d1acpjx3f.xn--p1ai before passing it to the handler

$res = $client->request('GET', 'https://яндекс.рф', ['idn_conversion' => false]);
// The domain part (яндекс.рф) stays unmodified
```

Enables/disables IDN support, can also be used for precise control by combining `IDNA_*` constants (except `IDNA_ERROR_*`), see the `$options` parameter in the [idn_to_ascii()](https://www.php.net/manual/en/function.idn-to-ascii.php) documentation for more details. Pass `false` or `null` to disable IDN conversion.

## json

Summary
The `json` option is used to easily upload JSON encoded data as the body of a request. A Content-Type header of `application/json` will be added if no Content-Type header is already present on the message. An Accept header is not added automatically; pass one explicitly if the server requires JSON response content negotiation.

Types
Any PHP type that can be operated on by PHP's `json_encode()` function.

Default
None

Constant
`GuzzleHttp\RequestOptions::JSON`

```php
$response = $client->request('PUT', '/put', ['json' => ['foo' => 'bar']]);
```

Here's an example of using the `tap` middleware to see what request is sent over the wire.

```php
use GuzzleHttp\Middleware;

// Create a middleware that echoes parts of the request.
$tapMiddleware = Middleware::tap(function ($request) {
    echo $request->getHeaderLine('Content-Type');
    // application/json
    echo $request->getBody();
    // {"foo":"bar"}
});

// The $handler variable is the handler passed in the
// options to the client constructor.
$response = $client->request('PUT', '/put', [
    'json'    => ['foo' => 'bar'],
    'handler' => $tapMiddleware($handler)
]);
```

> [!NOTE]
> This request option does not support customizing the Content-Type header or any of the options from PHP's [json_encode()](http://www.php.net/manual/en/function.json-encode.php) function. If you need to customize these settings, then you must pass the JSON encoded data into the request yourself using the `body` request option and you must specify the correct Content-Type header using the `headers` request option.
>
> This option cannot be used with `body`, `form_params`, or `multipart`

## multipart

Summary
Sets the body of the request to a `multipart/form-data` form.

Types
array

Constant
`GuzzleHttp\RequestOptions::MULTIPART`

The value of `multipart` is an array of part arrays, each containing the following key value pairs:

- `name`: (string|int, required) the form field name
- `contents`: (mixed, required) Any non-array value accepted by `GuzzleHttp\Psr7\Utils::streamFor()`, including strings, resources, streams, iterators, closures, and invokable objects. Arrays are expanded as nested multipart fields; `headers` and `filename` cannot be used when `contents` is an array.
- `headers`: (array) Optional array of custom string header values to use with the form element.
- `filename`: (string) Optional string to send as the filename in the part.

```php
use GuzzleHttp\Psr7;

$client->request('POST', '/post', [
    'multipart' => [
        [
            'name'     => 'foo',
            'contents' => 'data',
            'headers'  => ['X-Baz' => 'bar']
        ],
        [
            'name'     => 'baz',
            'contents' => Psr7\Utils::tryFopen('/path/to/file', 'r')
        ],
        [
            'name'     => 'qux',
            'contents' => Psr7\Utils::tryFopen('/path/to/file', 'r'),
            'filename' => 'custom_filename.txt'
        ],
    ]
]);
```

> [!NOTE]
> `multipart` cannot be used with the `form_params` option. You will need to use one or the other. Use `form_params` for `application/x-www-form-urlencoded` requests, and `multipart` for `multipart/form-data` requests.
>
> This option cannot be used with `body`, `form_params`, or `json`

## on_headers

Summary
A callable that is invoked when the HTTP headers of the response have been received but the body has not yet begun to download.

Types
- callable

Constant
`GuzzleHttp\RequestOptions::ON_HEADERS`

The callable accepts a `Psr\Http\Message\ResponseInterface` object and the corresponding `Psr\Http\Message\RequestInterface` object. If an exception is thrown by the callable, then the promise associated with the response will be rejected with a `GuzzleHttp\Exception\ResponseException` that wraps the exception that was thrown.

You may need to know what headers and status codes were received before data can be written to the sink.

```php
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

// Reject responses that are greater than 1024 bytes.
$client->request('GET', 'http://httpbin.org/stream/1024', [
    'on_headers' => function (ResponseInterface $response, RequestInterface $request) {
        if ($response->getHeaderLine('Content-Length') > 1024) {
            throw new \Exception('The file is too big!');
        }
    }
]);
```

> [!NOTE]
> When writing HTTP handlers, the `on_headers` function must be invoked before writing data to the body of the response.

## on_stats

Summary
`on_stats` allows you to get access to transfer statistics of a request and access the lower level transfer details of the handler associated with your client. `on_stats` is a callable that is invoked when a handler has finished sending a request. The callback is invoked with transfer statistics about the request, the response received, or the error encountered. Included in the data is the total amount of time taken to send the request.

Types
- callable

Constant
`GuzzleHttp\RequestOptions::ON_STATS`

The callable accepts a `GuzzleHttp\TransferStats` object. Built-in handlers reject non-callable `on_stats` values before starting the transfer.

Exceptions thrown by `on_stats` are not wrapped by Guzzle and may escape from the handler wait path. With the built-in cURL handlers, native cURL handles are released before `on_stats` is invoked. cURL handlers emit `on_stats` per low-level transfer attempt, so retries may invoke it more than once for one logical request.

```php
use GuzzleHttp\TransferStats;

$client = new GuzzleHttp\Client();

$client->request('GET', 'http://httpbin.org/stream/1024', [
    'on_stats' => function (TransferStats $stats) {
        echo $stats->getEffectiveUri() . "\n";
        echo $stats->getTransferTime() . "\n";
        var_dump($stats->getHandlerStats());

        // You must check if a response was received before using the
        // response object.
        if ($stats->hasResponse()) {
            echo $stats->getResponse()->getStatusCode();
        } else {
            // Error data is handler specific. You will need to know what
            // type of error data your handler uses before using this
            // value.
            var_dump($stats->getHandlerErrorData());
        }
    }
]);
```

## progress

Summary
Defines a function to invoke when transfer progress is made.

Types
- callable

Default
None

Constant
`GuzzleHttp\RequestOptions::PROGRESS`

The function accepts the following positional arguments:

- the total number of bytes expected to be downloaded, zero if unknown
- the number of bytes downloaded so far
- the total number of bytes expected to be uploaded
- the number of bytes uploaded so far

With the built-in cURL handlers, returning a truthy value aborts the transfer and rejects the request promise with a `GuzzleHttp\Exception\ResponseException` when a response is available, or a `GuzzleHttp\Exception\RequestException` otherwise. If the callable throws, the built-in cURL handlers abort the transfer and reject the promise with the same response-aware classification while wrapping the thrown exception. The built-in stream handler treats progress callbacks as notifications only and ignores return values.

```php
// Send a GET request to /get?foo=bar
$result = $client->request(
    'GET',
    '/',
    [
        'progress' => function(
            int $downloadTotal,
            int $downloadedBytes,
            int $uploadTotal,
            int $uploadedBytes
        ) {
            //do something
        },
    ]
);
```

## protocols

Summary
Allowed URI schemes for request transfers.

Types
- non-empty array

Default
`['http', 'https']`

Constant
`GuzzleHttp\RequestOptions::PROTOCOLS`

This option accepts a non-empty array of strings. Built-in handlers accept only
the case-sensitive values `http` and `https`. It applies to each request
transfer Guzzle sends, including redirect requests that reuse the same request
options.

```php
$client->request('GET', 'https://example.com', [
    'protocols' => ['https'],
]);
```

> [!NOTE]
> `protocols` replaces raw cURL `CURLOPT_PROTOCOLS` when restricting request
> schemes. Raw cURL options that conflict with Guzzle-managed request handling
> are rejected. Use Guzzle request options instead when configuring the request
> method, URI, body, headers, timeouts, redirects, proxy, TLS, progress,
> debug output, sinks, cookies, and protocols. Redirect middleware also
> validates redirect targets with `allow_redirects.protocols` before
> creating each redirect request.

## proxy

Summary
Pass a string to specify an HTTP proxy, or an array to specify different proxies for different protocols.

Types
- string
- array

Default
None

Constant
`GuzzleHttp\RequestOptions::PROXY`

Pass a string to specify a proxy for all protocols.

```php
$client->request('GET', '/', ['proxy' => 'http://localhost:8125']);
```

Pass an associative array to specify HTTP proxies for specific URI schemes (i.e., "http", "https"). Provide a `no` key value pair as a comma-delimited string or an array of entries that should not be proxied to. No-proxy entries may include host names, host-and-port pairs, IP literals, or IP CIDR rules. The wildcard entry `*` matches all hosts, and a port-specific wildcard such as `*:80` matches any host using effective port `80`. Domain entries are matched case-insensitively. A bare domain such as `example.com` matches both `example.com` and `foo.example.com`; a leading-dot domain such as `.example.com` matches subdomains but not `example.com` itself. No-proxy entries may include ports, for example `example.com:8080` or `[::1]:8080`. IP literals are normalized before matching, so equivalent IPv6 spellings such as `::1` and `0:0:0:0:0:0:0:1` match. CIDR entries match IP literals only and are not port-specific. The `http`, `https`, and `no` entries may be set to `null` to leave that entry unconfigured.

> [!NOTE]
> Guzzle will automatically populate this value with your environment's `NO_PROXY` environment variable. However, when providing a `proxy` request option, it is up to you to provide the `no` value from the `NO_PROXY` environment variable.

Custom handlers can use `GuzzleHttp\ProxyOptions::resolve()` to apply Guzzle-compatible proxy selection. The helper resolves the documented `proxy` request option shape, including scheme-specific proxy entries and `no` exclusion rules. Handlers remain responsible for translating the selected proxy string into their transport-specific configuration.

```php
use GuzzleHttp\ProxyOptions;

$selection = ProxyOptions::resolve($request->getUri(), $options['proxy'] ?? null);

if ($selection->hasProxy()) {
    $proxy = $selection->getProxy();
    // Configure the transport to use $proxy.
} elseif ($selection->shouldDisableProxy()) {
    // Disable transport-level default or environment proxy behavior.
}
```

```php
$client->request('GET', '/', [
    'proxy' => [
        'http'  => 'http://localhost:8125', // Use this proxy with "http"
        'https' => 'http://localhost:9124', // Use this proxy with "https",
        'no' => ['.mit.edu', 'foo.com', 'example.com:8080', '::1', '[fd00::1]:8080', '10.0.0.0/8', 'fd00::/8'] // Don't use a proxy with these
    ]
]);
```

> [!NOTE]
> You can provide proxy URLs that contain a scheme, username, and password. For example, `"http://username:password@192.168.16.1:10"`.

> [!NOTE]
> When sending HTTPS requests, or requests explicitly tunneled with
> `CURLOPT_HTTPPROXYTUNNEL`, through an authenticated HTTP or HTTPS proxy,
> libcurl versions before 8.19.0 could reuse an existing proxy tunnel even
> after proxy credentials changed. Guzzle avoids that reuse on affected libcurl
> versions when the proxy URL is configured through the `proxy` option,
> including cURL proxy credential options supplied through the `curl` request
> option. Raw `CURLOPT_PROXY` is rejected; use the `proxy` request option for
> the proxy URL. Custom proxy authentication sent with `CURLOPT_PROXYHEADER`
> also avoids tunnel reuse because libcurl does not include those header values
> in its connection matching. Fixed libcurl versions keep normal connection
> reuse behavior for proxy URL and cURL proxy credential options. Advanced
> users can still control cURL connection reuse explicitly with the `curl`
> request option and `CURLOPT_FRESH_CONNECT` or `CURLOPT_FORBID_REUSE`; raw
> `CURLOPT_PROXYTYPE` is respected when deciding whether a scheme-less `proxy`
> option value is an HTTP(S) proxy.

## query

Summary
Array of query string values or query string to add to the request.

Types
- array
- string

Default
None

Constant
`GuzzleHttp\RequestOptions::QUERY`

```php
// Send a GET request to /get?foo=bar
$client->request('GET', '/get', ['query' => ['foo' => 'bar']]);
```

Query strings specified in the `query` option will overwrite all query string values supplied in the URI of a request.

```php
// Send a GET request to /get?foo=bar
$client->request('GET', '/get?abc=123', ['query' => ['foo' => 'bar']]);
```

## read_timeout

Summary
Number of seconds to use when reading a streamed body. Positive values below `0.001` seconds are rejected by the built-in stream handler.

Types
- int
- float

Default
Defaults to the value of the `default_socket_timeout` PHP ini setting

Constant
`GuzzleHttp\RequestOptions::READ_TIMEOUT`

The timeout applies to individual read operations on a streamed body (when the `stream` option is enabled).

When a read on the streamed PSR-7 body times out, `read()` throws
`GuzzleHttp\Psr7\Exception\TimeoutException`. This PSR-7 class sits outside the
`GuzzleHttp\Exception\GuzzleException` hierarchy, so catch it explicitly. If you
detach the body and read the raw PHP stream resource instead, functions such as
`fgets()` return `false` on timeout, following PHP's stream semantics.

```php
$response = $client->request('GET', '/stream', [
    'stream' => true,
    'read_timeout' => 10,
]);

$body = $response->getBody();

// Throws GuzzleHttp\Psr7\Exception\TimeoutException on timeout
$data = $body->read(1024);

// Returns false on timeout (raw stream resource)
$line = fgets($body->detach());
```

## request_factory

Summary
PSR-17 request factory used when Guzzle creates requests.

Types
`Psr\Http\Message\RequestFactoryInterface`

Default
`GuzzleHttp\Psr7\HttpFactory`

Constant
`GuzzleHttp\RequestOptions::REQUEST_FACTORY`

```php
$factory = new \GuzzleHttp\Psr7\HttpFactory();

$client->request('GET', '/get', [
    'request_factory' => $factory,
]);
```

This option can be set on a client or per request. It affects request-side object creation only and does not affect response implementations returned by handlers.

> [!NOTE]
> This option only affects requests created by `request()`, `requestAsync()`, and shortcut methods such as `get()` and `post()`. Requests passed to `send()`, `sendAsync()`, or `sendRequest()` are used as provided.

## retries

Summary
Current retry count used by `GuzzleHttp\Middleware::retry()`.

Types
int

Default
`0` when retry middleware is used

Constant
No `RequestOptions` constant is defined for this middleware-specific option.

The retry middleware initializes this option to `0` before the first attempt and increments it before each retry. Applications may seed it on a per-request basis when using the retry middleware.

## stream_factory

Summary
PSR-17 stream factory used when Guzzle creates request body streams.

Types
`Psr\Http\Message\StreamFactoryInterface`

Default
`GuzzleHttp\Psr7\HttpFactory`

Constant
`GuzzleHttp\RequestOptions::STREAM_FACTORY`

```php
$factory = new \GuzzleHttp\Psr7\HttpFactory();

$client->request('POST', '/post', [
    'body' => 'payload',
    'stream_factory' => $factory,
]);
```

This option can be set on a client or per request. It is used when Guzzle converts supported `body`, `form_params`, or `json` request option values into `Psr\Http\Message\StreamInterface` instances, and when redirect handling resets a request body. Request bodies that already implement `Psr\Http\Message\StreamInterface` are used as provided.

> [!NOTE]
> This option affects request-side body stream creation only. It does not affect response body implementations returned by handlers, response sinks, callable or iterator bodies, or multipart internals.

## uri_factory

Summary
PSR-17 URI factory used when Guzzle creates URI objects from strings.

Types
`Psr\Http\Message\UriFactoryInterface`

Default
`GuzzleHttp\Psr7\HttpFactory`

Constant
`GuzzleHttp\RequestOptions::URI_FACTORY`

```php
$factory = new \GuzzleHttp\Psr7\HttpFactory();

$client = new GuzzleHttp\Client([
    'base_uri' => 'https://api.example.com',
    'uri_factory' => $factory,
]);
```

This option can be set on a client or per request. It is used for string request URI values, string `base_uri` values, and redirect `Location` header parsing when redirects are enabled. URI objects implementing `Psr\Http\Message\UriInterface` are used as provided.

> [!NOTE]
> This option affects request-side URI creation only. It does not affect response implementations returned by handlers. `GuzzleHttp\Client::sendRequest()` still returns redirect responses as-is for PSR-18 compliance.

## sink

Summary
Specify where the body of a response will be saved.

Types
- string (path to file on disk)
- `fopen()` resource
- `Psr\Http\Message\StreamInterface`

Default
PHP temp stream

Constant
`GuzzleHttp\RequestOptions::SINK`

Pass a string to specify the path to a file that will store the contents of the response body:

```php
$client->request('GET', '/stream/20', ['sink' => '/path/to/file']);
```

Pass a resource returned from `fopen()` to write the response to a PHP stream:

```php
$resource = \GuzzleHttp\Psr7\Utils::tryFopen('/path/to/file', 'w');
$client->request('GET', '/stream/20', ['sink' => $resource]);
```

Pass a `Psr\Http\Message\StreamInterface` object to stream the response body to an open PSR-7 stream.

```php
$resource = \GuzzleHttp\Psr7\Utils::tryFopen('/path/to/file', 'w');
$stream = \GuzzleHttp\Psr7\Utils::streamFor($resource);
$client->request('GET', '/stream/20', ['sink' => $stream]);
```

With Guzzle's built-in cURL and PHP stream handlers, non-streaming responses use the sink stream as the response body.

If `sink` is a string path, Guzzle opens the file and owns that stream.

If `sink` is a PHP resource, the caller owns the resource and is responsible for closing it. Closing or destroying the response body detaches Guzzle's wrapper without closing the original resource.

If `sink` is a `Psr\Http\Message\StreamInterface`, Guzzle uses that stream object as-is and its own `close()` behavior applies.

## ssl_key

Summary
Specify the path to a file containing a private SSL key. PEM is the default private key format. If a password is required, then set to an array containing the path to the SSL key in the first array element followed by the password required for the key in the second element. A `null` password is treated the same as omitting it. Use [`ssl_key_type`](#ssl_key_type) to specify another supported key format.

Types
- string
- array

Default
None

Constant
`GuzzleHttp\RequestOptions::SSL_KEY`

> [!NOTE]
> With the stream handler, `cert` and `ssl_key` must use the same passphrase when both options specify one because PHP streams expose only one SSL context passphrase.

> [!NOTE]
> TLS client key options remain active during redirects. See [Cross-Origin Redirects](#cross-origin-redirects) for details.

## ssl_key_type

Summary
Specify the SSL private key file type.

Types
- string

Default
`PEM`

Constant
`GuzzleHttp\RequestOptions::SSL_KEY_TYPE`

```php
$client->request('GET', '/', [
    'ssl_key' => '/path/client.key',
    'ssl_key_type' => 'DER',
]);
```

The cURL handler passes this value to `CURLOPT_SSLKEYTYPE`. Supported values depend on libcurl and its TLS backend.

> [!NOTE]
> The stream handler supports only `PEM` private key files.

## stream

Summary
Set to `true` to stream a response rather than download it all up-front.

Types
bool

Default
`false`

Constant
`GuzzleHttp\RequestOptions::STREAM`

```php
$response = $client->request('GET', '/stream/20', ['stream' => true]);
// Read bytes off of the stream until the end of the stream is reached
$body = $response->getBody();
while (!$body->eof()) {
    echo $body->read(1024);
}
```

> [!NOTE]
> Streaming response support must be implemented by the HTTP handler used by a client. This option might not be supported by every HTTP handler, but the interface of the response object remains the same regardless of whether or not it is supported by the handler.

## stream_context

Summary
PHP stream context options to merge into the context used by the built-in stream handler.

Types
array

Default
None

Constant
No `RequestOptions` constant is defined for this handler-specific option.

This option is only supported by the built-in stream handler. Built-in cURL handlers reject this option because cURL does not use PHP stream contexts.

## synchronous

Summary
Set to true to inform HTTP handlers that you intend on waiting on the response. This can be useful for optimizations.

Types
bool

Default
none

Constant
`GuzzleHttp\RequestOptions::SYNCHRONOUS`

## verify

Summary
Describes the SSL certificate verification behavior of a request.

> - Set to `true` to enable SSL certificate verification and use the default CA bundle provided by operating system.
> - Set to `false` to disable certificate verification (this is insecure!).
> - Set to a string to provide the path to a CA bundle to enable verification using a custom certificate.

Types
- bool
- string

Default
`true`

Constant
`GuzzleHttp\RequestOptions::VERIFY`

```php
// Use the system's CA bundle (this is the default setting)
$client->request('GET', '/', ['verify' => true]);

// Use a custom SSL certificate on disk.
$client->request('GET', '/', ['verify' => '/path/to/cert.pem']);

// Disable validation entirely (don't do this!).
$client->request('GET', '/', ['verify' => false]);
```

If you do not need a specific certificate bundle, then Mozilla provides a commonly used CA bundle which can be downloaded [here](https://curl.haxx.se/ca/cacert.pem) (provided by the maintainer of cURL). Once you have a CA bundle available on disk, you can set the "openssl.cafile" PHP ini setting to point to the path to the file, allowing you to omit the "verify" request option. Much more detail on SSL certificates can be found on the [cURL website](http://curl.haxx.se/docs/sslcerts.html).

## timeout

Summary
Number of seconds to use as the total timeout of the request. Use `0` to wait indefinitely (the default behavior). Positive values below `0.001` seconds are rejected by the built-in handlers.

Types
- int
- float

Default
`0`

Constant
`GuzzleHttp\RequestOptions::TIMEOUT`

```php
// Timeout if the request does not complete in 3.14 seconds.
$client->request('GET', '/delay/5', ['timeout' => 3.14]);
```

Built-in handlers use the most specific timeout exception they can determine
from the underlying transfer. Connect timeouts throw
`GuzzleHttp\Exception\ConnectTimeoutException`, which extends
`ConnectException`. Other timeouts before a response is received throw
`GuzzleHttp\Exception\NetworkTimeoutException`. Timeouts after a response is
received throw `GuzzleHttp\Exception\ResponseTimeoutException`, which extends
`GuzzleHttp\Exception\ResponseTransferException` and exposes the response.

Timeouts that originate from a slow PSR-7 stream follow the same phase rule, with
one exception: a slow `sink` write, or a request body that stalls after response
headers are received, throws a plain `GuzzleHttp\Exception\ResponseException`
rather than `ResponseTimeoutException`. In every case the original
`GuzzleHttp\Psr7\Exception\TimeoutException` is available via `getPrevious()`.

## version

Summary
Protocol version to attempt to use with the request.

Types
string, int, float

Default
`1.1`

Constant
`GuzzleHttp\RequestOptions::VERSION`

```php
// Force HTTP/1.0
$request = $client->request('GET', '/get', ['version' => 1.0]);

// Attempt HTTP/2 with the cURL handler
$request = $client->request('GET', 'https://example.com', ['version' => 2.0]);

// Attempt HTTP/3 with the cURL handler
$request = $client->request('GET', 'https://example.com', ['version' => 3.0]);
```

Guzzle defaults to HTTP/1.1. Set `version` when you want a request to use or attempt a different HTTP protocol version. Guzzle does not opt requests into HTTP/2 or HTTP/3 automatically just because the installed cURL stack supports them.

The built-in stream handler supports only `1.0` and `1.1`. The built-in cURL handler supports `1.0`, `1.1`, `2.0`, and `3.0`, but HTTP/2 and HTTP/3 depend on the PHP cURL extension and the linked runtime libcurl capabilities.

Empty or malformed `version` values are rejected before the request is sent. If the value is well-formed but the selected handler cannot use it, the transfer fails with `GuzzleHttp\Exception\RequestException`. For example, the stream handler rejects HTTP/2 and HTTP/3, the cURL handler rejects HTTP/2 when libcurl does not report HTTP/2 support, and the cURL handler rejects HTTP/3 when the installed cURL stack does not report HTTP/3 support.

For cURL requests, `version` is converted to Guzzle-managed cURL options. Use this request option instead of passing raw `CURLOPT_HTTP_VERSION`; built-in cURL handlers reject raw cURL options that conflict with Guzzle-managed protocol handling.

HTTP/2 uses libcurl's `CURL_HTTP_VERSION_2_0`. HTTP/3 uses libcurl's `CURL_HTTP_VERSION_3`, not `CURL_HTTP_VERSION_3ONLY`. These modes ask libcurl to attempt the requested protocol, but they are not strict modes: libcurl may use a lower HTTP version when negotiation or connection setup falls back. The response protocol version can therefore be lower than the `version` value you requested. Guzzle does not currently expose libcurl's strict HTTP/3-only mode.

HTTP/3 support requires PHP to expose cURL's HTTP/3 constants, runtime libcurl 7.66.0 or higher, and runtime libcurl reporting the `CURL_VERSION_HTTP3` feature. A libcurl version number is not enough by itself: libcurl must also be built with HTTP/3 and QUIC support, commonly through an HTTP/3 backend such as ngtcp2 with nghttp3 or quiche.

A request configured with `version => 3.0` must pass HTTP/3 support checks even if it uses a proxy. If a proxy is actually selected, Guzzle does not try HTTP/3 through the proxy; it sends the transfer as HTTP/2 when available, otherwise HTTP/1.1. A matching proxy `no` rule makes the request direct, so HTTP/3 can still be attempted.

For HTTPS cURL requests, Guzzle sets a minimum of TLS 1.2 by default. That minimum also applies when HTTP/2 or HTTP/3 is requested, because cURL may fall back to a TLS-based HTTP/1.1 or HTTP/2 connection. If you set `crypto_method` to TLS 1.3, Guzzle keeps that stricter setting when the cURL stack exposes TLS 1.3 configuration. Raw `CURLOPT_SSLVERSION` values passed through the `curl` option are rejected because TLS version handling is managed by Guzzle.
