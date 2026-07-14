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

- strict: (bool, default=false) Set to true to use strict redirects. Strict RFC compliant redirects mean that POST redirect requests are sent as POST requests vs. doing what most browsers do which is redirect POST requests with GET requests. The RFC 10008 QUERY method keeps its method and body across non-strict 301 and 302 redirects, matching the 307 and 308 behavior that already applies to every method, and a 303 redirect is followed with a body-less GET.

- referer: (bool, default=false) Set to true to enable adding the Referer header when redirecting.

- protocols: (non-empty array of strings, default=`['http', 'https']`) Specifies which protocols are allowed for redirect requests. Redirect matching is case-sensitive; use `http` and `https`.

- on_redirect: (callable) PHP callable that is invoked when a redirect is encountered. The callable is invoked with the original request, the redirect response that was received, and the effective URI. Any return value from the on_redirect function is ignored.

- track_redirects: (bool) When set to `true`, each redirected URI and status code encountered will be tracked in the `X-Guzzle-Redirect-History` and `X-Guzzle-Redirect-Status-History` headers respectively. All URIs and status codes will be stored in the order which the redirects were encountered.

> [!NOTE]
> When tracking redirects the `X-Guzzle-Redirect-History` header will exclude the initial request's URI and the `X-Guzzle-Redirect-Status-History` header will exclude the final status code. Redirect history is stored in response headers, and those header names are not reserved by Guzzle. If the final response already contains headers with these names, including when no redirect occurs, those values may be server-provided. Do not use these headers as a security boundary. For security-sensitive redirect history, collect values with the `on_redirect` option instead.

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

On cross-origin redirects, Guzzle removes the `Authorization` and `Cookie` headers and clears cURL HTTP authentication options such as `CURLOPT_HTTPAUTH` and `CURLOPT_USERPWD`.

Guzzle does not automatically remove other request options or headers solely because the redirect is cross-origin. This matches curl's redirect model. In particular, TLS client authentication options such as `cert`, `ssl_key`, custom cURL TLS options, and stream context TLS options are not removed automatically on cross-origin redirects.

If TLS client credentials are only trusted for the original origin, disable automatic redirects and handle redirect responses manually, or use separate clients and request options for trusted origins.

> [!NOTE]
> QUERY request bodies can carry sensitive query content. On cross-origin redirects Guzzle removes origin credentials such as the Authorization and Cookie headers, but it does not remove the request body. Disable automatic redirects or use on_redirect if a QUERY body must not be sent to another origin.

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

> [!WARNING]
> This is currently only supported when using the cURL handler. The `ntlm` auth type is deprecated in Guzzle and removed from Guzzle 8. NTLM is also deprecated by curl/libcurl: curl describes NTLM as weak, notes that Microsoft deprecated it and that it does not work over HTTP/2 or HTTP/3, and plans to remove NTLM support in September 2026. In curl 8.20.0 NTLM became opt-in, so some libcurl builds may already lack it. Avoid new NTLM usage. If an existing integration still requires NTLM temporarily, configure cURL HTTP authentication options directly and ensure the selected cURL handler uses a libcurl build with NTLM support.

## body

Summary
The `body` option is used to control the body of an entity enclosing request (e.g., PUT, POST, PATCH).

Types
- string
- `fopen()` resource
- `Psr\Http\Message\StreamInterface`
- callable
- `Iterator`
- object with `__toString()`
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

Values are converted to PSR-7 streams with `GuzzleHttp\Psr7\Utils::streamFor()`. Strings are always used as literal body contents, even when they name a callable. Callable bodies may be closures or invokable objects. Callable arrays are arrays, and arrays are not valid `body` values in Guzzle. Request bodies that already implement `Psr\Http\Message\StreamInterface` are used as provided.

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
Number of seconds to wait while trying to connect to a server. Use `0` to wait 300 seconds (the default behavior).

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
None

Constant
`GuzzleHttp\RequestOptions::CRYPTO_METHOD`

```php
$client->request('GET', '/foo', ['crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT]);
```

> [!NOTE]
> This setting must be set to one of the `STREAM_CRYPTO_METHOD_TLS*_CLIENT` constants. PHP 7.4 or higher is required in order to use TLS 1.3, and cURL 7.34.0 or higher is required in order to specify a crypto method, with cURL 7.52.0 or higher being required to use TLS 1.3.

