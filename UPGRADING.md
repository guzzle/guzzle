Guzzle Upgrade Guide
====================

7.0 to 8.0
----------

Guzzle 8 is a major release that raises the minimum PHP version, updates the
Guzzle dependency stack, adopts stricter PSR-7 header and request method
behavior, changes some network exception classification, and tightens validation
for request options, protocols, transport settings, cookies, and native method
signatures. It also adds generic PHPDoc types to async APIs for static analysis.

#### PHP Version and Dependencies

Guzzle 8 requires PHP `^7.4 || ^8.0`. Guzzle 7 supported PHP
`^7.2.5 || ^8.0`.

Guzzle 8 also requires Guzzle Promises 3.x and Guzzle PSR-7 3.x. Guzzle 7
supported Guzzle Promises `^2.3` and Guzzle PSR-7 `^2.8`.

Read the [Guzzle PSR-7 3.x upgrade guide][psr7-upgrade-guide] as part of every
Guzzle 8 upgrade. Guzzle's normal request and response APIs use Guzzle PSR-7, so
PSR-7 changes can affect applications even when they do not instantiate PSR-7
classes directly. This guide calls out the most common inherited PSR-7 changes,
but it does not repeat every PSR-7 behavior change.

Pay particular attention to [that PSR-7 upgrade guide][psr7-upgrade-guide] if
your application constructs requests, modifies responses, sets headers, uploads
files, builds multipart requests, works with URIs, or inspects streams.

Also read the [Guzzle Promises 3.x upgrade guide][promises-upgrade-guide] if you
use async requests, custom handlers, middleware, pools, or promise helpers.

Guzzle 8 now requires `psr/http-factory:^1.0` directly.

[psr7-upgrade-guide]: https://github.com/guzzle/psr7/blob/3.0/UPGRADING.md
[promises-upgrade-guide]: https://github.com/guzzle/promises/blob/3.0/UPGRADING.md

#### PSR-7 Header Values and Request Methods

Guzzle 8 uses Guzzle PSR-7 3.x, and several of its behavior changes surface
through normal Guzzle client usage. This section summarizes the inherited PSR-7
changes most likely to affect Guzzle users; read the
[Guzzle PSR-7 3.x upgrade guide][psr7-upgrade-guide] for the complete list.

The highest-impact inherited changes are stricter header value validation and
preserved request method casing. Header values passed through the `headers`
request option or PSR-7 request APIs must now be strings or non-empty arrays of
strings. Empty strings remain valid explicit header values, but empty arrays,
`null`, `false`, integers, floats, and other non-string values are no longer cast
or accepted.

If your application builds headers from configuration, user input, or typed
domain values, normalize them before creating or sending requests:

```php
// 7.x, no longer accepted in 8.0
$client->request('GET', '/', [
    'headers' => ['Api-Version' => 1],
]);

// 8.0
$client->request('GET', '/', [
    'headers' => ['Api-Version' => '1'],
]);
```

Guzzle 8 also preserves explicitly provided request method casing. HTTP method
names are case-sensitive, so Guzzle now sends the method exactly as provided and
applies built-in method-specific behavior only to exact standard method names
such as `GET`, `HEAD`, `POST`, and `PUT`.

If you previously relied on `new Request('get', ...)` or
`$client->request('get', ...)` being sent or treated as `GET`, normalize the
method before constructing or sending the request:

```php
$client->request('GET', 'https://example.com');
```

The convenience methods such as `$client->get()`, `$client->post()`, and their
async variants continue to use uppercase standard methods.

The built-in cURL and stream handlers now reject request `Content-Length` values
that are malformed or conflicting before starting a transfer. Requests that
previously succeeded after passing an invalid `Content-Length` header may now
fail with `RequestException`. Duplicate `Content-Length` values are accepted
only when every member has the same decimal value, including values with leading
zeros. Valid lengths larger than `PHP_INT_MAX` now fail before the request is
sent because built-in handlers cannot safely size such transfers on the current
platform.
Applications that set this header manually should send one valid non-negative
decimal length or omit it and let Guzzle prepare the body headers.

#### Exception Hierarchy and Classification

Some Guzzle 8.0 exception changes add intermediate classes, while others
intentionally reclassify specific failures. Broad `TransferException` and
`GuzzleException` catch blocks still catch all Guzzle transfer failures, and
`RequestException` still catches response-aware and other non-network request
failures. More specific catch blocks need auditing: Guzzle 8.0 splits timeout
failures into connect-phase, no-response network, and response-aware timeout
classes, and the built-in cURL and stream handlers classify more transport
failures by transfer phase. This section covers upgrading from Guzzle 7.x to
Guzzle 8.0. The `ConnectException` inheritance change happened earlier, in
Guzzle 7.0.0, when it moved out from under `RequestException`; see the 6.0 to
7.0 notes for that migration. The hierarchy changes are easiest to compare in
the two trees below.

Guzzle 7.x uses this hierarchy:

```text
. \RuntimeException
└── TransferException (implements GuzzleException)
    ├── ConnectException (implements NetworkExceptionInterface)
    └── RequestException (implements RequestExceptionInterface)
        ├── BadResponseException
        │   ├── ClientException
        │   └── ServerException
        └── TooManyRedirectsException
```

Guzzle 8.0 uses this hierarchy:

```text
. \RuntimeException
└── TransferException (implements GuzzleException)
    ├── HandlerClosedException
    ├── NetworkException (implements NetworkExceptionInterface)
    │   ├── ConnectException
    │   │   └── ConnectTimeoutException
    │   └── NetworkTimeoutException
    └── RequestException (implements RequestExceptionInterface)
        └── ResponseException
            ├── BadResponseException
            │   ├── ClientException
            │   └── ServerException
            ├── ResponseTransferException
            │   └── ResponseTimeoutException
            └── TooManyRedirectsException
```

`NetworkException` is new in Guzzle 8.0 as the base class for no-response
network failures. Throughout Guzzle 7.x, `ConnectException` implements
`Psr\Http\Client\NetworkExceptionInterface` directly and there is no
Guzzle-specific network base class. Reusable packages that support both Guzzle
7.x and 8.0 should catch `Psr\Http\Client\NetworkExceptionInterface` for
no-response network failures. Applications or packages that require Guzzle 8.0
may catch `GuzzleHttp\Exception\NetworkException` for all no-response network
failures. Keep catching `ConnectException` only when handling connection
establishment failures specifically.

Guzzle 8.0 also makes response-aware request failures explicit. Guzzle 7.x does
not have `ResponseException`, and response-aware request failures remain under
`RequestException` throughout Guzzle 7.x. In Guzzle 8.0, `ResponseException` is
the base class for request failures where response headers were received and a
response object is available. `ResponseTransferException` is used for
transfer-level failures after headers, including response-aware network,
protocol, content-decoding, partial-body, and response-body transfer failures.
`BadResponseException` and `TooManyRedirectsException` also extend
`ResponseException`.

Only this branch exposes response access: `RequestException` no longer stores
responses, no longer accepts a response constructor argument, and no longer has
`getResponse()` or `hasResponse()` methods. Catch `ResponseException`, or test
with `instanceof ResponseException`, before calling `getResponse()`.

Timeout exception classes are now split by the transport phase the handler can
determine. `ConnectTimeoutException` is thrown for detected connect timeouts
(DNS resolution, TCP connect, proxy CONNECT, or TLS handshake). It extends
`ConnectException`, so code that catches `ConnectException` will also catch
connect timeouts. `NetworkTimeoutException` is thrown for other detected
transport timeouts before response headers are received. It extends
`NetworkException`, but not `ConnectException`. `ResponseTimeoutException` is
thrown for response transfer timeouts after response headers are received. It
extends `ResponseTransferException` and exposes the response.

Timeouts that originate from caller-supplied PSR-7 streams are not transport
timeouts. Reading the request body stream, including size detection,
stringification, rewind, and upload reads, is a `RequestException` before a
response and a `ResponseException` after response headers. A slow response
`sink` write is a `ResponseException` once a response exists, or a
`RequestException` otherwise. Whenever the timeout comes from a PSR-7 stream,
the original `GuzzleHttp\Psr7\Exception\TimeoutException` is available via
`getPrevious()`.

If a handler throws `Error`, `TypeError`, or another non-`Exception` `Throwable`
before returning a promise, `Client::sendAsync()` returns a rejected promise.
Waiting on it rethrows the original throwable. During request and response body
handling outside native cURL callbacks, Guzzle still wraps `\Exception` failures
as `RequestException` or `ResponseException` where appropriate, while
non-`Exception` throwables propagate unchanged. Native cURL callbacks remain
different. A throwable from a cURL `sink` write is wrapped as
`ResponseException` when a response was received, or `RequestException`
otherwise, and the cURL handlers continue to report `CURLE_WRITE_ERROR` as
`on_stats` handler error data.

`HandlerClosedException` is new in Guzzle 8.0. It extends `TransferException`
and is used when an explicitly closed `CurlMultiHandler` rejects transfers that
are still pending, including delayed transfers. Existing Guzzle 7.x code should
not normally see this exception just by upgrading. Guzzle 7.x did not have this
exception or a public cURL handler `close()` method, and destructor cleanup did
not reject pending promises. If you add deterministic cleanup with
`CurlMultiHandler::close()`, wait for or cancel outstanding transfers first, or
handle `HandlerClosedException` or `TransferException` for pending promises you
may still observe.

When updating catch blocks for Guzzle 8.0, catch the more specific no-response
and response-aware failures before `RequestException`:

```php
use GuzzleHttp\Exception\NetworkException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\Exception\ResponseTransferException;

try {
    $client->request('GET', $uri);
} catch (NetworkException $e) {
    // No-response network failures, including reclassified cURL and stream
    // handler failures.
} catch (ResponseTransferException $e) {
    $response = $e->getResponse();

    // Transfer-level failures after response headers were received.
} catch (ResponseException $e) {
    $response = $e->getResponse();

    // Other request failures after response headers were received.
} catch (RequestException $e) {
    // Request failures where Guzzle does not expose a response object.
}
```

The built-in handlers also reclassify several failures that Guzzle 7.x reported
as `RequestException` or `ConnectException`:

- Connection-establishment failures (DNS and proxy resolution, TCP connect, TLS
  setup, and QUIC connect) are `ConnectException`.
- Other failures with no response, such as send and receive errors and
  no-response HTTP/2 and HTTP/3 protocol errors, are `NetworkException`.
