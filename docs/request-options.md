# Request Options

You can customize requests created and transferred by a client using **request
options**. Request options control various aspects of a request including,
headers, query string parameters, timeout settings, the body of a request, and
much more.

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

Set to `true` (the default setting) to enable normal redirects with a maximum
number of 5 redirects.

```php
$res = $client->request('GET', '/redirect/3');
echo $res->getStatusCode();
// 200
```

You can also pass an associative array containing the following key value pairs:

- max: (int, default=5) maximum number of allowed redirects.

- strict: (bool, default=false) Set to true to use strict redirects. Strict RFC
  compliant redirects mean that POST redirect requests are sent as POST requests
  vs. doing what most browsers do which is redirect POST requests with GET
  requests. The RFC 10008 QUERY method keeps its method and body across
  non-strict 301 and 302 redirects, matching the 307 and 308 behavior that
  already applies to every method, and a 303 redirect is followed with a
  body-less GET.

- referer: (bool, default=false) Set to true to add a `Referer` header when
  redirecting. On a cross-origin redirect only the origin (scheme, host, and
  port) is sent, and the header is omitted entirely when the scheme changes,
  including an `https` to `http` downgrade. See
  [Cross-Origin Redirects](#cross-origin-redirects).

- protocols: (non-empty array containing `http` and/or `https`,
  default=`['http', 'https']`) Specifies which protocols are allowed for
  redirect requests. Values are case-sensitive; only `http` and `https` are
  accepted.

- on_redirect: (callable) PHP callable that is invoked when a redirect is
  encountered. The callable is invoked with the original request, the redirect
  response that was received, and the effective URI. Any return value from the
  on_redirect function is ignored. When requests are sent with `GuzzleHttp\Pool`
  and this callback is supplied via the pool's `options` configuration, the
  callable also receives the iterable key that identified the request as a
  fourth argument.

- track_redirects: (bool) When set to `true`, each redirected URI and status
  code encountered will be tracked in the `X-Guzzle-Redirect-History` and
  `X-Guzzle-Redirect-Status-History` headers respectively. All URIs and status
  codes will be stored in the order which the redirects were encountered.

> [!NOTE]
> When tracking redirects the `X-Guzzle-Redirect-History` header will exclude
> the initial request's URI and the `X-Guzzle-Redirect-Status-History` header
> will exclude the final status code. Redirect history is stored in response
> headers, and those header names are not reserved by Guzzle. If the final
> response already contains headers with these names, including when no redirect
> occurs, those values may be server-provided. Do not use these headers as a
> security boundary. For security-sensitive redirect history, collect values
> with the `on_redirect` option instead.

> [!NOTE]
> Guzzle follows only the redirect status codes 301, 302, 303, 307, and 308.
> Other 3xx responses are returned as-is even when they include a Location
> header.

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
> This option only has an effect if your handler has the
> `GuzzleHttp\Middleware::redirect` middleware. This middleware is added by
> default when a client is created with no handler, and is added by default when
> creating a handler with `GuzzleHttp\HandlerStack::create`.

> [!NOTE]
> This option has **no** effect when making requests using
> `GuzzleHttp\Client::sendRequest()`. In order to stay compliant with PSR-18 any
> redirect response is returned as is.

### Cross-Origin Redirects

Guzzle considers a redirect cross-origin when the scheme, host, or effective
port changes.

On cross-origin redirects, Guzzle removes origin-scoped HTTP credentials before
sending the redirected request. This includes the `Authorization` and `Cookie`
headers, the generic `auth` request option, and cURL HTTP authentication options
such as `CURLOPT_HTTPAUTH` and `CURLOPT_USERPWD`.

Same-origin redirects preserve those values. Guzzle does not automatically
remove transport identity or TLS client credential options solely because the
redirect is cross-origin. In particular, TLS client authentication options such
as `cert`, `ssl_key`, custom cURL TLS options, and stream context TLS options
are not removed automatically on cross-origin redirects.

When the optional `referer` setting is enabled, Guzzle also limits what it
discloses to the new origin. On a cross-origin redirect it sends only the
request's origin (scheme, host, and port) in the `Referer` header instead of the
full URL, and it omits the header entirely when the scheme changes, including an
`https` to `http` downgrade. Same-origin redirects send the full URL. This
matches the `strict-origin-when-cross-origin` policy that modern browsers use by
default.

If TLS client credentials are only trusted for the original origin, disable
automatic redirects and handle redirect responses manually, or use separate
clients and request options for trusted origins.

The RFC 10008 QUERY method has method-specific redirect behavior. Guzzle keeps
the QUERY method and request body across 301, 302, 307, and 308 redirects, and
follows a 303 with a body-less GET. QUERY request bodies can carry sensitive
query content. On cross-origin redirects Guzzle removes origin credentials such
as the Authorization and Cookie headers and the auth request option, but it does
not remove the request body. Disable automatic redirects or use on_redirect if a
QUERY body must not be sent to another origin.

## auth

Summary
Pass HTTP authentication parameters to use with the request. An array must
contain the username in index `[0]`, the password in index `[1]`, and can
optionally provide a built-in authentication type in index `[2]`. Pass `false`
or `null` to disable authentication for a request. String values are passed
through for custom handlers.

Built-in Basic and Digest authentication are applied by
`GuzzleHttp\Middleware::auth()`, which is included by default when using
`GuzzleHttp\HandlerStack::create()` or when the client creates its default
handler. Raw custom handlers must be wrapped in
`GuzzleHttp\HandlerStack::create($handler)` or explicitly include
`GuzzleHttp\Middleware::auth()` to use built-in Basic or Digest authentication.
Unrecognized array auth types are left in the request options for custom
middleware or custom handlers.

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
Use [basic HTTP authentication](http://www.ietf.org/rfc/rfc7617.txt) in the
`Authorization` header (the default setting used if none is specified).

```php
$client->request('GET', '/get', ['auth' => ['username', 'password']]);
```

digest
Use [digest authentication](https://www.rfc-editor.org/rfc/rfc7616.html) through
Guzzle's auth middleware. When no reusable challenge is available, Digest
authentication sends an initial unauthenticated probe, processes a
`WWW-Authenticate: Digest ...` challenge, and retries with an `Authorization:
Digest ...` header. Requests with a body are probed with an empty body and
`Content-Length: 0`; the body is sent only on authenticated attempts: once in
the normal one-challenge handshake, and again if a stale-nonce challenge must be
retried. When challenge reuse is enabled (the default) and a cached challenge
applies, later body-less requests send the first Digest leg preemptively, with
an incremented nonce count; body-bearing requests always keep the probe
handshake. The `delay` option applies before the first Digest leg, whether that
leg is a probe or a preemptive request. A non-401 answer to a body-withholding
probe other than a proxy challenge (407) or a followable redirect fails the
request with `ResponseException`.

```php
$client->request('GET', '/get', [
    'auth' => ['username', 'password', 'digest']
]);
```

When a Digest challenge omits `domain`, RFC 7616 scopes the protection space to
the whole origin. Do not rely on default reuse across same-origin trust
boundaries: a preemptive request can disclose the Digest username or userhash
and password-derived response material for the cached realm to another
same-origin endpoint before that endpoint challenges. When `domain` is present,
Guzzle applies RFC literal-prefix matching to request targets, so
`domain="/api"` also covers `/api-internal`, and a query-scoped prefix such as
`/api?tenant=a` also covers longer targets beginning with that same string, such
as `/api?tenant=abc`. Use separate origins, configure a narrow server `domain`,
or disable reuse by replacing the default auth middleware:

```php
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;

$stack = HandlerStack::create();
$stack->remove('auth');
$stack->after('allow_redirects', Middleware::auth(false), 'auth');

$client = new Client(['handler' => $stack]);
```

Supported Digest algorithms are `MD5`, `MD5-sess`, `SHA-256`, `SHA-256-sess`,
and the `SHA-512-256` variants when PHP supports the `sha512/256` hash
algorithm. Guzzle uses PHP's FIPS SHA-512/256, matching libcurl builds with
SHA-512/256 support; servers built against RFC 7616's erratum test vectors for
truncated SHA-512 will not interoperate with either Guzzle or curl. Guzzle
supports legacy non-session challenges without `qop` and challenges with
`qop=auth`. Session algorithms require `qop`. `auth-int` is not supported.

Each Digest leg is a separate Guzzle handler invocation with its own request
options. `on_stats` fires once per leg; `on_headers` and `progress` are also
attached per leg. The `delay` option applies once, before the first Digest leg:
the probe, or a preemptive request when challenge reuse applies.

When multiple usable Digest challenges are present, Guzzle selects the strongest
supported algorithm, preferring `SHA-512-256` over `SHA-256` over `MD5`, and
preferring `-sess` variants within a family. If a challenge offers only
`auth-int`, uses a session algorithm without `qop`, uses an unknown or
unavailable algorithm, is malformed, or contains values that cannot be safely
placed in a header, Guzzle ignores that challenge. If no usable Digest challenge
remains, the original 401 response is returned for normal `http_errors`
handling; inspect its `WWW-Authenticate` header to debug the failure.

`Proxy-Authenticate` Digest challenges are not handled by the auth middleware. A
407 response to a Digest probe passes through unchanged. The built-in cURL
handlers allow proxy credentials through `CURLOPT_PROXYUSERPWD`, but they do not
expose `CURLOPT_PROXYAUTH` through the raw cURL option allow-list, and libcurl
defaults proxy authentication to Basic.

Probe redirects are followed by the redirect middleware under normal redirect
rules. Non-strict 301/302 and 303 redirects clear the body, with exact `GET`,
`HEAD`, and `OPTIONS` keeping their method and other methods rewritten to `GET`,
except exact `QUERY` on non-strict 301/302 preserves the method and body.
307/308 and strict 301/302 redirects preserve the method and body. A redirected
body-bearing request performs its own Digest handshake at the new URI.

To use libcurl's native Digest implementation instead, omit `auth` and configure
cURL options such as `CURLOPT_HTTPAUTH => CURLAUTH_DIGEST` and `CURLOPT_USERPWD`
directly with a cURL handler.

## body

Summary
The `body` option is used to control the body of an entity enclosing request
(e.g., PUT, POST, PATCH).

Types
- string
- `fopen()` resource
- `Psr\Http\Message\StreamInterface`
- callable object or closure
- `Iterator`
- `Stringable`
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

Resource and object values with `__toString()` are converted to PSR-7 streams
using the configured `stream_factory`. Callable and iterator bodies use Guzzle's
existing PSR-7 stream handling because PSR-17 does not define factories for
those stream types. Callable bodies may be closures or invokable objects.
Strings are always used as literal body contents, even when they name a
callable. Callable arrays are arrays, and arrays are not valid `body` values.
`int`, `float`, and `bool` are not valid `body` values in Guzzle 8. Request
bodies that already implement `Psr\Http\Message\StreamInterface` are used as
provided.

`int`, `float`, `bool`, arrays, and generic objects without `__toString()` are
not valid `body` values in Guzzle 8.

> [!NOTE]
> This option cannot be used with `form_params`, `multipart`, or `json`

## cert

Summary
Set to a string to specify the path to a file containing a client side
certificate. PEM is the default certificate format. If a password is required,
then set to an array containing the path to the certificate file in the first
array element followed by the password required for the certificate in the
second array element. A `null` password is treated the same as omitting it. Use
[`cert_type`](#cert_type) to specify another supported certificate format.

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
> TLS client certificate options remain active during redirects. See
> [Cross-Origin Redirects](#cross-origin-redirects) for details.

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

The cURL handler passes this value to `CURLOPT_SSLCERTTYPE`. Supported values
depend on libcurl and its TLS backend.

> [!NOTE]
> The stream handler supports only `PEM` certificate files.

## cookies

Summary
Specifies whether or not cookies are used in a request or what cookie jar to use
or what cookies to send.

Types
- `GuzzleHttp\Cookie\CookieJarInterface`
- false

Default
None

Constant
`GuzzleHttp\RequestOptions::COOKIES`

You must specify the cookies option as a `GuzzleHttp\Cookie\CookieJarInterface`
or `false`.

`true` is only a client-constructor shorthand. Guzzle converts `['cookies' =>
true]` in the constructor into a shared `GuzzleHttp\Cookie\CookieJar`.
Per-request `cookies` values must be `false` or a `CookieJarInterface` instance.

```php
$jar = new \GuzzleHttp\Cookie\CookieJar();
$client->request('GET', '/get', ['cookies' => $jar]);
```

> [!WARNING]
> This option only has an effect if your handler has the
> `GuzzleHttp\Middleware::cookies` middleware. This middleware is added by
> default when a client is created with no handler, and is added by default when
> creating a handler with `GuzzleHttp\HandlerStack::create`.

> [!TIP]
> When creating a client, you can set the default cookie option to `true` to use
> a shared cookie session associated with the client.

## connect_timeout

Summary
Number of seconds to wait while trying to connect to a server. Use `0` to
disable the connect timeout. Positive values below `0.001` seconds are
rejected by the built-in cURL handler.

Types
- int
- float

Default
`60`

Constant
`GuzzleHttp\RequestOptions::CONNECT_TIMEOUT`

```php
// Timeout if the client fails to connect to the server in 3.14 seconds.
$client->request('GET', '/delay/5', ['connect_timeout' => 3.14]);
```

> [!NOTE]
> `connect_timeout` is implemented by cURL handlers. The PHP stream handler does
> not provide a separate connection-timeout control; it accepts this option
> without effect so shared request configuration can enable a cURL connection
> timeout when cURL is available. The stream handler's connect phase is bounded
> by the `read_timeout` idle timeout, and by `timeout` when that is lower.

See [Timeout phases](#timeout-phases) for a summary of the timeout options
across the built-in handlers.

## crypto_method

Summary
A value describing the minimum TLS protocol version to use.

Types
int

Default
TLS 1.2 or newer for HTTPS requests sent by the built-in cURL and stream
handlers.

Constant
`GuzzleHttp\RequestOptions::CRYPTO_METHOD`

```php
$client->request('GET', '/foo', ['crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT]);
```

> [!NOTE]
> This setting must be set to one of the `STREAM_CRYPTO_METHOD_TLS*_CLIENT`
> constants. It controls the minimum TLS protocol version. cURL 7.52.0 or higher
> is required to use TLS 1.3 with the cURL handler.

## crypto_method_max

Summary
A value describing the maximum TLS protocol version to use.

Types
int

Default
None. HTTPS requests still default to TLS 1.2 or newer through `crypto_method`.

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
> effective minimum version. For HTTPS requests, the built-in handlers default
> the minimum to TLS 1.2 or newer unless `crypto_method` is explicitly set.
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

Except for Guzzle's special `body_as_string` key, the array is keyed by integer
cURL option constants and values are passed to cURL after Guzzle applies request
options. Raw cURL options that conflict with Guzzle-managed request handling are
rejected by the built-in cURL handlers.

Raw cURL options outside the built-in cURL handlers' allow-list are rejected.
Allow-listing means Guzzle passes the option through; PHP, libcurl, or the TLS
backend may still reject or ignore an option depending on the runtime. The
allow-list is limited to the following `CURLOPT_*` constants when they are
defined by the installed PHP cURL extension:

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

`CURLOPT_HEADEROPT` is intentionally absent from the allow-list above. Guzzle
sets it internally to `CURLHEADER_SEPARATE` whenever it configures
`CURLOPT_PROXYHEADER`, and also for an HTTP(S) proxy CONNECT tunnel even when no
proxy header is configured, so proxy headers stay separate from origin request
headers; passing `CURLOPT_HEADEROPT` yourself is rejected.

When an effective HTTP or HTTPS proxy is used, the built-in cURL handlers treat
PSR `Proxy-Authorization` as proxy-scoped rather than origin-scoped. On libcurl
7.37.0 and newer (with the proxy-header cURL constants available), the header is
moved to cURL's proxy-header channel (`CURLOPT_PROXYHEADER`), so it
authenticates the proxy rather than leaking to the origin. Direct (no-proxy) and
SOCKS-proxy requests are left untouched.

A proxy CONNECT tunnel carrying a non-empty `Proxy-Authorization` credential
requires a fresh connection, because libcurl cannot key connection reuse on that
opaque header value. Under `TransportSharing::PERSISTENT_REQUIRE`, which
requires reuse, such a request is rejected with an `InvalidArgumentException`
instead of silently degrading reuse. On older libcurl (or a build missing the
proxy-header constants), where proxy headers cannot be separated, a request
carrying a non-empty `Proxy-Authorization` header through an HTTP or HTTPS proxy
is rejected up front with a `RequestException`; libcurl 7.37.0 or newer is
required.

## debug

Summary
Set to `true` or set to a PHP stream returned by `fopen()` to enable debug
output with the handler used to send a request. For example, when using cURL to
transfer requests, cURL's verbose of `CURLOPT_VERBOSE` will be emitted. When
using the PHP stream wrapper, stream wrapper notifications will be emitted. If
set to true, the output is written to PHP's STDOUT. If a PHP stream is provided,
output is written to the stream.

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
Specify whether or not `Content-Encoding` responses (gzip, deflate, etc.) are
automatically decoded.

Types
- string
- bool

Default
`true`

Constant
`GuzzleHttp\RequestOptions::DECODE_CONTENT`

This option can be used to control how content-encoded response bodies are
handled. By default, `decode_content` is set to true, meaning any gzipped or
deflated response will be decoded by Guzzle.

When set to `false`, the body of a response is never decoded, meaning the bytes
pass through the handler unchanged.

```php
// Request gzipped data, but do not decode it while downloading
$client->request('GET', '/foo.js', [
    'headers'        => ['Accept-Encoding' => 'gzip'],
    'decode_content' => false
]);
```

When set to a string, the bytes of a response are decoded and the string value
provided to the `decode_content` option is passed as the `Accept-Encoding`
header of the request.

```php
// Pass "gzip" as the Accept-Encoding header.
$client->request('GET', '/foo.js', ['decode_content' => 'gzip']);
```

> [!WARNING]
> The `Accept-Encoding` header will not be sent unless you provide it
> explicitly, or pass a string value to `decode_content`. That is
> [equivalent](https://www.rfc-editor.org/rfc/rfc9110#field.accept-encoding) to
> sending `Accept-Encoding: *`. Most servers will probably return an
> uncompressed body in response to that but some might opt to use a compression
> method that is not supported by your system.
>
> In order to enable compression, and to ensure that only supported encoding
> methods will be used, you should let curl send the `Accept-Encoding` header:
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

`delay` must be an `int` or `float`, must be finite, and must be greater than or
equal to `0`. Negative values, `NAN`, and `INF` are rejected before the handler
is invoked.

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

Set to `true` to enable the "Expect: 100-Continue" header for all requests that
sends a body. Set to `false` to disable the "Expect: 100-Continue" header for
all requests. Set to a number so that the size of the payload must be greater
than the number in order to send the Expect header. Setting to a number will
send the Expect header for all requests in which the size of the payload cannot
be determined or where the body is not rewindable.

By default, Guzzle will add the "Expect: 100-Continue" header when the size of
the body of a request is greater than 1 MB and a request is using HTTP/1.1.

> [!NOTE]
> This option only takes effect when using HTTP/1.1. The HTTP/1.0, HTTP/2, and
> HTTP/3 protocols do not support the "Expect: 100-Continue" header. Support for
> handling the "Expect: 100-Continue" workflow must be implemented by Guzzle
> HTTP handlers used by a client.

## force_ip_resolve

Summary
Set to "v4" if you want the HTTP handlers to use only ipv4 protocol or "v6" for
ipv6 protocol.

Types
"v4" or "v6"

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

Only the exact, case-sensitive values `v4` and `v6` are accepted.

> [!NOTE]
> This setting must be supported by the HTTP handler used to send a request.
> `force_ip_resolve` is currently only supported by the built-in cURL and stream
> handlers.

## form_params

Summary
Used to send an `application/x-www-form-urlencoded` POST request.

Types
array

Constant
`GuzzleHttp\RequestOptions::FORM_PARAMS`

Array mapping form field names to scalar, `null`, or nested array values. Values
are serialized with PHP's `http_build_query()`. Sets the Content-Type header to
application/x-www-form-urlencoded when no Content-Type header is already
present.

```php
$client->request('POST', '/post', [
    'form_params' => [
        'foo' => 'bar',
        'baz' => ['hi', 'there!']
    ]
]);
```

> [!NOTE]
> `form_params` cannot be used with the `multipart` option. You will need to use
> one or the other. Use `form_params` for `application/x-www-form-urlencoded`
> requests, and `multipart` for `multipart/form-data` requests.
>
> This option cannot be used with `body`, `multipart`, or `json`

## headers

Summary
Array keyed by header names to add to the request. List-style header arrays are
rejected. PHP stores numeric-string header names as integer keys; when such keys
are accepted, Guzzle casts header keys back to strings while applying them. Each
value is a string or non-empty array of strings representing the header field
values.

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

Headers may be added as default options when creating a client. When headers are
used as default options, they are only applied if the request being created does
not already contain the specific header. This includes both requests passed to
the client in the `send()` and `sendAsync()` methods, and requests created by
the client (e.g., `request()` and `requestAsync()`).

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
Set to `false` to disable throwing exceptions on an HTTP protocol errors (i.e.,
4xx and 5xx responses). Exceptions are thrown by default when HTTP protocol
errors are encountered.

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
> This option only has an effect if your handler has the
> `GuzzleHttp\Middleware::httpErrors` middleware. This middleware is added by
> default when a client is created with no handler, and is added by default when
> creating a handler with `GuzzleHttp\HandlerStack::create`.

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

Enables/disables IDN support, can also be used for precise control by combining
`IDNA_*` constants (except `IDNA_ERROR_*`), see the `$options` parameter in the
[idn_to_ascii()](https://www.php.net/manual/en/function.idn-to-ascii.php)
documentation for more details. Pass `false` or `null` to disable IDN
conversion.

## json

Summary
The `json` option is used to easily upload JSON encoded data as the body of a
request. A Content-Type header of `application/json` will be added if no
Content-Type header is already present on the message. An Accept header is not
added automatically; pass one explicitly if the server requires JSON response
content negotiation.

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
> This request option does not support customizing the Content-Type header or
> any of the options from PHP's
> [json_encode()](http://www.php.net/manual/en/function.json-encode.php)
> function. If you need to customize these settings, then you must pass the JSON
> encoded data into the request yourself using the `body` request option and you
> must specify the correct Content-Type header using the `headers` request
> option.
>
> This option cannot be used with `body`, `form_params`, or `multipart`

## multipart

Summary
Sets the body of the request to a `multipart/form-data` form.

Types
array

Constant
`GuzzleHttp\RequestOptions::MULTIPART`

The value of `multipart` is an array of part arrays, each containing the
following key value pairs:

- `name`: (string|int, required) the form field name
- `contents`: (mixed, required) Any non-array value accepted by
  `GuzzleHttp\Psr7\Utils::streamFor()`, including strings, resources, streams,
  iterators, closures, and invokable objects. Arrays are expanded as nested
  multipart fields; `headers` and `filename` cannot be used when `contents` is
  an array.
- `headers`: (array) Optional array of custom string header values to use with
  the form element.
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
> `multipart` cannot be used with the `form_params` option. You will need to use
> one or the other. Use `form_params` for `application/x-www-form-urlencoded`
> requests, and `multipart` for `multipart/form-data` requests.
>
> This option cannot be used with `body`, `form_params`, or `json`

## multiplex

Summary
Controls how a request sent through a built-in cURL handler relates to shared,
multiplexed connections: how an HTTP/2 or HTTP/3 request pursues one, or, with
`Multiplexing::NONE`, whether the transfer may share its connection at all.

Types
- string (one of the `GuzzleHttp\Multiplexing` mode constants)

Default
`Multiplexing::WAIT`

Constant
`GuzzleHttp\RequestOptions::MULTIPLEX`

libcurl multiplexes concurrent HTTP/2 and HTTP/3 transfers over a single
connection whenever a multiplexable connection to the origin already exists,
whatever this option is set to. The modes grade how much further the request
goes:

- `Multiplexing::EAGER` - never wait for a connection that is still being
  established: a burst of requests against a cold origin opens parallel
  connections.
- `Multiplexing::WAIT` (default) - wait for a pending connection that libcurl
  considers eligible for multiplexing, normally one to the same origin, and
  share it. Silently ignored by the stream handler and the blocking
  `CurlHandler`, which has no multi handle to multiplex over. If the connection
  turns out not to multiplex, waiting requests open their own.
- `Multiplexing::REQUIRE_EAGER` - guarantee a multiplexed protocol or fail
  loudly, while dialing eagerly. HTTP/2 requests are sent with prior knowledge,
  so TLS connections offer only `h2` via ALPN (libcurl 8.14.0+) and cleartext
  connections speak HTTP/2 directly; cleartext requests sent through a proxy are
  rejected. HTTP/3 requests are pinned to HTTP/3 with no downgrade at all
  (libcurl 8.13.0+, PHP 8.4+); a proxy cannot carry them and is rejected. A
  server limited to lower protocol versions fails the connection instead of
  downgrading. The required family also rejects final `CURLOPT_HTTPAUTH` masks
  that permit NTLM, which libcurl retries over HTTP/1.1. Requires protocol
  version `2`/`2.0` or `3`/`3.0` and a cURL handler; anything else throws. A
  cold burst dials connections in parallel, but libcurl still packs later
  streams onto the first established connection rather than balancing.
- `Multiplexing::REQUIRE_WAIT` - the same guarantees as
  `Multiplexing::REQUIRE_EAGER`, plus `WAIT`'s waiting on pending connections.
  The required modes require a handler that permits actual multiplexing, not
  merely a multiplexed protocol, and are rejected on a `Multiplexing::NONE`
  handler.

`Multiplexing::NONE` disables multiplexing for a whole handler when passed as
the `multiplex` client configuration option, which configures the default
handler and also becomes the default request option, or, when constructing a
handler directly, as the `CurlMultiHandler` `multiplex` constructor option. A
handler configured with `Multiplexing::NONE` wins over the default `WAIT`:
default requests run without waiting, explicitly requested wait modes are
rejected as a configuration conflict when the transfer would actually wait, and
the required modes are always rejected, because they require a handler that
permits actual multiplexing, not merely a multiplexed protocol. As a request
option value, `Multiplexing::NONE` guarantees the transfer does not share its
connection with any concurrent transfer. `Multiplexing::NONE` does not force
HTTP/1.1: on a `Multiplexing::NONE` handler, HTTP/2 still negotiates and each
transfer keeps its connection to itself.

The request option value is accepted exactly where the guarantee holds and can
be verified: on a `CurlMultiHandler` configured with `Multiplexing::NONE`, for
requests whose declared protocol version is HTTP/1.x, on `CurlHandler`, and on
the stream handler, which never multiplexes. An HTTP/2 or HTTP/3 request with a
`Multiplexing::NONE` request option is rejected on a `CurlMultiHandler` that
permits multiplexing. Acceptance is decided from the request's declared protocol
version, before any transport-level downgrade: an HTTP/3 request sent through a
proxy is delivered over HTTP/2 or HTTP/1.1 on the wire, but is still rejected.
On a `CurlMultiHandler` that permits multiplexing, `Multiplexing::NONE` is also
rejected with a custom `handle_factory`, when the handler requires persistent
transport sharing (the safeguards can require a fresh connection, which required
sharing forbids), when the request carries an `Expect: 100-continue` header (its
417 retries select connections outside the safeguards; remove an explicitly
supplied header, or set the `expect` request option to `false` to prevent it
being added automatically), and combined with a raw `CURLOPT_HTTPAUTH` cURL
option, whose authentication retries do the same.

On a client whose multi handler permits multiplexing, the ordinary non-streaming
default stack - both cURL handlers available and no connection caps forcing
multi-only routing - runs synchronous requests on the `CurlHandler` path, which
satisfies the guarantee for any protocol version, while asynchronous requests
run on the `CurlMultiHandler`, so an HTTP/2 request with `Multiplexing::NONE`
succeeds synchronously and is rejected asynchronously on the same client.
Keep-alive reuse between consecutive transfers is unaffected, except on libcurl
versions below 7.77.0 and from 8.11.0 through 8.12.1, where an accepted HTTP/1.x
request on a multiplexing `CurlMultiHandler` forces a fresh connection. Custom
handlers receive the `multiplex` option unchanged: its semantics are
handler-defined, Guzzle does not guarantee it is honored, and a client-level
`Multiplexing::NONE` with a custom handler flows to it as a default request
option without client-side enforcement.

```php
$client->requestAsync('GET', 'https://example.com/big-file', [
    'version' => '2.0',
    'multiplex' => Multiplexing::EAGER,
]);
```

> [!NOTE]
> None of the modes is a connection **cap**: once an established HTTP/2
> connection has no free streams - servers commonly allow about 100 - additional
> requests open additional connections regardless of this option.

> [!NOTE]
> libcurl never reuses or coalesces a connection across differing TLS settings
> (`verify`, custom CA, client certificate/key, pinned public key) or proxy
> settings, so a verified request can never ride an unverified connection.
> Because libcurl coalesces HTTP/2 connections, requests to different hostnames
> that resolve to the same address and are covered by the server certificate may
> share one connection; a server not authoritative for the second name can
> reject it with HTTP/2 `421 Misdirected Request`. Waiting requests share one
> in-progress connection, so a slow lead connection adds latency to, and is
> charged against the `timeout` of, the requests waiting on it. Only requests
> whose protocol version resolves to HTTP/2 or HTTP/3 wait. Use
> `Multiplexing::EAGER` when you rely on independent connection timing; it stops
> the waiting but does not guarantee separate connections; established
> multiplex-capable connections are still shared.

## on_headers

Summary
A callable that is invoked when the HTTP headers of the final response, or a
`101 Switching Protocols` response, have been received but the body has not yet
begun to download.

Types
- callable

Constant
`GuzzleHttp\RequestOptions::ON_HEADERS`

The callable accepts a `Psr\Http\Message\ResponseInterface` object and the
corresponding `Psr\Http\Message\RequestInterface` object. Built-in handlers do
not invoke it for interim informational responses such as `100 Continue` or `103
Early Hints`. If an exception is thrown by the callable, then the promise
associated with the response will be rejected with a
`GuzzleHttp\Exception\ResponseException` that wraps the exception that was
thrown.

You may need to know what headers and status codes were received before data can
be written to the sink.

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

When requests are sent with `GuzzleHttp\Pool` and this callback is supplied via
the pool's `options` configuration, the callable also receives the iterable key
that identified the request as a third argument:

```php
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

$requests = [
    'image' => new Request('GET', 'http://httpbin.org/image/jpeg'),
    'avatar' => new Request('GET', 'http://httpbin.org/image/png'),
];

$pool = new Pool($client, $requests, [
    'options' => [
        'on_headers' => function (ResponseInterface $response, RequestInterface $request, $key) {
            // $key is 'image' or 'avatar'
        },
    ],
]);

$pool->promise()->wait();
```

> [!NOTE]
> When writing HTTP handlers, the `on_headers` function must be invoked for the
> final response, or for a `101 Switching Protocols` response, before writing
> data to the body of the response.

## on_stats

Summary
`on_stats` allows you to get access to transfer statistics of a request and
access the lower level transfer details of the handler associated with your
client. `on_stats` is a callable that is invoked when a handler has finished
sending a request. The callback is invoked with transfer statistics about the
request, the response received, or the error encountered. Included in the data
is the total amount of time taken to send the request.

Types
- callable

Constant
`GuzzleHttp\RequestOptions::ON_STATS`

The callable accepts a `GuzzleHttp\TransferStats` object. Built-in handlers
reject non-callable `on_stats` values before starting the transfer.

Exceptions thrown by `on_stats` are not wrapped by Guzzle and may escape from
the handler wait path. With the built-in cURL handlers, native cURL handles are
released before `on_stats` is invoked. cURL handlers emit `on_stats` per
low-level transfer attempt, so retries may invoke it more than once for one
logical request.

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

When requests are sent with `GuzzleHttp\Pool` and this callback is supplied via
the pool's `options` configuration, the callable also receives the iterable key
that identified the request as a second argument.

## on_trailers

Summary
A callable that is invoked exactly once when a transfer completes successfully,
with the HTTP trailer fields of the response.

Types
- callable

Constant
`GuzzleHttp\RequestOptions::ON_TRAILERS`

The callable accepts an associative array of trailer field names mapped to lists
of field values, the `Psr\Http\Message\ResponseInterface` object, and the
corresponding `Psr\Http\Message\RequestInterface` object. The built-in cURL
handlers invoke it after the entire response body has been written to the sink,
after `on_headers`, and before `on_stats`. The array is empty when the response
carried no trailer fields, for example because the server sent none or sent them
as ordinary headers. Trailer field names are lowercased and grouped
case-insensitively, matching the HTTP/2 wire format; values keep their wire
order. The callable is never invoked for failed transfers. If an exception is
thrown by the callable, then the promise associated with the response will be
rejected with a `GuzzleHttp\Exception\ResponseException` that wraps the
exception that was thrown.

```php
use GuzzleHttp\Psr7;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

// Verify a content checksum delivered after the body.
$client->request('GET', 'https://example.com/stream', [
    'version' => '2.0',
    'on_trailers' => function (array $trailers, ResponseInterface $response, RequestInterface $request) {
        $checksum = $trailers['x-checksum'][0] ?? null;
        if ($checksum === null || !hash_equals($checksum, Psr7\Utils::hash($response->getBody(), 'sha256'))) {
            throw new \Exception('Response body checksum missing or mismatched!');
        }
    }
]);
```

When requests are sent with `GuzzleHttp\Pool` and this callback is supplied via
the pool's `options` configuration, the callable also receives the iterable key
that identified the request as a fourth argument.

> [!NOTE]
> Only the built-in cURL handlers invoke `on_trailers`; the built-in stream
> handler rejects the option because it cannot observe trailer fields, and the
> mock handler ignores it. When writing HTTP handlers that support trailer
> fields, invoke the `on_trailers` callable exactly once per successful
> transfer, after the response body has completed, and never for failed
> transfers. Malformed trailer field lines are discarded before parsing. Trailer
> fields are reported separately from response headers and are never merged into
> the response.

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

With the built-in cURL handlers, returning a truthy value aborts the transfer
and rejects the request promise with a `GuzzleHttp\Exception\ResponseException`
when a response is available, or a `GuzzleHttp\Exception\RequestException`
otherwise. If the callable throws, the built-in cURL handlers abort the transfer
and reject the promise with the same response-aware classification while
wrapping the thrown exception. If a built-in handler receives a progress byte
count that cannot be represented as a PHP integer, the transfer is rejected
before the callback is invoked. The built-in stream handler treats progress
callbacks as notifications only and ignores return values.

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

When requests are sent with `GuzzleHttp\Pool` and this callback is supplied via
the pool's `options` configuration, the callable also receives the iterable key
that identified the request as a fifth argument.

## protocols

Summary
Allowed URI schemes for request transfers.

Types
- non-empty array

Default
`['http', 'https']`

Constant
`GuzzleHttp\RequestOptions::PROTOCOLS`

This option accepts a non-empty array containing only the case-sensitive values
`http` and/or `https`. It applies to each request transfer Guzzle sends,
including redirect requests that reuse the same request options.

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
Pass a string to specify a proxy, or an array to specify different proxies for
different protocols.

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

Pass an associative array to specify proxies for specific URI schemes (i.e.,
"http", "https"). Provide a `no` key value pair as a comma- or
whitespace-delimited string or an array of entries that should not be proxied
to; array entries are taken as-is and are never re-split. The `http`, `https`,
and `no` entries may be set to `null` to leave that entry unconfigured.

The `no` list supports the following entry forms:

| `no` entry | Bypasses the proxy for |
| --- | --- |
| `*` | every request |
| `*:80` | any host on effective port `80` |
| `example.com`, `.example.com` | `example.com` and its subdomains |
| `example.com:8080` | `example.com` and its subdomains on port `8080` |
| `127.0.0.1`, `::1`, `[::1]` | requests whose host is that IP literal |
| `[::1]:8080` | that IP literal on port `8080` |
| `10.0.0.0/8`, `fd00::/8` | IP-literal hosts inside the range |

Domain entries are matched case-insensitively, and one final DNS root dot is
ignored on each side before matching; repeated trailing dots are not collapsed,
and only a single leading dot is ignored — entries with repeated leading dots
match nothing. IP literals are normalized before matching, so equivalent IPv6
spellings such as `::1` and `0:0:0:0:0:0:0:1` match; trailing-dot normalization
does not apply to IP literals or CIDR rules. IP and CIDR entries match only
requests whose host is itself an IP literal: host names are never resolved to
addresses when deciding whether to proxy. Ports are matched against the
request's effective port — an explicit port in the URI, otherwise the scheme
default (`80` for "http", `443` for "https") — and CIDR entries are not
port-specific.

> [!NOTE]
> Guzzle will automatically populate this value with your environment's
> `NO_PROXY` environment variable. However, when providing a `proxy` request
> option, it is up to you to provide the `no` value from the `NO_PROXY`
> environment variable.

Custom handlers can use `GuzzleHttp\ProxyOptions::resolve()` to apply
Guzzle-compatible proxy selection. The helper resolves the documented `proxy`
request option shape, including scheme-specific proxy entries and `no` exclusion
rules. Handlers remain responsible for translating the selected proxy string
into their transport-specific configuration. The environment-variable fallback
performed by the built-in handlers is not part of this helper; custom handlers
that want it must implement their own environment lookup.

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
> You can provide proxy URLs that contain a scheme, username, and password. For
> example, `"http://username:password@192.168.16.1:10"`. A scheme-less value
> such as `"127.0.0.1:8125"` is treated as an HTTP proxy. Both built-in handlers
> validate the proxy URL up front and reject a malformed one (an invalid host,
> an out-of-range port, or leading junk before the scheme) with an
> `InvalidArgumentException`.

### Handler support

The `proxy` option — including the `no` list and its validation — means the same
thing on every built-in handler: which proxy, if any, applies to a request is
decided by the same rules everywhere. What differs is the transport executing
that decision:

| Capability | cURL handlers | Stream handler |
| --- | --- | --- |
| HTTP proxy for "http" requests | yes | yes |
| HTTP proxy for "https" requests (CONNECT tunnel) | yes (libcurl 7.54+) | no |
| HTTPS proxy (TLS to the proxy itself) | yes (libcurl 7.54+) | no |
| SOCKS proxies (`socks4://`, `socks4a://`, `socks5://`, `socks5h://`) | yes | no |
| Proxy credentials in the proxy URL | yes | yes (Basic only) |
| Proxy resolution from environment variables | yes | yes |

The stream handler forwards requests through PHP's HTTP stream wrapper, which
supports plain HTTP proxying only: it cannot establish CONNECT tunnels, so
"https" requests through a proxy fail with a connection error. It rejects any
proxy URL whose scheme it cannot execute, throwing an `InvalidArgumentException`
before the request is sent. That covers `https://`, SOCKS (`socks4://`,
`socks4a://`, `socks5://`, `socks5h://`), and anything other than `http://` or a
raw PHP transport such as `tcp://`, `ssl://`, or `tls://`. A recognized
SSL/TLS-family transport that this PHP build's `stream_get_transports()` does
not provide (for example `tls://` on a build without OpenSSL) is build-specific,
so it throws a `RequestException` instead of the `InvalidArgumentException` used
for a scheme invalid on every build. The stream handler now resolves proxies
from the environment too (see below); the scheme rejections above apply
identically to an environment-resolved proxy. A proxy given without an explicit
port defaults to port 1080, libcurl's default HTTP proxy port.

HTTPS proxies (an `https://` proxy URL, where the connection to the proxy itself
is encrypted) require libcurl 7.54.0 or newer built with HTTPS-proxy support.
The cURL handlers reject a request using an HTTPS proxy on anything older up
front, with a `RequestException`. They also reject any `proxy` URL whose scheme
libcurl cannot use as a proxy, with an `InvalidArgumentException`. Proxying an
"https" request establishes a CONNECT tunnel through the proxy, which likewise
requires libcurl 7.54.0 or newer and is rejected up front on older libcurl
(including tunnels forced through raw `curl` options). The proxy's interim `200
Connection established` reply is suppressed on these tunnels, so `on_headers`
observes only origin responses and a tunneled transfer failure is classified by
its transport phase.

### Proxy environment variables

The built-in handlers resolve proxies from the environment themselves: the cURL
handlers configure libcurl's proxy options explicitly so libcurl never reads the
environment itself, and the stream handler resolves the same way and installs
the result in the PHP stream context. When the `proxy` request option makes a
decision for a request, that decision is final, and proxy environment variables
are ignored for the request. For an "https" request, the option resolves like
this:

| `proxy` option value | Result |
| --- | --- |
| option not set, or `null` | environment proxies apply |
| `'http://proxy.example.com:8080'` | that proxy is used; environment ignored |
| `''` | no proxy; environment ignored |
| `['https' => 'http://proxy.example.com:8080']` | that proxy is used; environment ignored |
| `['https' => '']` | no proxy; environment ignored |
| `['https' => null]`, or an array without an `https` entry | environment proxies apply |
| any array whose `no` list matches the request | direct connection; environment ignored (takes precedence over the array rows above) |

In particular, the `no_proxy`/`NO_PROXY` environment variables do not bypass an
explicitly configured proxy; add the hosts to the option's `no` list instead.

When the `proxy` request option makes no decision for a request, the built-in
handlers resolve the proxy from the environment with the same lookup semantics
libcurl uses:

1. The lowercase scheme-specific variable, e.g. `https_proxy` for an "https"
   request. For "http" requests, the uppercase `HTTP_PROXY` variant is never
   read (see <https://httpoxy.org>, and the Windows note below); for other
   schemes the uppercase variant is read when the lowercase one is not set.
2. `all_proxy`, then `ALL_PROXY`.

The first variable with a non-empty value ends the lookup; variables set to an
empty string are treated as unset, matching libcurl. When an environment proxy
is found, the `no_proxy` (or `NO_PROXY`) environment variable is matched against
the request by Guzzle. The value is tokenized the way libcurl tokenizes it —
entries may be separated by commas or whitespace, and a single leading dot is
ignored, so `.example.com` bypasses `example.com` and its subdomains — and each
entry is then matched using the same rules as the option's `no` list (including
CIDR ranges); a match disables the proxy for the request.

Only the real process environment is consulted, matching libcurl: values
injected per-request by the SAPI (e.g. `fastcgi_param` or `SetEnv`) are not read
by this handler-level resolution. On Windows, environment variable names are
case-insensitive, so the lowercase-only protection for `HTTP_PROXY` is not
possible; outside the CLI SAPI on Windows, proxy environment variables are
therefore not resolved at all, and the `proxy` request option must be used
instead.

Separately from the handler-level resolution above, a `GuzzleHttp\Client` maps
the uppercase `HTTP_PROXY` (CLI SAPI only), `HTTPS_PROXY`, and `NO_PROXY`
environment variables into a default for the `proxy` request option. The client
mapping reads `$_SERVER` first, so it does honor SAPI-provided values such as
those set with `fastcgi_param` or `SetEnv`. See
[Environment Variables](quick-start.md#environment-variables).

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
> `CURLOPT_PROXY` is rejected; use the `proxy` request option for the proxy
> URL. A non-empty custom proxy authentication value sent with
> `CURLOPT_PROXYHEADER` is always sectioned because libcurl cannot key
> connection reuse on those header values. On libcurl 8.20.0 and newer,
> credential sectioning for option-supplied credentials is left to libcurl's own
> credential-aware connection matching.
>
> SOCKS proxies authenticate the connection itself rather than a CONNECT
> tunnel, and libcurl versions before 7.69.0 do not compare SOCKS credentials
> when matching a pooled connection for reuse, so on those versions Guzzle
> sections every SOCKS-proxied request by its proxy and credential state —
> plain `http://` targets and credential-less requests included. From libcurl
> 7.69.0, SOCKS credential matching is left to libcurl.
>
> A configured share handle forces every SOCKS request onto a fresh,
> non-reusable connection before libcurl 7.69.0 because its provenance is
> opaque to `CurlFactory`. This applies to Guzzle-managed `transport_sharing`
> handles too, even though they never share connection caches themselves.
> Caller-supplied false `CURLOPT_FRESH_CONNECT` and `CURLOPT_FORBID_REUSE`
> values cannot disable this rule.
>
> Sectioning has a cost in mixed workloads: changing the proxy credentials in
> use discards the idle pooled connections held for the previous credentials,
> which also drops unrelated direct keep-alive connections pooled alongside
> them, and alternating anonymous and authenticated traffic through the same
> proxy repeats that cost on each switch. Advanced users can still control cURL
> connection reuse explicitly with the `curl` request option and
> `CURLOPT_FRESH_CONNECT` or `CURLOPT_FORBID_REUSE`.

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

Query strings specified in the `query` option will overwrite all query string
values supplied in the URI of a request.

```php
// Send a GET request to /get?foo=bar
$client->request('GET', '/get?abc=123', ['query' => ['foo' => 'bar']]);
```

## read_timeout

Summary
Number of seconds the connection may sit silent at any stage of the request.
Use `0` to disable the idle timeout. Positive values below `0.001` seconds are
rejected by the built-in stream handler.

Types
- int
- float

Default
`60`

Constant
`GuzzleHttp\RequestOptions::READ_TIMEOUT`

The idle timeout bounds the silence between packets while connecting, while
waiting for response headers, and between reads on the response body, whether
the body is buffered or streamed; any received data resets it. It is
implemented by the PHP stream handler. cURL handlers accept this option
without effect so shared request configuration can be reused across
transports. With Guzzle's default handler selection, streamed responses
(`stream` => `true`) use the stream handler when PHP streams are available.
When the stream handler buffers a response with [`timeout`](#timeout) set,
each body read is bounded by the shorter of the idle timeout and the remaining
total timeout.

See [Timeout phases](#timeout-phases) for a summary of the timeout options
across the built-in handlers.

When a read on the streamed PSR-7 body times out, `read()` throws
`GuzzleHttp\Psr7\Exception\TimeoutException`. A non-timeout read failure, for
example the connection is reset mid-body or the body stream has been detached,
throws a plain `\RuntimeException`. Both types sit outside the
`GuzzleHttp\Exception\GuzzleException` hierarchy: they are raised after the
request has resolved and the response object has been returned, which is outside
the `Client::sendRequest()` transfer that PSR-18 governs. Catch them explicitly
when reading a streamed body. If you detach the body and read the raw PHP stream
resource instead, functions such as `fgets()` return `false` on timeout,
following PHP's stream semantics.

A timed-out read does not invalidate the streamed body. Catching the
`TimeoutException` and calling `read()` again resumes waiting for data, so
idle timeouts can serve as poll ticks on long-lived streams.

```php
$response = $client->request('GET', '/stream', [
    'stream' => true,
    'read_timeout' => 10,
]);

$body = $response->getBody();

// Throws GuzzleHttp\Psr7\Exception\TimeoutException on timeout
$data = $body->read(1024);

// A timed-out read can be retried, so idle timeouts work as poll ticks
try {
    $data = $body->read(1024);
} catch (\GuzzleHttp\Psr7\Exception\TimeoutException $e) {
    // Check for cancellation, then call read() again to resume waiting.
}

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

This option can be set on a client or per request. It affects request-side
object creation only and does not affect response implementations returned by
handlers.

> [!NOTE]
> This option only affects requests created by `request()`, `requestAsync()`,
> and shortcut methods such as `get()` and `post()`. Requests passed to
> `send()`, `sendAsync()`, or `sendRequest()` are used as provided.

> [!WARNING]
> Guzzle only checks that the value implements
> `Psr\Http\Message\RequestFactoryInterface`; it does not validate the requests
> it returns. Per PSR-7 the `Host` header is derived from the request URI, and
> Guzzle does not recompute it afterwards, so a factory that fails to set `Host`
> from the URI — or that lets a caller-controlled `Host` diverge from the URI
> actually dialed — can cause host confusion, cache poisoning, or requests
> routed to an unexpected origin. Supply a request implementation you trust to
> follow PSR-7.

## response_factory

Summary
PSR-17 response factory used by the built-in handlers when creating the response
message.

Types
`Psr\Http\Message\ResponseFactoryInterface`

Default
`GuzzleHttp\Psr7\HttpFactory`

Constant
`GuzzleHttp\RequestOptions::RESPONSE_FACTORY`

```php
$factory = new \GuzzleHttp\Psr7\HttpFactory();

$client->request('GET', '/get', [
    'response_factory' => $factory,
]);
```

This option can be set on a client or per request. The built-in cURL and stream
handlers build the response message (status code, reason phrase, headers, and
protocol version) with this factory, and create the response body stream with
the configured `stream_factory` where practical. The factory should return an
empty, header-less response, because the handlers apply the parsed status line,
headers, and body themselves.

> [!NOTE]
> This option is consumed by the built-in handlers when they create a response.
> `MockHandler` returns the responses you queue, and custom handlers are
> responsible for honoring this option themselves.

> [!WARNING]
> Guzzle only checks that the value implements
> `Psr\Http\Message\ResponseFactoryInterface`; it does not validate the
> responses it returns. The handlers add each parsed header to the returned
> response with `withAddedHeader()`, so a factory that pre-seeds headers (or
> returns a non-empty response) duplicates them — for example emitting two
> `Content-Type` or `Content-Length` values and corrupting the message. The
> factory should return an empty, header-less response, and must reject CR/LF in
> any header it sets itself, or it can re-introduce header-injection and
> response-splitting issues. Supply a response implementation you trust.

## retries

Summary
Current retry count used by `GuzzleHttp\Middleware::retry()`.

Types
int

Default
`0` when retry middleware is used

Constant
`GuzzleHttp\RequestOptions::RETRIES`

The retry middleware initializes this option to `0` before the first attempt and
increments it before each retry. Applications may seed it on a per-request basis
when using the retry middleware.

## stream_factory

Summary
PSR-17 stream factory used when Guzzle creates request body streams and, for the
built-in handlers, response body streams where practical.

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

This option can be set on a client or per request. It is used when Guzzle
converts supported `body`, `form_params`, or `json` request option values into
`Psr\Http\Message\StreamInterface` instances, when redirect handling resets a
request body, and by the built-in cURL and stream handlers when they wrap
response body resources where practical. Request bodies that already implement
`Psr\Http\Message\StreamInterface` are used as provided.

> [!NOTE]
> This option does not replace every stream. Callable and iterator request
> bodies use Guzzle's existing PSR-7 stream handling, multipart internals are
> left untouched, string path sinks open lazily, and caller-owned resource sinks
> keep Guzzle's own stream wrapper so write-only sink resources remain
> supported. `MockHandler` queued responses and custom handler responses are
> used as provided. When decoding gzip/deflate responses, the factory still
> wraps the underlying transport resource, but Guzzle layers its own
> `InflateStream` decorator on top because PSR-17 cannot express a decoding
> stream.

> [!WARNING]
> Guzzle only checks that the value implements
> `Psr\Http\Message\StreamFactoryInterface`; it does not validate the streams it
> returns. For responses, `createStreamFromResource()` wraps the live transport
> socket, so the returned stream must behave like the default
> `GuzzleHttp\Psr7\Stream`. It must expose the resource's **live** `timed_out`
> metadata: a stream that snapshots metadata, or omits the `timed_out` key,
> silently disables read-timeout detection, so a stalled read is reported as a
> successful but truncated response instead of a timeout — with no error at all
> for chunked or `Connection: close` bodies. It must also **close the underlying
> resource** when closed, or each request leaks a socket or file descriptor
> (including `HEAD` and `stream => true` responses, which are not drained),
> eventually exhausting the descriptor limit. The stream must be readable for
> gzip/deflate decoding, and should stream the resource rather than buffer it
> entirely into memory. The full contract is described under *Creating Streams*
> in the
> [PSR-7 stream documentation](https://github.com/guzzle/psr7/blob/3.0/docs/streams-and-decorators.md#creating-streams).
> Supply a stream implementation you trust.

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

This option can be set on a client or per request. It is used for string request
URI values, string `base_uri` values, and redirect `Location` header parsing
when redirects are enabled. URI objects implementing
`Psr\Http\Message\UriInterface` are used as provided.

> [!NOTE]
> This option affects request-side URI creation only. It does not affect
> response implementations returned by handlers.
> `GuzzleHttp\Client::sendRequest()` still returns redirect responses as-is for
> PSR-18 compliance.

> [!WARNING]
> Guzzle only checks that the value implements
> `Psr\Http\Message\UriFactoryInterface`; it does not validate the URIs it
> returns. When following redirects Guzzle strips credentials by comparing the
> origin (scheme, host, port) of the current and redirect-target URIs: on a
> cross-origin redirect it removes the `Authorization` and `Cookie` headers and
> clears HTTP auth, and it drops `Referer` whenever the scheme changes
> (including an `https` to `http` downgrade) and otherwise sends only the origin
> on cross-origin redirects — all by reading `getScheme()`, `getHost()`, and
> `getPort()` from the URI built from the `Location` header. A custom URI that
> misreports those, or whose getters disagree with the address actually dialed,
> can make a cross-origin redirect look same-origin and leak credentials to the
> target (the class of issue behind CVE-2022-31042, CVE-2022-31043,
> CVE-2022-31090, and CVE-2022-31091), or enable SSRF and protocol allow-list
> bypass. The default `GuzzleHttp\Psr7\Uri` lower-cases and validates the scheme
> and host and strips default ports; supply a URI implementation you trust.

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

Pass a string to specify the path to a file that will store the contents of the
response body:

```php
$client->request('GET', '/stream/20', ['sink' => '/path/to/file']);
```

Pass a resource returned from `fopen()` to write the response to a PHP stream:

```php
$resource = \GuzzleHttp\Psr7\Utils::tryFopen('/path/to/file', 'w');
$client->request('GET', '/stream/20', ['sink' => $resource]);
```

Pass a `Psr\Http\Message\StreamInterface` object to stream the response body to
an open PSR-7 stream.

```php
$resource = \GuzzleHttp\Psr7\Utils::tryFopen('/path/to/file', 'w');
$stream = \GuzzleHttp\Psr7\Utils::streamFor($resource);
$client->request('GET', '/stream/20', ['sink' => $stream]);
```

With Guzzle's built-in cURL and PHP stream handlers, non-streaming responses use
the sink stream as the response body. The handlers rewind seekable sink streams
before returning the response. If the sink is not seekable, the request still
succeeds and the response body is left at the sink's current position, usually
EOF.

Digest authentication buffers intermediate challenge bodies away from a
configured sink. A terminal 401 response, such as authentication failure or no
usable Digest challenge, is the final response and its body is restored into the
configured sink. Non-challenge response bodies, including redirect-hop bodies,
follow normal sink behavior; with resource or stream sinks, redirect-hop bodies
may be written before the final response body for Digest and non-Digest requests
alike.

If `sink` is a string path, Guzzle opens the file and owns that stream.

If `sink` is a PHP resource, the caller owns the resource and is responsible for
closing it. Closing or destroying the response body detaches Guzzle's wrapper
without closing the original resource.

If `sink` is a `Psr\Http\Message\StreamInterface`, Guzzle uses that stream
object as-is and its own `close()` behavior applies.

## ssl_key

Summary
Specify the path to a file containing a private SSL key. PEM is the default
private key format. If a password is required, then set to an array containing
the path to the SSL key in the first array element followed by the password
required for the key in the second element. A `null` password is treated the
same as omitting it. Use [`ssl_key_type`](#ssl_key_type) to specify another
supported key format.

Types
- string
- array

Default
None

Constant
`GuzzleHttp\RequestOptions::SSL_KEY`

> [!NOTE]
> With the stream handler, `cert` and `ssl_key` must use the same passphrase
> when both options specify one because PHP streams expose only one SSL context
> passphrase.

> [!NOTE]
> TLS client key options remain active during redirects. See
> [Cross-Origin Redirects](#cross-origin-redirects) for details.

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

The cURL handler passes this value to `CURLOPT_SSLKEYTYPE`. Supported values
depend on libcurl and its TLS backend.

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
> Streaming response support must be implemented by the HTTP handler used by a
> client. This option might not be supported by every HTTP handler, but the
> interface of the response object remains the same regardless of whether or not
> it is supported by the handler.

With Guzzle's default handler selection, streamed responses use the stream
handler when PHP streams are available. The [`timeout`](#timeout) deadline then
applies to obtaining the response and does not bound reads on the streamed body:
each read is bounded by the [`read_timeout`](#read_timeout) idle timeout, and a
timed-out read throws `GuzzleHttp\Psr7\Exception\TimeoutException` and can be
retried. The built-in cURL handlers download the body up-front even when
`stream` is enabled, so the deadline covers the whole transfer.

Handlers that do not support `stream` fall back to `sink` behavior. Digest
authentication still protects configured sinks from intermediate challenge
bodies. With `StreamHandler`, `stream => true`, and a configured `sink`, a
Digest request drains the final body into the sink while a non-Digest request
leaves the sink untouched.

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
array

Default
None

Constant
`GuzzleHttp\RequestOptions::STREAM_CONTEXT`

This option is only supported by the built-in stream handler. Built-in cURL
handlers reject this option because cURL does not use PHP stream contexts.

Stream context options outside the built-in stream handler allow-list, or not
available in the current PHP runtime, are rejected. Allow-listing means Guzzle
passes the option through; PHP or OpenSSL may still reject or ignore an option
depending on the runtime. The allow-list is `http.request_fulluri`,
`socket.bindto`, `socket.tcp_nodelay`, `ssl.SNI_enabled`,
`ssl.capture_peer_cert`, `ssl.capture_peer_cert_chain`, `ssl.ciphers`,
`ssl.disable_compression`, `ssl.no_ticket`, `ssl.peer_fingerprint`,
`ssl.security_level`, and `ssl.verify_depth`. Use Guzzle request options
instead when configuring the request method, URI, body, headers, timeouts,
redirects, proxy, TLS certificate files, TLS private keys, protocol versions,
verification, progress, debug output, sinks, cookies, and allowed protocols.
TLS minimum protocol versions are managed through the `crypto_method` request
option, maximum protocol versions are managed through the `crypto_method_max`
request option, and TLS verification is managed through the `verify` request
option.

## synchronous

Summary
Set to true to inform HTTP handlers that you intend on waiting on the response.
This can be useful for optimizations.

Types
bool

Default
none

Constant
`GuzzleHttp\RequestOptions::SYNCHRONOUS`

## verify

Summary
Describes the SSL certificate verification behavior of a request.

> - Set to `true` to enable SSL certificate verification and use the default CA
>   bundle provided by operating system.
> - Set to `false` to disable certificate verification (this is insecure!).
> - Set to a string to provide the path to a CA bundle to enable verification
>   using a custom certificate.

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

If you do not need a specific certificate bundle, then Mozilla provides a
commonly used CA bundle which can be downloaded
[here](https://curl.se/ca/cacert.pem) (provided by the maintainer of cURL). Once
you have a CA bundle available on disk, you can set the "openssl.cafile" PHP ini
setting to point to the path to the file, allowing you to omit the "verify"
request option. Much more detail on SSL certificates can be found on the
[cURL website](https://curl.se/docs/sslcerts.html).

## timeout

Summary
Number of seconds to use as the total timeout of the request. Use `0` to
disable the total timeout (the default behavior). Positive values below
`0.001` seconds are rejected by the built-in handlers.

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

The built-in cURL handlers enforce the timeout for the whole transfer. The
built-in stream handler enforces it while connecting and while buffering the
response: the transfer is aborted once the deadline passes. While waiting for
response headers the stream handler can only bound the time between packets,
so a response whose header block arrives in small pieces past the deadline is
rejected as soon as the headers complete. With the `stream` option enabled,
the deadline applies to obtaining the response and does not bound reads on the
streamed body.

The deadline never governs how long the connection may sit silent; that is the
role of [`read_timeout`](#read_timeout), the idle timeout, which applies at
every stage of a stream handler request and defaults to 60 seconds. The stream
handler does not consult the `default_socket_timeout` ini setting.

Built-in handlers use the most specific transport timeout exception they can
determine. Connect timeouts throw
`GuzzleHttp\Exception\ConnectTimeoutException`, which extends
`ConnectException`. Other transport timeouts before a response is received throw
`GuzzleHttp\Exception\NetworkTimeoutException`. Transport timeouts after a
response is received throw `GuzzleHttp\Exception\ResponseTimeoutException`,
which extends `GuzzleHttp\Exception\ResponseTransferException` and exposes the
response.

Timeouts from caller-supplied PSR-7 streams are not transport timeouts. A
request body stream timeout while detecting size, buffering, rewinding, or
reading upload bytes throws `GuzzleHttp\Exception\RequestException` before a
response and `GuzzleHttp\Exception\ResponseException` after response headers. A
slow response `sink` write also throws `ResponseException`.

Timeout detection is best-effort: it relies on the stream exposing PHP's
`timed_out` metadata. The network socket Guzzle opens for the stream handler
exposes this metadata, but a caller-supplied request-body or `sink` stream might
not, for example a custom `StreamInterface` implementation. When the metadata is
absent, the failure is not recognized as a timeout and surfaces as an ordinary
read/write error instead.

### Timeout phases

The following table shows the timeouts that bound each phase of a request sent
by the built-in handlers and the exception thrown when one is exceeded. The
deadline is this `timeout` option; the idle timeout is
[`read_timeout`](#read_timeout).

| Phase | cURL handlers | Stream handler |
| --- | --- | --- |
| Connecting | Bounded by [`connect_timeout`](#connect_timeout) and the deadline; times out with `ConnectTimeoutException` | Bounded by the idle timeout and the deadline; times out with `ConnectTimeoutException` |
| Receiving headers | Bounded by the deadline; aborted mid-headers with `NetworkTimeoutException` | Packet gaps are bounded by the idle timeout and the remaining deadline; a header block completing past the deadline is rejected with `ResponseTimeoutException` |
| Body, buffered (default) | Bounded by the deadline; aborted with `ResponseTimeoutException` | Bounded by the deadline and, on each read, the idle timeout; aborted with `ResponseTimeoutException` |
| Body, `stream` enabled | The body is downloaded up-front regardless, so the deadline applies as above | Not bounded by the deadline; each read is bounded by the idle timeout, and a timed-out read throws a retryable `GuzzleHttp\Psr7\Exception\TimeoutException` |

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

Guzzle defaults to HTTP/1.1. Set `version` when you want a request to use or
attempt a different HTTP protocol version. Guzzle does not opt requests into
HTTP/2 or HTTP/3 automatically just because the installed cURL stack supports
them.

The built-in stream handler supports only `1.0` and `1.1`. The built-in cURL
handler supports `1.0`, `1.1`, `2.0`, and `3.0`, but HTTP/2 and HTTP/3 depend on
the PHP cURL extension and the linked runtime libcurl capabilities.

Empty or malformed `version` values are rejected before the request is sent. If
the value is well-formed but the selected handler cannot use it, the transfer
fails with `GuzzleHttp\Exception\RequestException`. For example, the stream
handler rejects HTTP/2 and HTTP/3, the cURL handler rejects HTTP/2 when libcurl
does not report HTTP/2 support, and the cURL handler rejects HTTP/3 when the
installed cURL stack does not report HTTP/3 support.

For cURL requests, `version` is converted to Guzzle-managed cURL options. Use
this request option instead of passing raw `CURLOPT_HTTP_VERSION`; built-in cURL
handlers reject raw cURL options that conflict with Guzzle-managed protocol
handling.

HTTP/2 uses libcurl's `CURL_HTTP_VERSION_2_0`. HTTP/3 uses libcurl's
`CURL_HTTP_VERSION_3` unless `multiplex` is set to `Multiplexing::REQUIRE_EAGER`
or `Multiplexing::REQUIRE_WAIT`, which use libcurl's strict HTTP/3-only mode.
The non-required modes ask libcurl to attempt the requested protocol, but they
are not strict modes: libcurl may use a lower HTTP version when negotiation or
connection setup falls back. The response protocol version can therefore be
lower than the `version` value you requested.

When multiple HTTP/2- or HTTP/3-capable requests start concurrently against the
same origin, the `multiplex` request option controls whether they wait to share
one connection instead of each opening their own.

HTTP/3 support requires PHP to expose cURL's HTTP/3 constants, runtime libcurl
7.88.0 or higher, and runtime libcurl reporting the `CURL_VERSION_HTTP3`
feature. A libcurl version number is not enough by itself: libcurl must also be
built with HTTP/3 and QUIC support, commonly through an HTTP/3 backend such as
ngtcp2 with nghttp3 or quiche.

A request configured with `version => 3.0` must pass HTTP/3 support checks even
if it uses a proxy. If a proxy is actually selected, whether through the `proxy`
option or resolved from the environment, Guzzle does not try HTTP/3 through the
proxy in non-required modes; it sends the transfer as HTTP/2 when available,
otherwise HTTP/1.1. Required multiplexing rejects proxied HTTP/3 because it
cannot guarantee HTTP/3 through the proxy. A matching proxy `no` rule or
environment `no_proxy` entry makes the request direct, so HTTP/3 can still be
attempted.

For HTTPS cURL requests, Guzzle sets a minimum of TLS 1.2 by default. That
minimum also applies when HTTP/2 or HTTP/3 is requested, because cURL may fall
back to a TLS-based HTTP/1.1 or HTTP/2 connection. If you set `crypto_method` to
TLS 1.3, Guzzle keeps that stricter setting when the cURL stack exposes TLS 1.3
configuration. Raw `CURLOPT_SSLVERSION` values passed through the `curl` option
are rejected because TLS version handling is managed by Guzzle. The
`crypto_method_max` request option can cap the maximum TLS version, but the cap
must not be lower than the effective minimum (TLS 1.2 by default for HTTPS
unless `crypto_method` is set lower).