## crypto_method_max

Summary
A value describing the maximum TLS protocol version to use.

Types
int

Default
None

Constant
`GuzzleHttp\RequestOptions::CRYPTO_METHOD_MAX`

```php
$client->request('GET', '/foo', [
    'crypto_method_max' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
]);
```

> [!NOTE]
> This setting must be set to one of the `STREAM_CRYPTO_METHOD_TLS*_CLIENT`
> constants. It may be combined with `crypto_method` to set an allowed TLS
> version range. The maximum version must be greater than or equal to the
> minimum version. On the stream handler, PHP 7.3 or higher is required to set
> a maximum TLS version, and PHP 7.4 or higher is required to use TLS 1.3.
> cURL 7.54.0 or higher is required to use maximum TLS-version bounds with the
> cURL handler.

## curl

Summary
Raw cURL options to apply when using a built-in cURL handler.

Types
- array

Default
None

Constant
`GuzzleHttp\RequestOptions::CURL`

Except for Guzzle's special `body_as_string` key, the array is keyed by
integer cURL option constants and values are passed to cURL after Guzzle
applies request options. Raw cURL options that conflict with Guzzle-managed
request handling are deprecated.

Raw `CURLOPT_PROXY`, `CURLOPT_NOPROXY`, and `CURLOPT_PRE_PROXY` values must be
strings, and raw `CURLOPT_PROXYTYPE` values must be integers. Guzzle rejects
other types rather than classify a value differently from ext-curl. The checks
run against the final resolved configuration, so the proxy value resolved from
the `proxy` request option must also be a string; an explicit `null` value is
treated as if the option were unset.

Raw cURL options outside the built-in cURL handlers' allow-list are deprecated.
Allow-listing means Guzzle passes the option through without its own
deprecation warning; PHP, libcurl, or the TLS backend may still reject or ignore
an option depending on the runtime. The allow-list is limited to the following
`CURLOPT_*` constants when they are defined by the installed PHP cURL extension:

- `CURLOPT_ADDRESS_SCOPE`
- `CURLOPT_CERTINFO`
- `CURLOPT_CONNECT_TO`
- `CURLOPT_DNS_CACHE_TIMEOUT`
- `CURLOPT_DNS_INTERFACE`
- `CURLOPT_DNS_LOCAL_IP4`
- `CURLOPT_DNS_LOCAL_IP6`
- `CURLOPT_DNS_SERVERS`
- `CURLOPT_DNS_SHUFFLE_ADDRESSES`
- `CURLOPT_ENCODING`
- `CURLOPT_FORBID_REUSE`
- `CURLOPT_FRESH_CONNECT`
- `CURLOPT_HAPPY_EYEBALLS_TIMEOUT_MS`
- `CURLOPT_HTTPAUTH`
- `CURLOPT_HTTPPROXYTUNNEL`
- `CURLOPT_INTERFACE`
- `CURLOPT_LOCALPORT`
- `CURLOPT_LOCALPORTRANGE`
- `CURLOPT_LOW_SPEED_LIMIT`
- `CURLOPT_LOW_SPEED_TIME`
- `CURLOPT_MAXAGE_CONN`
- `CURLOPT_MAXCONNECTS`
- `CURLOPT_MAXLIFETIME_CONN`
- `CURLOPT_PREREQFUNCTION`
- `CURLOPT_PROXYHEADER`
- `CURLOPT_PROXYUSERPWD`
- `CURLOPT_RESOLVE`
- `CURLOPT_SSL_CIPHER_LIST`
- `CURLOPT_SSL_EC_CURVES`
- `CURLOPT_TCP_FASTOPEN`
- `CURLOPT_TCP_KEEPALIVE`
- `CURLOPT_TCP_KEEPIDLE`
- `CURLOPT_TCP_KEEPINTVL`
- `CURLOPT_TCP_KEEPCNT`
- `CURLOPT_TCP_NODELAY`
- `CURLOPT_TLS13_CIPHERS`
- `CURLOPT_UNIX_SOCKET_PATH`
- `CURLOPT_USERPWD`

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
    User-Agent: GuzzleHttp/7

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
> This option only takes effect when using HTTP/1.1. The HTTP/1.0 and HTTP/2.0 protocols do not support the "Expect: 100-Continue" header. Support for handling the "Expect: 100-Continue" workflow must be implemented by Guzzle HTTP handlers used by a client.

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
The `json` option is used to easily upload JSON encoded data as the body of a request. A Content-Type header of `application/json` will be added if no Content-Type header is already present on the message.