- Response-transfer failures after response headers were received are
  `ResponseTransferException`. This includes response-aware cURL connection,
  network, protocol, content-decoding, partial-body, and response body transfer
  failures. Response-body network stalls are `ResponseTimeoutException`, while
  `sink` write failures, progress callback failures, deterministic response
  size/platform-limit failures, or request-body stalls after headers are plain
  `ResponseException` instances.
- Post-transfer response finalization failures are also plain
  `ResponseException`. A seekable response sink that fails to rewind does not
  become a `ResponseTransferException`. Non-seekable sinks are not rewound, and
  source close cleanup after a complete stream-handler download is best effort.
- Other non-transfer or local failures that occur after a response was received
  are `ResponseException`.

This applies to both the cURL and stream handlers; the exact error codes and
messages each one maps onto these classes are an implementation detail.

The cURL handler no longer treats non-`101` informational responses such as
`100 Continue`, `102 Processing`, or `103 Early Hints` as the final response. If
a cURL transfer fails after receiving only one of those interim responses,
Guzzle now reports a no-response failure such as `NetworkException` instead of a
`ResponseTransferException` carrying the interim `1xx` response. Code that
previously caught this path with `RequestException` or
`RequestExceptionInterface` should catch `NetworkExceptionInterface` or
`TransferException` instead. `101 Switching Protocols` is unchanged and is still
surfaced as a response.

The stream handler now rejects a drained, non-streamed response when a valid,
positive `Content-Length` declares more bytes than the handler receives or
cannot be represented as a PHP integer on the current platform. Short bodies
raise `ResponseTransferException`; platform-size failures raise plain
`ResponseException` with the underlying `OverflowException` available via
`getPrevious()`. This matches the cURL handler for identity-coded responses,
compressed responses with `decode_content` disabled, and unsupported compressed
codings that the stream handler leaves raw. It does not apply to
`stream => true` responses, decoded gzip or deflate responses after
`Content-Length` has been removed, chunked or other `Transfer-Encoding`
responses, or conflicting or malformed `Content-Length` values. Responses to
`HEAD`, any `1xx`, `204`, `304`, and successful `CONNECT` requests are never
checked because they are bodiless by framing. `205 Reset Content` is checked
because it remains framed by `Content-Length`.

The deprecated `RequestException::wrapException()` method was removed. Create a
`RequestException` directly for request failures where Guzzle does not expose a
response object. Its third constructor argument is now the exception code,
followed by the previous exception. For failures with a response, create
`ResponseException`, `ResponseTransferException`, `ResponseTimeoutException`,
`BadResponseException`, `ClientException`, `ServerException`, or
`TooManyRedirectsException` instead. If you used the removed
`getHandlerContext()` methods to classify failures, use the more granular
exception classes above instead, or use `on_stats` when you need handler timing
or statistics. `GuzzleHttp\Exception\InvalidArgumentException` remains outside
the transfer exception hierarchy and is still used for invalid configuration or
request option values that can be rejected before a transfer starts.

#### Body Summaries In HTTP Error Exceptions

Guzzle's default `http_errors` middleware uses `BodySummarizer` to include a
short response body summary in response-aware exception messages. In Guzzle 7,
creating that summary rewound seekable response bodies to the beginning. In
Guzzle 8, `BodySummarizer` still summarizes from the beginning of the body, but
then restores the body cursor to its previous position.

Most applications do not need changes. Check only code that catches
response-aware exceptions from `http_errors` and then reads the response body
while relying on exception creation to leave that body rewound. If you need to
read the body from the beginning after catching the exception, call
`Message::rewindBody()` explicitly.

#### Request Protocol Versions

Invalid request protocol versions are no longer treated as omitted. Passing
`'version' => ''`, `'version' => 'HTTP/1.1'`, or sending a PSR-7 request whose
protocol version is empty or malformed now fails before the request is sent.

Omit the `version` request option to use Guzzle's default HTTP/1.1 behavior, or
pass an explicit supported protocol version such as `'1.1'`. Do not include the
`HTTP/` prefix.

Invalid `version` request option values are still rejected with
`GuzzleHttp\Exception\InvalidArgumentException` before a request is sent.
Empty or malformed protocol versions returned by a `RequestInterface`, and
well-formed but unsupported protocol versions such as HTTP/3 with the stream
handler, now fail with `GuzzleHttp\Exception\RequestException`. If you
previously caught `InvalidArgumentException` or `ConnectException` for request
protocol version failures, catch `RequestException` or `GuzzleException`
instead.

#### Callback Semantics

If you use the `progress` request option with the built-in cURL handlers, audit
callbacks for return values. Any truthy return value now aborts the transfer and
rejects the request with `ResponseException` when a response is available, or
`RequestException` otherwise. Return `0`, `false`, or nothing to keep the
transfer running.

If a cURL `progress` callback throws, catch `ResponseException` for
response-aware failures or `RequestException` for broader request failures, and
inspect `getPrevious()` for the original throwable. The throwable no longer
escapes directly from the native cURL callback.

The stream handler still ignores `progress` return values.

Exceptions thrown by `on_stats` remain unwrapped, so existing catch logic for
`on_stats` exceptions does not need to change. The built-in cURL handlers now
release native easy handles before invoking `on_stats`. Built-in handlers now
reject non-callable `on_stats` values before starting the transfer. Raw callbacks
passed through the `curl` request option remain low-level cURL callbacks and are
not normalized by Guzzle.

The `on_headers` request option callback now receives the request as its second
argument. Existing userland callbacks that accept only the response continue to
work, but callbacks that inspect all arguments, for example with
`func_get_args()` or a variadic parameter, will observe the additional
`Psr\Http\Message\RequestInterface` argument.

The built-in handlers invoke `on_headers` for the final response headers and for
`101 Switching Protocols`, but not for other informational `1xx` responses such
as `100 Continue` or `103 Early Hints`. Guzzle does not expose a separate Early
Hints API; a dedicated interim-response hook may be added in a future minor
release.

#### Sink Resource Ownership

PHP resources passed as the `sink` request option are no longer closed when the
response body is closed or garbage-collected by the built-in cURL and stream
handlers. Applications that relied on Guzzle closing a raw resource sink should
close the resource explicitly or pass a string path instead.

#### TLS Minimum Version

The built-in cURL and stream handlers now default HTTPS requests to TLS 1.2 or
newer. Applications that must connect to legacy TLS 1.0 or TLS 1.1 endpoints can
explicitly lower the minimum version with the `crypto_method` request option:

```php
$client->request('GET', 'https://legacy.example.com', [
    'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT,
]);
```

#### cURL Minimum Version

Guzzle 8 requires libcurl 7.34.0 or higher when using the built-in cURL
handlers. If the default handler stack detects an older libcurl version, it will
not select the cURL handler automatically. Manually configured cURL handlers also
reject requests when the linked libcurl version is lower than 7.34.0 or the PHP
cURL extension does not expose TLS 1.2 support.

#### cURL Handler Lifecycle

Applications that manage built-in cURL handlers or factories directly should
treat closed instances as unusable. Reusing a closed `CurlHandler`,
`CurlMultiHandler`, or `CurlFactory` throws `BadMethodCallException`; create a
new instance instead.

If `CurlMultiHandler::close()` is called while transfers are pending, those
promises are rejected with `GuzzleHttp\Exception\HandlerClosedException`.
Destructor cleanup remains best-effort and does not reject pending promises.

A custom `handle_factory` passed to a built-in cURL handler remains caller-owned.
Closing the handler does not close an injected factory.

Direct magic access to `CurlMultiHandler::$_mh` has been removed. This was an
undocumented internal lazy cURL multi handle. Applications that used it to set
`CURLMOPT_*` options should pass those values through the `options` key of the
`CurlMultiHandler` constructor.

#### Timeout Option Validation

Built-in handlers validate timeout option values when they apply those options.
`timeout` is applied by both built-in transports. `connect_timeout` is applied by
cURL handlers and accepted without effect by the stream handler. `read_timeout`
is applied by the stream handler and accepted without effect by cURL handlers.
When a built-in handler applies a timeout option, positive values below `0.001`
seconds now throw `InvalidArgumentException` instead of being converted to no
timeout.

#### Proxy Option Validation

The `proxy` request option is validated more strictly. Proxy values must be
strings, and the `proxy['no']` value may be either an array of strings or a
comma-delimited string such as the value from the `NO_PROXY` environment
variable. Other values now throw `InvalidArgumentException`.

No-proxy matching is normalized more consistently. Domain entries are matched
case-insensitively, a single trailing DNS root dot is ignored for domain
matching, exact IP literal entries compare normalized IP addresses, and
`NO_PROXY` environment entries are trimmed with the same parser used for request
options. Internal spaces in `NO_PROXY` entries are preserved instead of removed.

Explicit proxy options also override environment no-proxy settings. If you pass
a `proxy` request option and want to exclude hosts, provide the `no` value
explicitly:

```php
$noProxy = getenv('NO_PROXY');

$client->request('GET', '/', [
    'proxy' => [
        'http' => 'http://localhost:8125',
        'no' => $noProxy === false ? '' : $noProxy,
    ],
]);
```

#### Auth Option Validation

The `auth` request option now validates array values before applying them. Auth
arrays must contain username and password strings at indexes `0` and `1`. If an
auth type is provided at index `2`, it must be one of `basic`, `digest`, or
`ntlm`.

Invalid auth arrays that previously emitted warnings, coerced values, or did
nothing now throw `GuzzleHttp\Exception\InvalidArgumentException`.

```php
// Valid:
$client->request('GET', '/', [
    'auth' => ['username', 'password', 'basic'],
]);

// Invalid in 8.0:
$client->request('GET', '/', [
    'auth' => ['username'],
]);
```

#### Cross-Origin Redirect Auth Cleanup

Guzzle 8 no longer forwards the generic `auth` request option when automatic
redirects cross origin. Guzzle already removed the `Authorization` and `Cookie`
headers and cURL HTTP authentication options on cross-origin redirects; this now
also applies to handler-visible `auth` state.

Same-origin redirects continue to preserve `auth`. If an application or custom
handler intentionally reused `auth` across redirected origins, disable automatic
redirects or handle redirects manually so each origin receives explicit
credentials.

#### Handler-Specific Option Overrides

Handler-specific overrides remain available for finer transport control when
they do not conflict with Guzzle-managed behavior. The built-in cURL handlers
now reject raw cURL options that override request method, URI, body, headers,
timeouts, redirects, proxy URLs, TLS verification or client credentials,
progress/debug callbacks, sink handling, cookies, protocols, or cURL share
handles. Use first-class Guzzle request options for those settings.