Types
Any PHP type that can be operated on by PHP's `json_encode()` function.

Default
None

Constant
`GuzzleHttp\RequestOptions::JSON`

```php
$response = $client->request('PUT', '/put', ['json' => ['foo' => 'bar']]);
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

## multiplex

Summary
Controls how an HTTP/2 request sent through a built-in cURL handler pursues a shared, multiplexed connection.

Types
- string (one of the `GuzzleHttp\Multiplexing` constants)

Default
None (multiplexing is left to libcurl)

Constant
`GuzzleHttp\RequestOptions::MULTIPLEX`

libcurl multiplexes concurrent HTTP/2 transfers over a single connection whenever a multiplexable connection to the origin already exists, whatever this option is set to. When the option is not set, Guzzle leaves the rest to libcurl too: nothing waits. The modes grade how much further the request goes:

- `Multiplexing::EAGER` - never wait for a connection that is still being established: a burst of requests against a cold origin opens parallel connections.
- `Multiplexing::WAIT` - wait for a pending connection that libcurl considers eligible for multiplexing, normally one to the same origin, and share it. Needs libcurl 7.65.2+ and the `CurlMultiHandler`, and is silently ignored elsewhere. If the connection turns out not to multiplex, waiting requests open their own.
- `Multiplexing::REQUIRE_EAGER` - guarantee a multiplexed protocol or fail loudly, while dialing eagerly. The request is sent with HTTP/2 prior knowledge, so TLS connections offer only `h2` via ALPN and cleartext connections speak HTTP/2 directly; a server limited to HTTP/1.x fails the connection instead of downgrading, and cleartext requests sent through a proxy are rejected. Requires protocol version `2`/`2.0`, a cURL handler, and libcurl 8.14.0+; anything else throws. A cold burst dials connections in parallel, but libcurl still packs later streams onto the first established connection rather than balancing.
- `Multiplexing::REQUIRE_WAIT` - the same guarantees as `Multiplexing::REQUIRE_EAGER`, plus `WAIT`'s waiting; the protocol guarantee holds on both cURL handlers, the waiting only on the `CurlMultiHandler`.

```php
$promises = [];