The cURL handlers also reject stream-only `stream_context` options, but accept
`read_timeout` without effect. The stream handler rejects cURL-only options it
cannot honor, but accepts `connect_timeout` without effect. These timeout options
are intentionally best-effort so shared request configuration can be reused
across transports.

#### Native Type Declarations

Guzzle 8 adds native parameter and return types where PHP 7.4 allows. Code
overriding affected methods must update method signatures accordingly.

`HandlerStack::__toString()` and `SetCookie::__toString()` now return `string`.

`HandlerStack::remove()` now throws `TypeError` when passed a value that is
neither a callable nor a middleware name string.

`Pool::__construct()` and `Pool::batch()` now require iterable request collections.

`SetCookie::setSecure()`, `SetCookie::setDiscard()`, and
`SetCookie::setHttpOnly()` now require boolean parameters. Calls from files that
declare strict types will throw `TypeError` for non-boolean values.

`SetCookie::getExpires()` now returns `int|null`. Invalid textual expiration
dates are treated as `null`.

#### IDN Conversion Option Types

The `idn_conversion` request option must be `true`, `false`, `null`, or an
integer `IDNA_*` bitmask. Numeric strings and floats that previously worked
through PHP scalar coercion are no longer accepted.

Integer `0` remains a valid option bitmask. Use `false` or `null` to disable IDN
conversion.

#### Generic Promise and Structured PHPDoc Types

Guzzle's async client APIs, handlers, and middleware callable annotations now use
generic `PromiseInterface<ResponseInterface, mixed>` PHPDoc types. This is a
static-analysis-only change at runtime, but projects with stricter static
analysis may see new or different diagnostics.

Code using unparameterized promise types continues to work. If your project
implements Guzzle client interfaces, provides custom handlers or middleware, or
uses stricter static analysis, you may need to update your PHPDoc annotations to
include promise fulfillment and rejection types.

Public client config, request option, pool option, handler, middleware, mock
handler, and history middleware PHPDoc now uses structured array and callable
shapes. This does not change runtime behavior, but stricter static analysis may
now report invalid option keys, invalid option value types, or lower-arity
callback annotations that were previously hidden behind loose `array` or
`callable` PHPDoc.

If your project implements `ClientInterface`, extends client behavior through
traits, builds custom handlers or middleware, or documents reusable request
option arrays, update those PHPDoc annotations to match the supported request
option and callback shapes.

#### Multipart Request Serialization

Guzzle 8 uses Guzzle PSR-7 3.x for multipart request bodies. Multipart parts
created through the `multipart` request option no longer include generated
per-part `Content-Length` headers by default. If your tests compare raw
multipart payloads, remove those generated part headers from expected strings.

Generated multipart `Content-Disposition` header `name` and `filename`
parameters now escape double quotes, carriage returns, and line feeds as `%22`,
`%0D`, and `%0A`. Literal backslashes and other characters are serialized
unchanged, matching browser multipart form submission behavior. Custom multipart
part header names and values, and explicit PSR-7 multipart boundaries, are also
validated by PSR-7.

Custom multipart part header values also preserve trailing spaces and tabs in the
serialized request body, so raw body snapshots or signatures may need updated
expectations.

Guzzle now quotes the `boundary` parameter in generated
`Content-Type: multipart/form-data` headers when an explicit PSR-7
`MultipartStream` boundary contains characters that require quoting.
Automatically generated boundaries are unchanged.

You can still pass an explicit `Content-Length` header in a multipart element's
`headers` array if a non-standard peer requires it.

#### Host-Only Cookies

Cookies extracted from responses without a `Domain` attribute are now stored as
host-only cookies. They are sent only to the exact host that set them.

Previously, Guzzle stored these cookies with the request host as a normal domain
cookie, so they could also be sent to subdomains. Applications relying on that
behavior should use an explicit `Domain` attribute.

`SetCookie::toArray()` may include `HostOnly => true` for host-only cookies.
Existing persisted cookie files without this key load as non-host-only cookies.

#### SetCookie Constructor Field Validation

`SetCookie` constructor arrays no longer coerce invalid field values. Cookie
names, values, domains, paths, max-age values, expiry values, and boolean flags
must use the documented types. Invalid constructor values now throw
`InvalidArgumentException`.

```php
// Valid:
new SetCookie([
    'Name' => 'foo',
    'Value' => 'bar',
    'Domain' => 'example.com',
    'Secure' => true,
]);

// Invalid in 8.0:
new SetCookie([
    'Name' => false,
    'Value' => 'bar',
]);
```

Cookies parsed from normal `Set-Cookie` headers continue to be normalized by
`SetCookie::fromString()`.

#### CookieJar::clear Null Semantics

`CookieJar::clear()` now treats only `null` as an omitted path or name.
Previously, falsy path or name values such as `'0'` or `''` could be interpreted
as omitted and clear a broader set of cookies than intended.

If you call `clear()` to clear all cookies, continue passing no arguments:

```php
$jar->clear();
```

If you pass a path or name, that value is now treated as provided.

#### FileCookieJar Serialization

`FileCookieJar` instances restored with `unserialize()` no longer save cookies
automatically on destruction. If your application intentionally unserializes a
`FileCookieJar` and expects changes to persist, call `save()` explicitly.

Saved cookie files now JSON-escape tag characters. Existing cookie files remain
readable, and cookie values are unchanged when loaded.

#### Retry Delay Callbacks

The retry middleware accepts an optional delay callback as the second argument to
`Middleware::retry()` or the third constructor argument to `RetryMiddleware`. The
callback returns the number of milliseconds to wait before the next retry
attempt.

Delay callbacks may now explicitly use either the retry-count-only signature or
the full retry-context signature:

```php
// Retry count only:
$delay = static function (int $retries): int {
    return $retries * 1000;
};

// Full retry context:
$delay = static function (int $retries, ?ResponseInterface $response, RequestInterface $request): int {
    return $retries * 1000;
};
```

Callbacks that accept three arguments continue to receive the retry count, the
response that triggered the retry when one exists, and the request being retried.
One-argument callbacks are now called with only the retry count, which also
allows internal PHP functions with a single-argument signature.

The seeded `retries` request option must be an integer. Delay callbacks must
return an integer number of milliseconds.

#### Logging Middleware Formatter Types

`GuzzleHttp\MessageFormatter` is now final. Applications that extended
`MessageFormatter` should implement `GuzzleHttp\MessageFormatterInterface`
instead and pass the custom formatter to `GuzzleHttp\Middleware::log()`.

`Middleware::log()` now requires its formatter argument to implement
`MessageFormatterInterface`. Passing `new MessageFormatter()` still works
because `MessageFormatter` implements `MessageFormatterInterface`. Passing any
other value now fails with PHP's native `TypeError` instead of Guzzle's previous
`LogicException`.

```php
use GuzzleHttp\MessageFormatterInterface;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class RedactingFormatter implements MessageFormatterInterface
{
    public function format(
        RequestInterface $request,
        ?ResponseInterface $response = null,
        ?\Throwable $error = null
    ): string {
        return $request->getMethod().' '.$request->getUri()->getPath();
    }
}

$stack->push(Middleware::log($logger, new RedactingFormatter()));
```

#### Built-in Handler Inheritance

`GuzzleHttp\Handler\CurlFactory`, `GuzzleHttp\Handler\CurlHandler`,
`GuzzleHttp\Handler\CurlMultiHandler`, `GuzzleHttp\Handler\MockHandler`, and
`GuzzleHttp\Handler\StreamHandler` are now final.

Applications that extended `CurlFactory` should implement
`GuzzleHttp\Handler\CurlFactoryInterface` instead. Applications that extended
`CurlHandler`, `CurlMultiHandler`, `MockHandler`, or `StreamHandler` should use
composition instead: wrap a handler instance in a custom callable or provide a
custom handler rather than subclassing the built-in handler.

#### Custom cURL Handle Factories

Custom `GuzzleHttp\Handler\CurlFactoryInterface` implementations that create or
mutate `GuzzleHttp\Handler\EasyHandle` instances must assign values compatible
with EasyHandle's documented public property types. Several EasyHandle
bookkeeping properties now use native property types, so assigning incompatible
values to those properties raises `TypeError`.

Custom factories must assign the request and sink state before returning an
EasyHandle to Guzzle because those properties are required typed invariants.
Reading them before assignment raises PHP's uninitialized typed-property `Error`.

The native cURL handle properties intentionally remain untyped because PHP 7.4
represents cURL handles as resources while PHP 8 represents them as cURL handle
objects. Custom factories must still unset `$easy->handle` when releasing an easy
handle, as required by `CurlFactoryInterface::release()`.

#### Progress Callback Parameter Types

The built-in handlers now pass integer byte counts to `progress` callbacks.
Callbacks with `int` parameter types continue to work, and callbacks with `float`
parameter types can still receive integer byte counts in PHP. If a callback used
other scalar parameter types, update it to accept integers or remove the scalar
parameter declarations.

#### CurlMultiHandler Select Timeout

The `GUZZLE_CURL_SELECT_TIMEOUT` environment variable is no longer read. Pass
the `select_timeout` option to `CurlMultiHandler` instead.

#### Removed Middleware Helper APIs

`RetryMiddleware::exponentialDelay()` has been removed. The retry middleware
continues to use the same exponential backoff calculation by default. This only
affects code that called the static helper directly; inline that calculation or
pass a custom delay callable to `Middleware::retry()`.

`RedirectMiddleware::$defaultSettings` has been removed. Use
`RedirectMiddleware::DEFAULT_SETTINGS` instead.

#### Removed Proxy Helper API

`Utils::isHostInNoProxy()` has been removed.

Use `ProxyOptions::resolve()` when implementing Guzzle-compatible proxy handling
in a custom handler. Use `ProxyOptions::isUriInNoProxy()` when checking whether a
request URI matches a no-proxy list. Use `ProxyOptions::isHostInNoProxy()` only
when checking a host string directly.

These helpers use Guzzle 8's normalized no-proxy matching rather than preserving
the old `Utils::isHostInNoProxy()` semantics. Domain matching is
case-insensitive and ignores a single trailing DNS root dot, IP literals are
normalized before comparison, and CIDR entries match IP literal hosts.

#### Non-instantiable Utility Classes

Static utility and constant classes such as `GuzzleHttp\Middleware`,
`GuzzleHttp\Utils`, `GuzzleHttp\RequestOptions`,
`GuzzleHttp\Handler\HeaderProcessor`, and `GuzzleHttp\Handler\Proxy` now have
private constructors. `GuzzleHttp\Handler\Proxy` is also declared `final`.

These classes only expose static members. Replace any accidental instantiation
with static method calls or constant access.

6.0 to 7.0
----------

In order to take advantage of the new features of PHP, Guzzle dropped the support
of PHP 5. The minimum supported PHP version is now PHP 7.2. Type hints and return
types for functions and methods have been added wherever possible. 

Please make sure:
- You are calling a function or a method with the correct type.
- If you extend a class of Guzzle; update all signatures on methods you override.

#### Other Backwards Compatibility Breaking Changes

- Class `GuzzleHttp\UriTemplate` is removed.
- Class `GuzzleHttp\Exception\SeekException` is removed.
- Classes `GuzzleHttp\Exception\BadResponseException`, `GuzzleHttp\Exception\ClientException`, 
  `GuzzleHttp\Exception\ServerException` can no longer be initialized with an empty
  Response as argument.
- Class `GuzzleHttp\Exception\ConnectException` now extends `GuzzleHttp\Exception\TransferException`
  instead of `GuzzleHttp\Exception\RequestException`.
- Function `GuzzleHttp\Exception\ConnectException::getResponse()` is removed.
- Function `GuzzleHttp\Exception\ConnectException::hasResponse()` is removed.
- Constant `GuzzleHttp\ClientInterface::VERSION` is removed. Added `GuzzleHttp\ClientInterface::MAJOR_VERSION` instead.
- Function `GuzzleHttp\Exception\RequestException::getResponseBodySummary` is removed.
  Use `\GuzzleHttp\Psr7\get_message_body_summary` as an alternative.
- Function `GuzzleHttp\Cookie\CookieJar::getCookieValue` is removed.
- Request option `exceptions` is removed. Please use `http_errors`.
- Request option `save_to` is removed. Please use `sink`.
- Pool option `pool_size` is removed. Please use `concurrency`.
- We now look for environment variables in the `$_SERVER` super global, due to thread safety issues with `getenv`. We continue to fallback to `getenv` in CLI environments, for maximum compatibility.
- The `get`, `head`, `put`, `post`, `patch`, `delete`, `getAsync`, `headAsync`, `putAsync`, `postAsync`, `patchAsync`, and `deleteAsync` methods are now implemented as genuine methods on `GuzzleHttp\Client`, with strong typing. The original `__call` implementation remains unchanged for now, for maximum backwards compatibility, but won't be invoked under normal operation.
- The `log` middleware will log the errors with level `error` instead of `notice` 
- Support for international domain names (IDN) is now disabled by default, and enabling it requires installing ext-intl, linked against a modern version of the C library (ICU 4.6 or higher).

#### Native Functions Calls

All internal native functions calls of Guzzle are now prefixed with a slash. This
change makes it impossible for method overloading by other libraries or applications.
Example:

```php
// Before:
curl_version();

// After:
\curl_version();
```