foreach ($uris as $uri) {
    $promises[] = $client->getAsync($uri, [
        'version' => '2.0',
        'multiplex' => Multiplexing::REQUIRE_EAGER,
    ]);
}
```

> [!NOTE]
> None of the modes is a connection **cap**: once an established HTTP/2 connection has no free streams - servers commonly allow about 100 - additional requests open additional connections regardless of this option.

> [!NOTE]
> libcurl never reuses or coalesces a connection across differing TLS settings (`verify`, custom CA, client certificate/key, pinned public key) or proxy settings, so a verified request can never ride an unverified connection. Because libcurl coalesces HTTP/2 connections, hostnames that resolve to the same address and are covered by the server certificate may share one connection; a server not authoritative for a name can reject it with HTTP/2 `421 Misdirected Request`. Waiting requests share one in-progress connection, so a slow lead connection delays them and is charged against their `timeout`. Only HTTP/2 requests wait; `Multiplexing::EAGER` stops the waiting but does not guarantee separate connections - established multiplex-capable connections are still shared.

> [!NOTE]
> Explicit modes reject deprecated raw cURL options they conflict with: the required family cannot be combined with a raw `CURLOPT_HTTP_VERSION`, `CURLOPT_URL`, or `CURLOPT_FOLLOWLOCATION`, and no explicit mode can be combined with a raw `CURLOPT_PIPEWAIT` on the `CurlMultiHandler`. The required family also rejects final `CURLOPT_HTTPAUTH` masks that permit NTLM, which libcurl retries over HTTP/1.1. The required family validates its cleartext proxy rule against the final cURL configuration, after raw options such as `CURLOPT_PROXY` and `CURLOPT_PRE_PROXY` are applied; only the exact raw `CURLOPT_NOPROXY` wildcard `'*'` disables the primary proxy and pre-proxy there, and raw host-specific patterns are conservatively treated as leaving them active. These rejections are configuration-conflict checks, not remote security checks.

## on_headers

Summary
A callable that is invoked when the HTTP headers of the response have been received but the body has not yet begun to download.

Types
- callable

Constant
`GuzzleHttp\RequestOptions::ON_HEADERS`

The callable accepts a `Psr\Http\Message\ResponseInterface` object. If an exception is thrown by the callable, then the promise associated with the response will be rejected with a `GuzzleHttp\Exception\RequestException` that wraps the exception that was thrown.

You may need to know what headers and status codes were received before data can be written to the sink.

```php
// Reject responses that are greater than 1024 bytes.
$client->request('GET', 'http://httpbin.org/stream/1024', [
    'on_headers' => function (ResponseInterface $response) {
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

The callable accepts a `GuzzleHttp\TransferStats` object.

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

## on_trailers

Summary
A callable that is invoked when the HTTP trailers of the response have been received, after the body has been fully transferred.

Types
- callable

Constant
`GuzzleHttp\RequestOptions::ON_TRAILERS`

The callable accepts an associative array that maps each trailer field name, lowercased and grouped case-insensitively, to an array of values in the order they were received, followed by the `Psr\Http\Message\ResponseInterface` object. The callable is invoked by the built-in cURL handlers exactly once per successful transfer, after the response body has been written to the sink and after the ``on_stats`` callable has run when one is provided, and receives an empty array when the response has no trailers. It is never invoked when a transfer fails. If an exception is thrown by the callable, then the promise associated with the response will be rejected with a `GuzzleHttp\Exception\RequestException` that wraps the exception that was thrown.

```php
// Verify a content checksum delivered after the body.
$client->request('GET', 'https://example.com/stream', [
    'version' => '2.0',
    'on_trailers' => function (array $trailers, ResponseInterface $response) {
        $checksum = $trailers['x-checksum'][0] ?? null;
        if ($checksum === null || !hash_equals($checksum, \GuzzleHttp\Psr7\Utils::hash($response->getBody(), 'sha256'))) {
            throw new \Exception('Response body checksum missing or mismatched!');
        }
    }
]);
```

> [!NOTE]
> Only the built-in cURL handlers can observe trailers; the stream handler
> rejects this option because it cannot observe trailers. Trailer field names
> are lowercased and grouped case-insensitively, matching the HTTP/2 wire
> format. Malformed trailer field lines are discarded before parsing. Trailer
> fields are reported separately from response headers and are never merged
> into the response.

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

```php
// Send a GET request to /get?foo=bar
$result = $client->request(
    'GET',
    '/',
    [
        'progress' => function(
            $downloadTotal,
            $downloadedBytes,
            $uploadTotal,
            $uploadedBytes
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
> trigger deprecation warnings. Prefer request options when configuring the
> request method, URI, body, headers, timeouts, redirects, proxy, TLS,
> progress, debug output, sinks, cookies, and protocols. Redirect middleware
> also validates redirect targets with `allow_redirects.protocols` before
> creating each redirect request.

## proxy

Summary
Pass a string to specify a proxy, or an array to specify different proxies for different protocols.

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

Pass an associative array to specify proxies for specific URI schemes (i.e., "http", "https"). Provide a `no` key value pair as a comma-delimited string or an array to provide a list of host names that should not be proxied to. No-proxy entries may include ports, for example `example.com:8080` or `[::1]:8080`, or IP CIDR ranges, for example `10.0.0.0/8` or `fd00::/8`. The `http`, `https`, and `no` entries may be set to `null` to leave that entry unconfigured.

> [!NOTE]
> Guzzle will automatically populate this value with your environment's `NO_PROXY` environment variable. However, when providing a `proxy` request option, it is up to you to provide the `no` value from the `NO_PROXY` environment variable.

```php
$client->request('GET', '/', [
    'proxy' => [
        'http'  => 'http://localhost:8125', // Use this proxy with "http"
        'https' => 'http://localhost:9124', // Use this proxy with "https",
        'no' => ['.mit.edu', 'foo.com', 'example.com:8080'] // Don't use a proxy with these
    ]
]);
```

> [!NOTE]
> You can provide proxy URLs that contain a scheme, username, and password. For example, `"http://username:password@192.168.16.1:10"`.

HTTPS proxies (an `https://` proxy URL, where the connection to the proxy itself is encrypted) require libcurl 7.52.0 or newer built with HTTPS-proxy support. When libcurl lacks that support, it mishandles such a proxy: versions before 7.50.2 silently downgrade it to a plaintext HTTP proxy, and later versions fail at connect time with a cryptic error. To avoid both outcomes, the cURL handlers reject the request up front. They also reject a proxy URL with a malformed scheme, such as one with junk before the scheme, which libcurl would otherwise downgrade to a plaintext HTTP proxy.

### Proxy environment variables

The cURL handlers always configure libcurl's proxy options explicitly, so libcurl never reads proxy environment variables itself. When the `proxy` request option makes a decision for a request — a string proxy, or an array whose key matches the request scheme (including a `no` list match) — that decision is final, and proxy environment variables are ignored for the request. In particular, the `no_proxy`/`NO_PROXY` environment variables do not bypass an explicitly configured proxy; add the hosts to the option's `no` list instead.

When the `proxy` request option makes no decision for a request, the cURL handlers resolve the proxy from the environment with the same semantics libcurl uses:

1. The lowercase scheme-specific variable, e.g. `https_proxy` for an "https" request. For "http" requests, the uppercase `HTTP_PROXY` variant is never read (see <https://httpoxy.org>, and the Windows note below); for other schemes the uppercase variant is read when the lowercase one is not set.
2. `all_proxy`, then `ALL_PROXY`.

The first variable with a non-empty value ends the lookup; variables set to an empty string are treated as unset, matching libcurl. When an environment proxy is found, the `no_proxy` (or `NO_PROXY`) environment variable is matched against the request by Guzzle. The value is tokenized the way libcurl tokenizes it — entries may be separated by commas or whitespace, and a single leading dot is ignored, so `.example.com` bypasses `example.com` and its subdomains — and each entry is then matched using the same rules as the option's `no` list (including CIDR ranges); a match disables the proxy for the request.

Only the real process environment is consulted, matching libcurl: values injected per-request by the SAPI (e.g. `fastcgi_param` or `SetEnv`) are not read by this handler-level resolution. On Windows, environment variable names are case-insensitive, so the lowercase-only protection for `HTTP_PROXY` is not possible; outside the CLI SAPI on Windows, proxy environment variables are therefore not resolved at all, and the `proxy` request option must be used instead.

Proxy decisions are made once per request, from the request's initial URI. If libcurl-internal redirect following is enabled with the deprecated raw `CURLOPT_FOLLOWLOCATION` cURL option, every redirect hop inherits that decision, and the environment `no_proxy` list is not re-evaluated per hop; use the `allow_redirects` option instead, which re-resolves the proxy for each hop.

Separately from the handler-level resolution above, a `GuzzleHttp\Client` maps the uppercase `HTTP_PROXY` (CLI SAPI only), `HTTPS_PROXY`, and `NO_PROXY` environment variables into a default for the `proxy` request option. The client mapping reads `$_SERVER` first, so it does honor SAPI-provided values such as those set with `fastcgi_param` or `SetEnv`. See [Environment Variables](quickstart.md#environment-variables).

> [!NOTE]
> When sending HTTPS requests, or requests explicitly tunneled with
> `CURLOPT_HTTPPROXYTUNNEL`, through an HTTP or HTTPS proxy, libcurl versions
> before 8.19.0 could reuse an existing proxy tunnel even after proxy
> credentials changed, and 8.19.0 still contains related proxy credential leak
> flaws that were fixed in 8.20.0. Guzzle therefore sections proxy tunnel
> connection reuse by the proxy credentials in effect: requests with the same
> credentials reuse a pooled tunnel, while a change of proxy credentials (from
> the `proxy` option, the environment, or cURL proxy credential options
> supplied through the `curl` request option) is isolated onto its own
> connections. Anonymous tunnels are sectioned apart from authenticated ones,
> so an unauthenticated request never rides an authenticated tunnel. Raw
> `CURLOPT_PROXY` supplied through the `curl` request option is deprecated but
> still honored and participates in the same sectioning. A non-empty custom
> proxy authentication value sent with `CURLOPT_PROXYHEADER` is always sectioned
> because libcurl cannot key connection reuse on those header values. On libcurl
> 8.20.0 and newer, credential sectioning for option-supplied credentials is
> left to libcurl's own credential-aware connection matching.
>
> SOCKS proxies authenticate the connection itself rather than a CONNECT
> tunnel, and libcurl versions before 7.69.0 do not compare SOCKS credentials
> when matching a pooled connection for reuse, so on those versions Guzzle
> sections every SOCKS-proxied request by its proxy and credential state —
> plain `http://` targets and credential-less requests included. From libcurl
> 7.69.0, SOCKS credential matching is left to libcurl.
>
> Sectioning has a cost in mixed workloads: changing the proxy credentials in
> use discards the idle pooled connections held for the previous credentials,
> which also drops unrelated direct keep-alive connections pooled alongside
> them, and alternating anonymous and authenticated traffic through the same
> proxy repeats that cost on each switch. Advanced users can still control cURL
> connection reuse explicitly with the `curl` request option and
> `CURLOPT_FRESH_CONNECT` or `CURLOPT_FORBID_REUSE`; raw `CURLOPT_PROXYTYPE` is
> respected when deciding whether a scheme-less `proxy` option value is an
> HTTP(S) proxy.

A first-class `Proxy-Authorization` request header is proxy-scoped rather than
origin-scoped, and the cURL handlers never generate its values in cURL's origin
header list (`CURLOPT_HTTPHEADER`). On libcurl 7.37.0 and newer
built with proxy header separation support (the `CURLOPT_PROXYHEADER`,
`CURLOPT_HEADEROPT`, and `CURLHEADER_SEPARATE` PHP constants), the values are
configured in libcurl's proxy-only header channel (`CURLOPT_PROXYHEADER`) with
separate proxy and origin header handling, and libcurl decides whether the
proxy-only list is used for the transfer. The configured `CURLOPT_PROXYHEADER`
value may therefore be present for a direct or SOCKS transfer, but it is not
transmitted to the origin. When libcurl routes the request through an HTTP or
HTTPS proxy, the credential authenticates the proxy and participates in the
proxy tunnel sectioning described above. The header is not bound to one proxy
identity, so a routing change can offer it to a different proxy. Guzzle also
enables separate proxy/origin header handling for CONNECT tunnels through an
effective HTTP/HTTPS proxy, whether or not a proxy header is configured.
An empty value is represented as an explicit empty proxy header, which can
suppress proxy authorization that libcurl would otherwise generate from proxy
URL userinfo, but it is not treated as a credential for connection sectioning.

On older libcurl, or a build missing those constants, a first-class value fails
before cURL initialization and network I/O when the final route may use an HTTP
or HTTPS proxy, instead of leaving the value in an origin-bound channel. A route
known to be direct, bypassed by Guzzle or the exact raw `CURLOPT_NOPROXY`
wildcard `*`, or sent through a known SOCKS proxy safely omits the value and
continues. Other raw route configurations are treated conservatively. Replacing
the generated headers with a deprecated raw `CURLOPT_HTTPHEADER` value
suppresses the managed values along with every other generated header and
remains outside this rule: deprecated raw origin-header lists stay
caller-controlled and can defeat the managed guarantee. Proxy credential
sectioning remains tunnel-focused: non-tunneled HTTPS-proxy TLS credential
behavior is not expanded by this change.

The stream handler omits first-class `Proxy-Authorization` values while building
the request context, so direct and bypassed routes cannot receive them. Once it
selects a proxy, it accepts exactly one value, validates it, and adds one
canonical proxy authorization line for PHP's stream wrapper. The first-class
value is authoritative over Basic credentials in proxy URL userinfo, including
when the value is empty, so the physical request never carries both fields.
Multiple values and values containing a carriage return or line feed are
rejected before a stream is created. Deprecated raw `stream_context` overrides
(`http.header`, `http.proxy`, and `http.follow_location`) remain
caller-controlled and outside the managed guarantee. A raw `http.proxy`
override is rejected when the selected proxy generated a proxy authorization
line, because changing the proxy afterward could make that line origin-bound;
unauthenticated raw proxy overrides remain allowed. Raw `follow_location` can
cause PHP-internal redirects that never re-enter Guzzle, and a raw `http.header`
replacement suppresses the managed header along with every other generated
header.

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
Number of seconds to use when reading a streamed body

Types
- int
- float

Default
Defaults to the value of the `default_socket_timeout` PHP ini setting

Constant
`GuzzleHttp\RequestOptions::READ_TIMEOUT`

The timeout applies to individual read operations on a streamed body (when the `stream` option is enabled).

```php
$response = $client->request('GET', '/stream', [
    'stream' => true,
    'read_timeout' => 10,
]);

$body = $response->getBody();

// Returns false on timeout
$data = $body->read(1024);

// Returns false on timeout
$line = fgets($body->detach());
```

## retries

Summary
Current retry count used by `GuzzleHttp\Middleware::retry()`.

Types
- int

Default
`0` when retry middleware is used

Constant
`GuzzleHttp\RequestOptions::RETRIES`

The retry middleware initializes this option to `0` before the first attempt and increments it before each retry. Applications may seed it on a per-request basis when using the retry middleware.

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

With Guzzle's built-in cURL and PHP stream handlers, non-streaming responses use the sink stream as the response body. If you pass a PHP resource, Guzzle wraps it in a PSR-7 stream before writing to it. Closing or garbage-collecting the response body can close that wrapped resource.

If you need to keep using a resource after the response is no longer referenced, keep the response body alive or detach the resource before the body is destroyed:

```php
$response = $client->request('GET', '/stream/20', ['sink' => $resource]);
$resource = $response->getBody()->detach();
```

> [!NOTE]
> `save_to` was deprecated in Guzzle 6 and removed in Guzzle 7. Use `sink`.

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

The built-in stream handler rejects `stream => true` when the
`max_host_connections` or `max_total_connections` constructor options are
configured because streamed connections cannot be capped; `stream => false` and
omitting the option remain valid, and the numeric values provide no
stream-handler admission control. See
[How can I limit concurrent connections?](faq.md#how-can-i-limit-concurrent-connections)
for the connection cap semantics.

## stream_context

Summary
PHP stream context options to merge into the context used by the built-in stream
handler.

Types
- array

Default
None

Constant
`GuzzleHttp\RequestOptions::STREAM_CONTEXT`

This option is only supported by the built-in stream handler. Built-in cURL
handlers deprecate this option because cURL does not use PHP stream contexts.

Stream context options outside the built-in stream handler allow-list, or not
available in the current PHP runtime, are deprecated. Allow-listing means Guzzle
passes the option through without its own deprecation warning; PHP or OpenSSL
may still reject or ignore an option depending on the runtime. The allow-list is
`http.request_fulluri`, `socket.bindto`, `socket.tcp_nodelay`,
`ssl.SNI_enabled`, `ssl.capture_peer_cert`, `ssl.capture_peer_cert_chain`,
`ssl.ciphers`, `ssl.disable_compression`, `ssl.no_ticket`,
`ssl.peer_fingerprint`, `ssl.security_level`, and `ssl.verify_depth`. Use
Guzzle request options instead when configuring the request method, URI, body,
headers, timeouts, redirects, proxy, TLS certificate files, TLS private keys,
protocol versions, verification, progress, debug output, sinks, cookies, and
allowed protocols. TLS minimum protocol versions are managed through the
`crypto_method` request option, maximum protocol versions are managed through
the `crypto_method_max` request option, and TLS verification is managed through
the `verify` request option.

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

If you do not need a specific certificate bundle, then Mozilla provides a commonly used CA bundle which can be downloaded [here](https://curl.se/ca/cacert.pem) (provided by the maintainer of cURL). Once you have a CA bundle available on disk, you can set the "openssl.cafile" PHP ini setting to point to the path to the file, allowing you to omit the "verify" request option. Much more detail on SSL certificates can be found on the [cURL website](http://curl.se/docs/sslcerts.html).

## timeout

Summary
Number of seconds to use as the total timeout of the request. Use `0` to wait indefinitely (the default behavior).

Types
- int
- float

Default
`0`

Constant
`GuzzleHttp\RequestOptions::TIMEOUT`

```php
// Timeout if a server does not return a response in 3.14 seconds.
$client->request('GET', '/delay/5', ['timeout' => 3.14]);
// PHP Fatal error:  Uncaught exception 'GuzzleHttp\Exception\TransferException'
```

## version

Summary
Protocol version to use with the request.

Types
string, int, float

Default
`1.1`

Constant
`GuzzleHttp\RequestOptions::VERSION`

```php
// Force HTTP/1.0
$request = $client->request('GET', '/get', ['version' => 1.0]);
```