For the full diff you can check [here](https://github.com/guzzle/guzzle/compare/6.5.4..7.0.0).

5.0 to 6.0
----------

Guzzle now uses [PSR-7](https://www.php-fig.org/psr/psr-7/) for HTTP messages.
Due to the fact that these messages are immutable, this prompted a refactoring
of Guzzle to use a middleware based system rather than an event system. Any
HTTP message interaction (e.g., `GuzzleHttp\Message\Request`) need to be
updated to work with the new immutable PSR-7 request and response objects. Any
event listeners or subscribers need to be updated to become middleware
functions that wrap handlers (or are injected into a
`GuzzleHttp\HandlerStack`).

- Removed `GuzzleHttp\BatchResults`
- Removed `GuzzleHttp\Collection`
- Removed `GuzzleHttp\HasDataTrait`
- Removed `GuzzleHttp\ToArrayInterface`
- The `guzzlehttp/streams` dependency has been removed. Stream functionality
  is now present in the `GuzzleHttp\Psr7` namespace provided by the
  `guzzlehttp/psr7` package.
- Guzzle no longer uses ReactPHP promises and now uses the
  `guzzlehttp/promises` library. We use a custom promise library for three
  significant reasons:
  1. React promises (at the time of writing this) are recursive. Promise
     chaining and promise resolution will eventually blow the stack. Guzzle
     promises are not recursive as they use a sort of trampolining technique.
     Note: there has been movement in the React project to modify promises to
     no longer utilize recursion.
  2. Guzzle needs to have the ability to synchronously block on a promise to
     wait for a result. Guzzle promises allows this functionality (and does
     not require the use of recursion).
  3. Because we need to be able to wait on a result, doing so using React
     promises requires wrapping react promises with RingPHP futures. This
     overhead is no longer needed, reducing stack sizes, reducing complexity,
     and improving performance.
- `GuzzleHttp\Mimetypes` has been moved to a function in
  `GuzzleHttp\Psr7\mimetype_from_extension` and
  `GuzzleHttp\Psr7\mimetype_from_filename`.
- `GuzzleHttp\Query` and `GuzzleHttp\QueryParser` have been removed. Query
  strings must now be passed into request objects as strings, or provided to
  the `query` request option when creating requests with clients. The `query`
  option uses PHP's `http_build_query` to convert an array to a string. If you
  need a different serialization technique, you will need to pass the query
  string in as a string. There are a couple helper functions that will make
  working with query strings easier: `GuzzleHttp\Psr7\parse_query` and
  `GuzzleHttp\Psr7\build_query`.
- Guzzle no longer has a dependency on RingPHP. Due to the use of a middleware
  system based on PSR-7, using RingPHP and it's middleware system as well adds
  more complexity than the benefits it provides. All HTTP handlers that were
  present in RingPHP have been modified to work directly with PSR-7 messages
  and placed in the `GuzzleHttp\Handler` namespace. This significantly reduces
  complexity in Guzzle, removes a dependency, and improves performance. RingPHP
  will be maintained for Guzzle 5 support, but will no longer be a part of
  Guzzle 6.
- As Guzzle now uses a middleware based systems the event system and RingPHP
  integration has been removed. Note: while the event system has been removed,
  it is possible to add your own type of event system that is powered by the
  middleware system.
  - Removed the `Event` namespace.
  - Removed the `Subscriber` namespace.
  - Removed `Transaction` class
  - Removed `RequestFsm`
  - Removed `RingBridge`
  - `GuzzleHttp\Subscriber\Cookie` is now provided by
    `GuzzleHttp\Middleware::cookies`
  - `GuzzleHttp\Subscriber\HttpError` is now provided by
    `GuzzleHttp\Middleware::httpError`
  - `GuzzleHttp\Subscriber\History` is now provided by
    `GuzzleHttp\Middleware::history`
  - `GuzzleHttp\Subscriber\Mock` is now provided by
    `GuzzleHttp\Handler\MockHandler`
  - `GuzzleHttp\Subscriber\Prepare` is now provided by
    `GuzzleHttp\PrepareBodyMiddleware`
  - `GuzzleHttp\Subscriber\Redirect` is now provided by
    `GuzzleHttp\RedirectMiddleware`
- Guzzle now uses `Psr\Http\Message\UriInterface` (implements in
  `GuzzleHttp\Psr7\Uri`) for URI support. `GuzzleHttp\Url` is now gone.
- Static functions in `GuzzleHttp\Utils` have been moved to namespaced
  functions under the `GuzzleHttp` namespace. This requires either a Composer
  based autoloader or you to include functions.php.
- `GuzzleHttp\ClientInterface::getDefaultOption` has been renamed to
  `GuzzleHttp\ClientInterface::getConfig`.
- `GuzzleHttp\ClientInterface::setDefaultOption` has been removed.
- The `json` and `xml` methods of response objects has been removed. With the
  migration to strictly adhering to PSR-7 as the interface for Guzzle messages,
  adding methods to message interfaces would actually require Guzzle messages
  to extend from PSR-7 messages rather then work with them directly.

## Migrating to middleware

The change to PSR-7 unfortunately required significant refactoring to Guzzle
due to the fact that PSR-7 messages are immutable. Guzzle 5 relied on an event
system from plugins. The event system relied on mutability of HTTP messages and
side effects in order to work. With immutable messages, you have to change your
workflow to become more about either returning a value (e.g., functional
middlewares) or setting a value on an object. Guzzle v6 has chosen the
functional middleware approach.

Instead of using the event system to listen for things like the `before` event,
you now create a stack based middleware function that intercepts a request on
the way in and the promise of the response on the way out. This is a much
simpler and more predictable approach than the event system and works nicely
with PSR-7 middleware. Due to the use of promises, the middleware system is
also asynchronous.

v5:

```php
use GuzzleHttp\Event\BeforeEvent;
$client = new GuzzleHttp\Client();
// Get the emitter and listen to the before event.
$client->getEmitter()->on('before', function (BeforeEvent $e) {
    // Guzzle v5 events relied on mutation
    $e->getRequest()->setHeader('X-Foo', 'Bar');
});
```

v6:

In v6, you can modify the request before it is sent using the `mapRequest`
middleware. The idiomatic way in v6 to modify the request/response lifecycle is
to setup a handler middleware stack up front and inject the handler into a
client.

```php
use GuzzleHttp\Middleware;
// Create a handler stack that has all of the default middlewares attached
$handler = GuzzleHttp\HandlerStack::create();
// Push the handler onto the handler stack
$handler->push(Middleware::mapRequest(function (RequestInterface $request) {
    // Notice that we have to return a request object
    return $request->withHeader('X-Foo', 'Bar');
}));
// Inject the handler into the client
$client = new GuzzleHttp\Client(['handler' => $handler]);
```

## POST Requests

This version added the [`form_params`](https://github.com/guzzle/guzzle/blob/6.5/docs/request-options.rst#form_params)
and `multipart` request options. `form_params` is an associative array of
strings or array of strings and is used to serialize an
`application/x-www-form-urlencoded` POST request. The
[`multipart`](https://github.com/guzzle/guzzle/blob/6.5/docs/request-options.rst#multipart)
option is now used to send a multipart/form-data POST request.

`GuzzleHttp\Post\PostFile` has been removed. Use the `multipart` option to add
POST files to a multipart/form-data request.

The `body` option no longer accepts an array to send POST requests. Please use
`multipart` or `form_params` instead.

The `base_url` option has been renamed to `base_uri`.

4.x to 5.0
----------

## Rewritten Adapter Layer

Guzzle now uses [RingPHP](https://github.com/guzzle/RingPHP) to send
HTTP requests. The `adapter` option in a `GuzzleHttp\Client` constructor
is still supported, but it has now been renamed to `handler`. Instead of
passing a `GuzzleHttp\Adapter\AdapterInterface`, you must now pass a PHP
`callable` that follows the RingPHP specification.

## Removed Fluent Interfaces

[Fluent interfaces were removed](https://ocramius.github.io/blog/fluent-interfaces-are-evil/)
from the following classes:

- `GuzzleHttp\Collection`
- `GuzzleHttp\Url`
- `GuzzleHttp\Query`
- `GuzzleHttp\Post\PostBody`
- `GuzzleHttp\Cookie\SetCookie`

## Removed functions.php

Removed "functions.php", so that Guzzle is truly PSR-4 compliant. The following
functions can be used as replacements.

- `GuzzleHttp\json_decode` -> `GuzzleHttp\Utils::jsonDecode`
- `GuzzleHttp\get_path` -> `GuzzleHttp\Utils::getPath`
- `GuzzleHttp\Utils::setPath` -> `GuzzleHttp\set_path`
- `GuzzleHttp\Pool::batch` -> `GuzzleHttp\batch`. This function is, however,
  deprecated in favor of using `GuzzleHttp\Pool::batch()`.

The "procedural" global client has been removed with no replacement (e.g.,
`GuzzleHttp\get()`, `GuzzleHttp\post()`, etc.). Use a `GuzzleHttp\Client`
object as a replacement.

## `throwImmediately` has been removed

The concept of "throwImmediately" has been removed from exceptions and error
events. This control mechanism was used to stop a transfer of concurrent
requests from completing. This can now be handled by throwing the exception or
by cancelling a pool of requests or each outstanding future request
individually.

## headers event has been removed

Removed the "headers" event. This event was only useful for changing the
body a response once the headers of the response were known. You can implement
a similar behavior in a number of ways. One example might be to use a
FnStream that has access to the transaction being sent. For example, when the
first byte is written, you could check if the response headers match your
expectations, and if so, change the actual stream body that is being
written to.

## Updates to HTTP Messages

Removed the `asArray` parameter from
`GuzzleHttp\Message\MessageInterface::getHeader`. If you want to get a header
value as an array, then use the newly added `getHeaderAsArray()` method of
`MessageInterface`. This change makes the Guzzle interfaces compatible with
the PSR-7 interfaces.

3.x to 4.0
----------

## Overarching changes:

- Now requires PHP 5.4 or greater.
- No longer requires cURL to send requests.
- Guzzle no longer wraps every exception it throws. Only exceptions that are
  recoverable are now wrapped by Guzzle.
- Various namespaces have been removed or renamed.
- No longer requiring the Symfony EventDispatcher. A custom event dispatcher
  based on the Symfony EventDispatcher is
  now utilized in `GuzzleHttp\Event\EmitterInterface` (resulting in significant
  speed and functionality improvements).

Changes per Guzzle 3.x namespace are described below.

## Batch

The `Guzzle\Batch` namespace has been removed. This is best left to
third-parties to implement on top of Guzzle's core HTTP library.

## Cache

The `Guzzle\Cache` namespace has been removed. (Todo: No suitable replacement
has been implemented yet, but hoping to utilize a PSR cache interface).

## Common

- Removed all of the wrapped exceptions. It's better to use the standard PHP
  library for unrecoverable exceptions.
- `FromConfigInterface` has been removed.
- `Guzzle\Common\Version` has been removed. The VERSION constant can be found
  at `GuzzleHttp\ClientInterface::VERSION`.

### Collection

- `getAll` has been removed. Use `toArray` to convert a collection to an array.
- `inject` has been removed.
- `keySearch` has been removed.
- `getPath` no longer supports wildcard expressions. Use something better like
  JMESPath for this.
- `setPath` now supports appending to an existing array via the `[]` notation.

### Events

Guzzle no longer requires Symfony's EventDispatcher component. Guzzle now uses
`GuzzleHttp\Event\Emitter`.

- `Symfony\Component\EventDispatcher\EventDispatcherInterface` is replaced by
  `GuzzleHttp\Event\EmitterInterface`.
- `Symfony\Component\EventDispatcher\EventDispatcher` is replaced by
  `GuzzleHttp\Event\Emitter`.
- `Symfony\Component\EventDispatcher\Event` is replaced by
  `GuzzleHttp\Event\Event`, and Guzzle now has an EventInterface in
  `GuzzleHttp\Event\EventInterface`.
- `AbstractHasDispatcher` has moved to a trait, `HasEmitterTrait`, and
  `HasDispatcherInterface` has moved to `HasEmitterInterface`. Retrieving the
  event emitter of a request, client, etc. now uses the `getEmitter` method
  rather than the `getDispatcher` method.

#### Emitter

- Use the `once()` method to add a listener that automatically removes itself
  the first time it is invoked.
- Use the `listeners()` method to retrieve a list of event listeners rather than
  the `getListeners()` method.
- Use `emit()` instead of `dispatch()` to emit an event from an emitter.
- Use `attach()` instead of `addSubscriber()` and `detach()` instead of
  `removeSubscriber()`.

```php
$mock = new Mock();
// 3.x
$request->getEventDispatcher()->addSubscriber($mock);
$request->getEventDispatcher()->removeSubscriber($mock);
// 4.x
$request->getEmitter()->attach($mock);
$request->getEmitter()->detach($mock);
```

Use the `on()` method to add a listener rather than the `addListener()` method.

```php
// 3.x
$request->getEventDispatcher()->addListener('foo', function (Event $event) { /* ... */ } );
// 4.x
$request->getEmitter()->on('foo', function (Event $event, $name) { /* ... */ } );
```

## Http

### General changes

- The cacert.pem certificate has been moved to `src/cacert.pem`.
- Added the concept of adapters that are used to transfer requests over the
  wire.
- Simplified the event system.
- Sending requests in parallel is still possible, but batching is no longer a
  concept of the HTTP layer. Instead, you must use the `complete` and `error`
  events to asynchronously manage parallel request transfers.
- `Guzzle\Http\Url` has moved to `GuzzleHttp\Url`.
- `Guzzle\Http\QueryString` has moved to `GuzzleHttp\Query`.
- QueryAggregators have been rewritten so that they are simply callable
  functions.
- `GuzzleHttp\StaticClient` has been removed. Use the functions provided in
  `functions.php` for an easy to use static client instance.
- Exceptions in `GuzzleHttp\Exception` have been updated to all extend from
  `GuzzleHttp\Exception\TransferException`.

### Client

Calling methods like `get()`, `post()`, `head()`, etc. no longer create and
return a request, but rather creates a request, sends the request, and returns
the response.

```php
// 3.0
$request = $client->get('/');
$response = $request->send();

// 4.0
$response = $client->get('/');

// or, to mirror the previous behavior
$request = $client->createRequest('GET', '/');
$response = $client->send($request);
```

`GuzzleHttp\ClientInterface` has changed.

- The `send` method no longer accepts more than one request. Use `sendAll` to
  send multiple requests in parallel.
- `setUserAgent()` has been removed. Use a default request option instead. You
  could, for example, do something like:
  `$client->setConfig('defaults/headers/User-Agent', 'Foo/Bar ' . $client::getDefaultUserAgent())`.
- `setSslVerification()` has been removed. Use default request options instead,
  like `$client->setConfig('defaults/verify', true)`.

`GuzzleHttp\Client` has changed.

- The constructor now accepts only an associative array. You can include a
  `base_url` string or array to use a URI template as the base URL of a client.
  You can also specify a `defaults` key that is an associative array of default
  request options. You can pass an `adapter` to use a custom adapter,
  `batch_adapter` to use a custom adapter for sending requests in parallel, or
  a `message_factory` to change the factory used to create HTTP requests and
  responses.
- The client no longer emits a `client.create_request` event.
- Creating requests with a client no longer automatically utilize a URI
  template. You must pass an array into a creational method (e.g.,
  `createRequest`, `get`, `put`, etc.) in order to expand a URI template.

### Messages

Messages no longer have references to their counterparts (i.e., a request no
longer has a reference to it's response, and a response no loger has a
reference to its request). This association is now managed through a
`GuzzleHttp\Adapter\TransactionInterface` object. You can get references to
these transaction objects using request events that are emitted over the
lifecycle of a request.

#### Requests with a body

- `GuzzleHttp\Message\EntityEnclosingRequest` and
  `GuzzleHttp\Message\EntityEnclosingRequestInterface` have been removed. The
  separation between requests that contain a body and requests that do not
  contain a body has been removed, and now `GuzzleHttp\Message\RequestInterface`
  handles both use cases.
- Any method that previously accepts a `GuzzleHttp\Response` object now accept a
  `GuzzleHttp\Message\ResponseInterface`.
- `GuzzleHttp\Message\RequestFactoryInterface` has been renamed to
  `GuzzleHttp\Message\MessageFactoryInterface`. This interface is used to create
  both requests and responses and is implemented in
  `GuzzleHttp\Message\MessageFactory`.
- POST field and file methods have been removed from the request object. You
  must now use the methods made available to `GuzzleHttp\Post\PostBodyInterface`
  to control the format of a POST body. Requests that are created using a
  standard `GuzzleHttp\Message\MessageFactoryInterface` will automatically use
  a `GuzzleHttp\Post\PostBody` body if the body was passed as an array or if
  the method is POST and no body is provided.

```php
$request = $client->createRequest('POST', '/');
$request->getBody()->setField('foo', 'bar');
$request->getBody()->addFile(new PostFile('file_key', fopen('/path/to/content', 'r')));
```

#### Headers

- `GuzzleHttp\Message\Header` has been removed. Header values are now simply
  represented by an array of values or as a string. Header values are returned
  as a string by default when retrieving a header value from a message. You can
  pass an optional argument of `true` to retrieve a header value as an array
  of strings instead of a single concatenated string.
- `GuzzleHttp\PostFile` and `GuzzleHttp\PostFileInterface` have been moved to
  `GuzzleHttp\Post`. This interface has been simplified and now allows the
  addition of arbitrary headers.
- Custom headers like `GuzzleHttp\Message\Header\Link` have been removed. Most
  of the custom headers are now handled separately in specific
  subscribers/plugins, and `GuzzleHttp\Message\HeaderValues::parseParams()` has
  been updated to properly handle headers that contain parameters (like the
  `Link` header).

#### Responses

- `GuzzleHttp\Message\Response::getInfo()` and
  `GuzzleHttp\Message\Response::setInfo()` have been removed. Use the event
  system to retrieve this type of information.
- `GuzzleHttp\Message\Response::getRawHeaders()` has been removed.
- `GuzzleHttp\Message\Response::getMessage()` has been removed.
- `GuzzleHttp\Message\Response::calculateAge()` and other cache specific
  methods have moved to the CacheSubscriber.
- Header specific helper functions like `getContentMd5()` have been removed.
  Just use `getHeader('Content-MD5')` instead.
- `GuzzleHttp\Message\Response::setRequest()` and
  `GuzzleHttp\Message\Response::getRequest()` have been removed. Use the event
  system to work with request and response objects as a transaction.
- `GuzzleHttp\Message\Response::getRedirectCount()` has been removed. Use the
  Redirect subscriber instead.
- `GuzzleHttp\Message\Response::isSuccessful()` and other related methods have
  been removed. Use `getStatusCode()` instead.

#### Streaming responses

Streaming requests can now be created by a client directly, returning a
`GuzzleHttp\Message\ResponseInterface` object that contains a body stream
referencing an open PHP HTTP stream.

```php
// 3.0
use Guzzle\Stream\PhpStreamRequestFactory;
$request = $client->get('/');
$factory = new PhpStreamRequestFactory();
$stream = $factory->fromRequest($request);
$data = $stream->read(1024);

// 4.0
$response = $client->get('/', ['stream' => true]);
// Read some data off of the stream in the response body
$data = $response->getBody()->read(1024);
```

#### Redirects

The `configureRedirects()` method has been removed in favor of a
`allow_redirects` request option.

```php
// Standard redirects with a default of a max of 5 redirects
$request = $client->createRequest('GET', '/', ['allow_redirects' => true]);

// Strict redirects with a custom number of redirects
$request = $client->createRequest('GET', '/', [
    'allow_redirects' => ['max' => 5, 'strict' => true]
]);
```

#### EntityBody

EntityBody interfaces and classes have been removed or moved to
`GuzzleHttp\Stream`. All classes and interfaces that once required
`GuzzleHttp\EntityBodyInterface` now require
`GuzzleHttp\Stream\StreamInterface`. Creating a new body for a request no
longer uses `GuzzleHttp\EntityBody::factory` but now uses
`GuzzleHttp\Stream\Stream::factory` or even better:
`GuzzleHttp\Stream\create()`.

- `Guzzle\Http\EntityBodyInterface` is now `GuzzleHttp\Stream\StreamInterface`
- `Guzzle\Http\EntityBody` is now `GuzzleHttp\Stream\Stream`
- `Guzzle\Http\CachingEntityBody` is now `GuzzleHttp\Stream\CachingStream`
- `Guzzle\Http\ReadLimitEntityBody` is now `GuzzleHttp\Stream\LimitStream`
- `Guzzle\Http\IoEmittyinEntityBody` has been removed.

#### Request lifecycle events

Requests previously submitted a large number of requests. The number of events
emitted over the lifecycle of a request has been significantly reduced to make
it easier to understand how to extend the behavior of a request. All events
emitted during the lifecycle of a request now emit a custom
`GuzzleHttp\Event\EventInterface` object that contains context providing
methods and a way in which to modify the transaction at that specific point in
time (e.g., intercept the request and set a response on the transaction).

- `request.before_send` has been renamed to `before` and now emits a
  `GuzzleHttp\Event\BeforeEvent`
- `request.complete` has been renamed to `complete` and now emits a
  `GuzzleHttp\Event\CompleteEvent`.
- `request.sent` has been removed. Use `complete`.
- `request.success` has been removed. Use `complete`.
- `error` is now an event that emits a `GuzzleHttp\Event\ErrorEvent`.
- `request.exception` has been removed. Use `error`.
- `request.receive.status_line` has been removed.
- `curl.callback.progress` has been removed. Use a custom `StreamInterface` to
  maintain a status update.
- `curl.callback.write` has been removed. Use a custom `StreamInterface` to
  intercept writes.
- `curl.callback.read` has been removed. Use a custom `StreamInterface` to
  intercept reads.

`headers` is a new event that is emitted after the response headers of a
request have been received before the body of the response is downloaded. This
event emits a `GuzzleHttp\Event\HeadersEvent`.

You can intercept a request and inject a response using the `intercept()` event
of a `GuzzleHttp\Event\BeforeEvent`, `GuzzleHttp\Event\CompleteEvent`, and
`GuzzleHttp\Event\ErrorEvent` event.

## Inflection

The `Guzzle\Inflection` namespace has been removed. This is not a core concern
of Guzzle.

## Iterator

The `Guzzle\Iterator` namespace has been removed.

- `Guzzle\Iterator\AppendIterator`, `Guzzle\Iterator\ChunkedIterator`, and
  `Guzzle\Iterator\MethodProxyIterator` are nice, but not a core requirement of
  Guzzle itself.
- `Guzzle\Iterator\FilterIterator` is no longer needed because an equivalent
  class is shipped with PHP 5.4.
- `Guzzle\Iterator\MapIterator` is not really needed when using PHP 5.5 because
  it's easier to just wrap an iterator in a generator that maps values.

For a replacement of these iterators, see https://github.com/nikic/iter

## Log

The LogPlugin has moved to https://github.com/guzzle/log-subscriber. The
`Guzzle\Log` namespace has been removed. Guzzle now relies on
`Psr\Log\LoggerInterface` for all logging. The MessageFormatter class has been
moved to `GuzzleHttp\Subscriber\Log\Formatter`.

## Parser

The `Guzzle\Parser` namespace has been removed. This was previously used to
make it possible to plug in custom parsers for cookies, messages, URI
templates, and URLs; however, this level of complexity is not needed in Guzzle
so it has been removed.

- Cookie: Cookie parsing logic has been moved to
  `GuzzleHttp\Cookie\SetCookie::fromString`.
- Message: Message parsing logic for both requests and responses has been moved
  to `GuzzleHttp\Message\MessageFactory::fromMessage`. Message parsing is only
  used in debugging or deserializing messages, so it doesn't make sense for
  Guzzle as a library to add this level of complexity to parsing messages.
- UriTemplate: URI template parsing has been moved to
  `GuzzleHttp\UriTemplate`. The Guzzle library will automatically use the PECL
  URI template library if it is installed.
- Url: URL parsing is now performed in `GuzzleHttp\Url::fromString` (previously
  it was `Guzzle\Http\Url::factory()`). If custom URL parsing is necessary,
  then developers are free to subclass `GuzzleHttp\Url`.

## Plugin

The `Guzzle\Plugin` namespace has been renamed to `GuzzleHttp\Subscriber`.
Several plugins are shipping with the core Guzzle library under this namespace.

- `GuzzleHttp\Subscriber\Cookie`: Replaces the old CookiePlugin. Cookie jar
  code has moved to `GuzzleHttp\Cookie`.
- `GuzzleHttp\Subscriber\History`: Replaces the old HistoryPlugin.
- `GuzzleHttp\Subscriber\HttpError`: Throws errors when a bad HTTP response is
  received.
- `GuzzleHttp\Subscriber\Mock`: Replaces the old MockPlugin.
- `GuzzleHttp\Subscriber\Prepare`: Prepares the body of a request just before
  sending. This subscriber is attached to all requests by default.
- `GuzzleHttp\Subscriber\Redirect`: Replaces the RedirectPlugin.

The following plugins have been removed (third-parties are free to re-implement
these if needed):

- `GuzzleHttp\Plugin\Async` has been removed.
- `GuzzleHttp\Plugin\CurlAuth` has been removed.
- `GuzzleHttp\Plugin\ErrorResponse\ErrorResponsePlugin` has been removed. This
  functionality should instead be implemented with event listeners that occur
  after normal response parsing occurs in the guzzle/command package.

The following plugins are not part of the core Guzzle package, but are provided
in separate repositories:

- `Guzzle\Http\Plugin\BackoffPlugin` has been rewritten to be much simpler
  to build custom retry policies using simple functions rather than various
  chained classes. See: https://github.com/guzzle/retry-subscriber
- `Guzzle\Http\Plugin\Cache\CachePlugin` has moved to
  https://github.com/guzzle/cache-subscriber
- `Guzzle\Http\Plugin\Log\LogPlugin` has moved to
  https://github.com/guzzle/log-subscriber
- `Guzzle\Http\Plugin\Md5\Md5Plugin` has moved to
  https://github.com/guzzle/message-integrity-subscriber
- `Guzzle\Http\Plugin\Mock\MockPlugin` has moved to
  `GuzzleHttp\Subscriber\MockSubscriber`.
- `Guzzle\Http\Plugin\Oauth\OauthPlugin` has moved to
  https://github.com/guzzle/oauth-subscriber

## Service

The service description layer of Guzzle has moved into two separate packages:

- https://github.com/guzzle/command Provides a high level abstraction over web
  services by representing web service operations using commands.
- https://github.com/guzzle/guzzle-services Provides an implementation of
  guzzle/command that provides request serialization and response parsing using
  Guzzle service descriptions.

## Stream

Stream have moved to a separate package available at
https://github.com/guzzle/streams.

`Guzzle\Stream\StreamInterface` has been given a large update to cleanly take
on the responsibilities of `Guzzle\Http\EntityBody` and
`Guzzle\Http\EntityBodyInterface` now that they have been removed. The number
of methods implemented by the `StreamInterface` has been drastically reduced to
allow developers to more easily extend and decorate stream behavior.

## Removed methods from StreamInterface

- `getStream` and `setStream` have been removed to better encapsulate streams.
- `getMetadata` and `setMetadata` have been removed in favor of
  `GuzzleHttp\Stream\MetadataStreamInterface`.
- `getWrapper`, `getWrapperData`, `getStreamType`, and `getUri` have all been
  removed. This data is accessible when
  using streams that implement `GuzzleHttp\Stream\MetadataStreamInterface`.
- `rewind` has been removed. Use `seek(0)` for a similar behavior.

## Renamed methods

- `detachStream` has been renamed to `detach`.
- `feof` has been renamed to `eof`.
- `ftell` has been renamed to `tell`.
- `readLine` has moved from an instance method to a static class method of
  `GuzzleHttp\Stream\Stream`.

## Metadata streams

`GuzzleHttp\Stream\MetadataStreamInterface` has been added to denote streams
that contain additional metadata accessible via `getMetadata()`.
`GuzzleHttp\Stream\StreamInterface::getMetadata` and
`GuzzleHttp\Stream\StreamInterface::setMetadata` have been removed.

## StreamRequestFactory

The entire concept of the StreamRequestFactory has been removed. The way this
was used in Guzzle 3 broke the actual interface of sending streaming requests
(instead of getting back a Response, you got a StreamInterface). Streaming
PHP requests are now implemented through the `GuzzleHttp\Adapter\StreamAdapter`.

3.6 to 3.7
----------

### Deprecations

- You can now enable E_USER_DEPRECATED warnings to see if you are using any deprecated methods.:

```php
\Guzzle\Common\Version::$emitWarnings = true;
```

The following APIs and options have been marked as deprecated:

- Marked `Guzzle\Http\Message\Request::isResponseBodyRepeatable()` as deprecated. Use `$request->getResponseBody()->isRepeatable()` instead.
- Marked `Guzzle\Http\Message\Request::canCache()` as deprecated. Use `Guzzle\Plugin\Cache\DefaultCanCacheStrategy->canCacheRequest()` instead.
- Marked `Guzzle\Http\Message\Request::canCache()` as deprecated. Use `Guzzle\Plugin\Cache\DefaultCanCacheStrategy->canCacheRequest()` instead.
- Marked `Guzzle\Http\Message\Request::setIsRedirect()` as deprecated. Use the HistoryPlugin instead.
- Marked `Guzzle\Http\Message\Request::isRedirect()` as deprecated. Use the HistoryPlugin instead.
- Marked `Guzzle\Cache\CacheAdapterFactory::factory()` as deprecated
- Marked `Guzzle\Service\Client::enableMagicMethods()` as deprecated. Magic methods can no longer be disabled on a Guzzle\Service\Client.
- Marked `Guzzle\Parser\Url\UrlParser` as deprecated. Just use PHP's `parse_url()` and percent encode your UTF-8.
- Marked `Guzzle\Common\Collection::inject()` as deprecated.
- Marked `Guzzle\Plugin\CurlAuth\CurlAuthPlugin` as deprecated. Use
  `$client->getConfig()->setPath('request.options/auth', array('user', 'pass', 'Basic|Digest|NTLM|Any'));` or
  `$client->setDefaultOption('auth', array('user', 'pass', 'Basic|Digest|NTLM|Any'));`

3.7 introduces `request.options` as a parameter for a client configuration and as an optional argument to all creational
request methods. When paired with a client's configuration settings, these options allow you to specify default settings
for various aspects of a request. Because these options make other previous configuration options redundant, several
configuration options and methods of a client and AbstractCommand have been deprecated.

- Marked `Guzzle\Service\Client::getDefaultHeaders()` as deprecated. Use `$client->getDefaultOption('headers')`.
- Marked `Guzzle\Service\Client::setDefaultHeaders()` as deprecated. Use `$client->setDefaultOption('headers/{header_name}', 'value')`.
- Marked 'request.params' for `Guzzle\Http\Client` as deprecated. Use `$client->setDefaultOption('params/{param_name}', 'value')`
- Marked 'command.headers', 'command.response_body' and 'command.on_complete' as deprecated for AbstractCommand. These will work through Guzzle 4.0

        $command = $client->getCommand('foo', array(
            'command.headers' => array('Test' => '123'),
            'command.response_body' => '/path/to/file'
        ));

        // Should be changed to:

        $command = $client->getCommand('foo', array(
            'command.request_options' => array(
                'headers' => array('Test' => '123'),
                'save_as' => '/path/to/file'
            )
        ));

### Interface changes

Additions and changes (you will need to update any implementations or subclasses you may have created):

- Added an `$options` argument to the end of the following methods of `Guzzle\Http\ClientInterface`:
  createRequest, head, delete, put, patch, post, options, prepareRequest
- Added an `$options` argument to the end of `Guzzle\Http\Message\Request\RequestFactoryInterface::createRequest()`
- Added an `applyOptions()` method to `Guzzle\Http\Message\Request\RequestFactoryInterface`
- Changed `Guzzle\Http\ClientInterface::get($uri = null, $headers = null, $body = null)` to
  `Guzzle\Http\ClientInterface::get($uri = null, $headers = null, $options = array())`. You can still pass in a
  resource, string, or EntityBody into the $options parameter to specify the download location of the response.
- Changed `Guzzle\Common\Collection::__construct($data)` to no longer accepts a null value for `$data` but a
  default `array()`
- Added `Guzzle\Stream\StreamInterface::isRepeatable`
- Made `Guzzle\Http\Client::expandTemplate` and `getUriTemplate` protected methods.

The following methods were removed from interfaces. All of these methods are still available in the concrete classes
that implement them, but you should update your code to use alternative methods:

- Removed `Guzzle\Http\ClientInterface::setDefaultHeaders(). Use
  `$client->getConfig()->setPath('request.options/headers/{header_name}', 'value')`. or
  `$client->getConfig()->setPath('request.options/headers', array('header_name' => 'value'))` or
  `$client->setDefaultOption('headers/{header_name}', 'value')`. or
  `$client->setDefaultOption('headers', array('header_name' => 'value'))`.
- Removed `Guzzle\Http\ClientInterface::getDefaultHeaders(). Use `$client->getConfig()->getPath('request.options/headers')`.
- Removed `Guzzle\Http\ClientInterface::expandTemplate()`. This is an implementation detail.
- Removed `Guzzle\Http\ClientInterface::setRequestFactory()`. This is an implementation detail.
- Removed `Guzzle\Http\ClientInterface::getCurlMulti()`. This is a very specific implementation detail.
- Removed `Guzzle\Http\Message\RequestInterface::canCache`. Use the CachePlugin.
- Removed `Guzzle\Http\Message\RequestInterface::setIsRedirect`. Use the HistoryPlugin.
- Removed `Guzzle\Http\Message\RequestInterface::isRedirect`. Use the HistoryPlugin.

### Cache plugin breaking changes

- CacheKeyProviderInterface and DefaultCacheKeyProvider are no longer used. All of this logic is handled in a
  CacheStorageInterface. These two objects and interface will be removed in a future version.
- Always setting X-cache headers on cached responses
- Default cache TTLs are now handled by the CacheStorageInterface of a CachePlugin
- `CacheStorageInterface::cache($key, Response $response, $ttl = null)` has changed to `cache(RequestInterface
  $request, Response $response);`
- `CacheStorageInterface::fetch($key)` has changed to `fetch(RequestInterface $request);`
- `CacheStorageInterface::delete($key)` has changed to `delete(RequestInterface $request);`
- Added `CacheStorageInterface::purge($url)`
- `DefaultRevalidation::__construct(CacheKeyProviderInterface $cacheKey, CacheStorageInterface $cache, CachePlugin
  $plugin)` has changed to `DefaultRevalidation::__construct(CacheStorageInterface $cache,
  CanCacheStrategyInterface $canCache = null)`
- Added `RevalidationInterface::shouldRevalidate(RequestInterface $request, Response $response)`

3.5 to 3.6
----------

* Mixed casing of headers are now forced to be a single consistent casing across all values for that header.
* Messages internally use a HeaderCollection object to delegate handling case-insensitive header resolution
* Removed the whole changedHeader() function system of messages because all header changes now go through addHeader().
  For example, setHeader() first removes the header using unset on a HeaderCollection and then calls addHeader().
  Keeping the Host header and URL host in sync is now handled by overriding the addHeader method in Request.
* Specific header implementations can be created for complex headers. When a message creates a header, it uses a
  HeaderFactory which can map specific headers to specific header classes. There is now a Link header and
  CacheControl header implementation.
* Moved getLinks() from Response to just be used on a Link header object.

If you previously relied on Guzzle\Http\Message\Header::raw(), then you will need to update your code to use the
HeaderInterface (e.g. toArray(), getAll(), etc.).

### Interface changes

* Removed from interface: Guzzle\Http\ClientInterface::setUriTemplate
* Removed from interface: Guzzle\Http\ClientInterface::setCurlMulti()
* Removed Guzzle\Http\Message\Request::receivedRequestHeader() and implemented this functionality in
  Guzzle\Http\Curl\RequestMediator
* Removed the optional $asString parameter from MessageInterface::getHeader(). Just cast the header to a string.
* Removed the optional $tryChunkedTransfer option from Guzzle\Http\Message\EntityEnclosingRequestInterface
* Removed the $asObjects argument from Guzzle\Http\Message\MessageInterface::getHeaders()

### Removed deprecated functions

* Removed Guzzle\Parser\ParserRegister::get(). Use getParser()
* Removed Guzzle\Parser\ParserRegister::set(). Use registerParser().

### Deprecations

* The ability to case-insensitively search for header values
* Guzzle\Http\Message\Header::hasExactHeader
* Guzzle\Http\Message\Header::raw. Use getAll()
* Deprecated cache control specific methods on Guzzle\Http\Message\AbstractMessage. Use the CacheControl header object
  instead.

### Other changes

* All response header helper functions return a string rather than mixing Header objects and strings inconsistently
* Removed cURL blacklist support. This is no longer necessary now that Expect, Accept, etc. are managed by Guzzle
  directly via interfaces
* Removed the injecting of a request object onto a response object. The methods to get and set a request still exist
  but are a no-op until removed.
* Most classes that used to require a `Guzzle\Service\Command\CommandInterface` typehint now request a
  `Guzzle\Service\Command\ArrayCommandInterface`.
* Added `Guzzle\Http\Message\RequestInterface::startResponse()` to the RequestInterface to handle injecting a response
  on a request while the request is still being transferred
* `Guzzle\Service\Command\CommandInterface` now extends from ToArrayInterface and ArrayAccess

3.3 to 3.4
----------

Base URLs of a client now follow the rules of https://datatracker.ietf.org/doc/html/rfc3986#section-5.2.2 when merging URLs.

3.2 to 3.3
----------

### Response::getEtag() quote stripping removed

`Guzzle\Http\Message\Response::getEtag()` no longer strips quotes around the ETag response header

### Removed `Guzzle\Http\Utils`

The `Guzzle\Http\Utils` class was removed. This class was only used for testing.

### Stream wrapper and type

`Guzzle\Stream\Stream::getWrapper()` and `Guzzle\Stream\Stream::getStreamType()` are no longer converted to lowercase.

### curl.emit_io became emit_io

Emitting IO events from a RequestMediator is now a parameter that must be set in a request's curl options using the
'emit_io' key. This was previously set under a request's parameters using 'curl.emit_io'

3.1 to 3.2
----------

### CurlMulti is no longer reused globally

Before 3.2, the same CurlMulti object was reused globally for each client. This can cause issue where plugins added
to a single client can pollute requests dispatched from other clients.

If you still wish to reuse the same CurlMulti object with each client, then you can add a listener to the
ServiceBuilder's `service_builder.create_client` event to inject a custom CurlMulti object into each client as it is
created.

```php
$multi = new Guzzle\Http\Curl\CurlMulti();
$builder = Guzzle\Service\Builder\ServiceBuilder::factory('/path/to/config.json');
$builder->addListener('service_builder.create_client', function ($event) use ($multi) {
    $event['client']->setCurlMulti($multi);
}
});
```

### No default path

URLs no longer have a default path value of '/' if no path was specified.

Before:

```php
$request = $client->get('http://www.foo.com');
echo $request->getUrl();
// >> http://www.foo.com/
```

After:

```php
$request = $client->get('http://www.foo.com');
echo $request->getUrl();
// >> http://www.foo.com
```

### Less verbose BadResponseException

The exception message for `Guzzle\Http\Exception\BadResponseException` no longer contains the full HTTP request and
response information. You can, however, get access to the request and response object by calling `getRequest()` or
`getResponse()` on the exception object.

### Query parameter aggregation

Multi-valued query parameters are no longer aggregated using a callback function. `Guzzle\Http\Query` now has a
setAggregator() method that accepts a `Guzzle\Http\QueryAggregator\QueryAggregatorInterface` object. This object is
responsible for handling the aggregation of multi-valued query string variables into a flattened hash.

2.8 to 3.x
----------

### Guzzle\Service\Inspector

Change `\Guzzle\Service\Inspector::fromConfig` to `\Guzzle\Common\Collection::fromConfig`

**Before**

```php
use Guzzle\Service\Inspector;

class YourClient extends \Guzzle\Service\Client
{
    public static function factory($config = array())
    {
        $default = array();
        $required = array('base_url', 'username', 'api_key');
        $config = Inspector::fromConfig($config, $default, $required);

        $client = new self(
            $config->get('base_url'),
            $config->get('username'),
            $config->get('api_key')
        );
        $client->setConfig($config);

        $client->setDescription(ServiceDescription::factory(__DIR__ . DIRECTORY_SEPARATOR . 'client.json'));

        return $client;
    }
```

**After**

```php
use Guzzle\Common\Collection;

class YourClient extends \Guzzle\Service\Client
{
    public static function factory($config = array())
    {
        $default = array();
        $required = array('base_url', 'username', 'api_key');
        $config = Collection::fromConfig($config, $default, $required);

        $client = new self(
            $config->get('base_url'),
            $config->get('username'),
            $config->get('api_key')
        );
        $client->setConfig($config);

        $client->setDescription(ServiceDescription::factory(__DIR__ . DIRECTORY_SEPARATOR . 'client.json'));

        return $client;
    }
```

### Convert XML Service Descriptions to JSON

**Before**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<client>
    <commands>
        <!-- Groups -->
        <command name="list_groups" method="GET" uri="groups.json">
            <doc>Get a list of groups</doc>
        </command>
        <command name="search_groups" method="GET" uri='search.json?query="{{query}} type:group"'>
            <doc>Uses a search query to get a list of groups</doc>
            <param name="query" type="string" required="true" />
        </command>
        <command name="create_group" method="POST" uri="groups.json">
            <doc>Create a group</doc>
            <param name="data" type="array" location="body" filters="json_encode" doc="Group JSON"/>
            <param name="Content-Type" location="header" static="application/json"/>
        </command>
        <command name="delete_group" method="DELETE" uri="groups/{{id}}.json">
            <doc>Delete a group by ID</doc>
            <param name="id" type="integer" required="true"/>
        </command>
        <command name="get_group" method="GET" uri="groups/{{id}}.json">
            <param name="id" type="integer" required="true"/>
        </command>
        <command name="update_group" method="PUT" uri="groups/{{id}}.json">
            <doc>Update a group</doc>
            <param name="id" type="integer" required="true"/>
            <param name="data" type="array" location="body" filters="json_encode" doc="Group JSON"/>
            <param name="Content-Type" location="header" static="application/json"/>
        </command>
    </commands>
</client>
```

**After**

```json
{
    "name":       "Zendesk REST API v2",
    "apiVersion": "2012-12-31",
    "description":"Provides access to Zendesk views, groups, tickets, ticket fields, and users",
    "operations": {
        "list_groups":  {
            "httpMethod":"GET",
            "uri":       "groups.json",
            "summary":   "Get a list of groups"
        },
        "search_groups":{
            "httpMethod":"GET",
            "uri":       "search.json?query=\"{query} type:group\"",
            "summary":   "Uses a search query to get a list of groups",
            "parameters":{
                "query":{
                    "location":   "uri",
                    "description":"Zendesk Search Query",
                    "type":       "string",
                    "required":   true
                }
            }
        },
        "create_group": {
            "httpMethod":"POST",
            "uri":       "groups.json",
            "summary":   "Create a group",
            "parameters":{
                "data":        {
                    "type":       "array",
                    "location":   "body",
                    "description":"Group JSON",
                    "filters":    "json_encode",
                    "required":   true
                },
                "Content-Type":{
                    "type":    "string",
                    "location":"header",
                    "static":  "application/json"
                }
            }
        },
        "delete_group": {
            "httpMethod":"DELETE",
            "uri":       "groups/{id}.json",
            "summary":   "Delete a group",
            "parameters":{
                "id":{
                    "location":   "uri",
                    "description":"Group to delete by ID",
                    "type":       "integer",
                    "required":   true
                }
            }
        },
        "get_group":    {
            "httpMethod":"GET",
            "uri":       "groups/{id}.json",
            "summary":   "Get a ticket",
            "parameters":{
                "id":{
                    "location":   "uri",
                    "description":"Group to get by ID",
                    "type":       "integer",
                    "required":   true
                }
            }
        },
        "update_group": {
            "httpMethod":"PUT",
            "uri":       "groups/{id}.json",
            "summary":   "Update a group",
            "parameters":{
                "id":          {
                    "location":   "uri",
                    "description":"Group to update by ID",
                    "type":       "integer",
                    "required":   true
                },
                "data":        {
                    "type":       "array",
                    "location":   "body",
                    "description":"Group JSON",
                    "filters":    "json_encode",
                    "required":   true
                },
                "Content-Type":{
                    "type":    "string",
                    "location":"header",
                    "static":  "application/json"
                }
            }
        }
}
```

### Guzzle\Service\Description\ServiceDescription

Commands are now called Operations

**Before**

```php
use Guzzle\Service\Description\ServiceDescription;

$sd = new ServiceDescription();
$sd->getCommands();     // @returns ApiCommandInterface[]
$sd->hasCommand($name);
$sd->getCommand($name); // @returns ApiCommandInterface|null
$sd->addCommand($command); // @param ApiCommandInterface $command
```

**After**

```php
use Guzzle\Service\Description\ServiceDescription;

$sd = new ServiceDescription();
$sd->getOperations();           // @returns OperationInterface[]
$sd->hasOperation($name);
$sd->getOperation($name);       // @returns OperationInterface|null
$sd->addOperation($operation);  // @param OperationInterface $operation
```

### Guzzle\Common\Inflection\Inflector

Namespace is now `Guzzle\Inflection\Inflector`

### Guzzle\Http\Plugin

Namespace is now `Guzzle\Plugin`. Many other changes occur within this namespace and are detailed in their own sections below.

### Guzzle\Http\Plugin\LogPlugin and Guzzle\Common\Log

Now `Guzzle\Plugin\Log\LogPlugin` and `Guzzle\Log` respectively.

**Before**

```php
use Guzzle\Common\Log\ClosureLogAdapter;
use Guzzle\Http\Plugin\LogPlugin;

/** @var \Guzzle\Http\Client */
$client;

// $verbosity is an integer indicating desired message verbosity level
$client->addSubscriber(new LogPlugin(new ClosureLogAdapter(function($m) { echo $m; }, $verbosity = LogPlugin::LOG_VERBOSE);
```

**After**

```php
use Guzzle\Log\ClosureLogAdapter;
use Guzzle\Log\MessageFormatter;
use Guzzle\Plugin\Log\LogPlugin;

/** @var \Guzzle\Http\Client */
$client;

// $format is a string indicating desired message format -- @see MessageFormatter
$client->addSubscriber(new LogPlugin(new ClosureLogAdapter(function($m) { echo $m; }, $format = MessageFormatter::DEBUG_FORMAT);
```

### Guzzle\Http\Plugin\CurlAuthPlugin

Now `Guzzle\Plugin\CurlAuth\CurlAuthPlugin`.

### Guzzle\Http\Plugin\ExponentialBackoffPlugin

Now `Guzzle\Plugin\Backoff\BackoffPlugin`, and other changes.

**Before**

```php
use Guzzle\Http\Plugin\ExponentialBackoffPlugin;

$backoffPlugin = new ExponentialBackoffPlugin($maxRetries, array_merge(
        ExponentialBackoffPlugin::getDefaultFailureCodes(), array(429)
    ));

$client->addSubscriber($backoffPlugin);
```

**After**

```php
use Guzzle\Plugin\Backoff\BackoffPlugin;
use Guzzle\Plugin\Backoff\HttpBackoffStrategy;

// Use convenient factory method instead -- see implementation for ideas of what
// you can do with chaining backoff strategies
$backoffPlugin = BackoffPlugin::getExponentialBackoff($maxRetries, array_merge(
        HttpBackoffStrategy::getDefaultFailureCodes(), array(429)
    ));
$client->addSubscriber($backoffPlugin);
```

### Known Issues

#### [BUG] Accept-Encoding header behavior changed unintentionally.

(See #217) (Fixed in 09daeb8c666fb44499a0646d655a8ae36456575e)

In version 2.8 setting the `Accept-Encoding` header would set the CURLOPT_ENCODING option, which permitted cURL to
properly handle gzip/deflate compressed responses from the server. In versions affected by this bug this does not happen.
See issue #217 for a workaround, or use a version containing the fix.
