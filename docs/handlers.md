# Handlers

Guzzle clients send HTTP requests through a *handler*: a function that transfers
a request over the wire and settles a promise with the response. Handlers are
composed with [middleware](middleware.md) to build a client's behavior.

## Handlers

A handler function accepts a `Psr\Http\Message\RequestInterface` and array of
request options and returns a
`GuzzleHttp\Promise\PromiseInterface<Psr\Http\Message\ResponseInterface, mixed>`
that is fulfilled with a `Psr\Http\Message\ResponseInterface` or rejected with a
reason.

You can provide a custom handler to a client using the `handler` option of a
client constructor. It is important to understand that several request options
used by Guzzle require that specific middlewares wrap the handler used by the
client. You can ensure that the handler you provide to a client uses the default
middlewares by wrapping the handler in the
`GuzzleHttp\HandlerStack::create(callable $handler = null)` static method.

```php
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Handler\CurlHandler;

$handler = new CurlHandler();
$stack = HandlerStack::create($handler); // Wrap w/ middleware
$client = new Client(['handler' => $stack]);
```

The `create` method adds default handlers to the `HandlerStack`. When the
`HandlerStack` is resolved, the handlers will execute in the following order:

1.  Sending request:

> 1.  `http_errors` - No op when sending a request. The response status code is
>     checked in the response processing when returning a response promise up
>     the stack.
> 2.  `allow_redirects` - No op when sending a request. Following redirects
>     occurs when a response promise is being returned up the stack.
> 3.  `auth` - Adds Basic authentication headers and handles Digest
>     authentication challenges when the `auth` request option is set.
> 4.  `cookies` - Adds cookies to requests.
> 5.  `prepare_body` - Prepares the body of an HTTP request: it infers
>     `Content-Type` from a file-backed body, adds `Content-Length` when the
>     body size is known or a provisional `Transfer-Encoding: chunked` marker
>     for an unknown HTTP/1.1 body, and applies the `Expect: 100-Continue`
>     behavior controlled by the [`expect`](request-options.md#expect) request
>     option. The built-in handler finalizes framing before I/O.
> 6.  <send request with handler>

2.  Processing response:

> 1.  `prepare_body` - no op on response processing.
> 2.  `cookies` - extracts response cookies into the cookie jar.
> 3.  `auth` - handles Digest authentication challenges before HTTP errors are
>     raised.
> 4.  `allow_redirects` - Follows redirects.
> 5.  `http_errors` - throws exceptions when the response status code `>=` 400.

When provided no `$handler` argument, `GuzzleHttp\HandlerStack::create()` will
choose the most appropriate handler based on the extensions available on your
system.

> [!IMPORTANT]
> The handler provided to a client determines how request options are applied
> and utilized for each request sent by a client. For example, if you do not
> have a cookie middleware associated with a client, then setting the `cookies`
> request option will have no effect on the request.

### PSR-17 Factories

The PSR-17 factory request options are owned by different layers:

- `request_factory` and `uri_factory` are used by the client when it builds the
  outgoing request and resolves URIs.
- `response_factory` is handler-owned: the built-in cURL and stream handlers use
  it to create the response message (status code, reason phrase, headers, and
  protocol version). It should return an empty, header-less response.
- `stream_factory` has split responsibility. The client uses it for request body
  creation (`body`, `form_params`, `json`) and redirect body resets, while the
  built-in handlers use it to wrap response body resources where practical.

The default handlers consume `response_factory` and `stream_factory` when
constructing responses. `MockHandler` returns the responses you queue, and
custom handlers are responsible for honoring these options themselves.

> [!WARNING]
> Replacing Guzzle's PSR-7 implementation through these options is an advanced
> feature that moves responsibility for correctness and security to your code.
> Guzzle validates only that each value implements the relevant PSR-17 interface
> — it does not validate the objects a factory returns. An implementation that
> does not honor the documented contracts can introduce bugs or security issues:
> streams that drop live `timed_out` metadata silently disable read-timeout
> detection, streams that do not close their resource leak file descriptors,
> response factories that pre-seed headers corrupt the message, and URIs that
> misreport scheme, host, or port can defeat the cross-origin credential
> stripping that protects against credential leaks on redirects. See the
> per-option notes under [Request Options](request-options.md) for specifics.

### Closing cURL Handlers

The cURL handlers own native cURL resources. These resources are normally
released automatically when the handler is garbage collected. Applications that
need deterministic cleanup may call `close()` on
`GuzzleHttp\Handler\CurlHandler` or `GuzzleHttp\Handler\CurlMultiHandler`.
Applications that construct `GuzzleHttp\Handler\CurlFactory` directly may also
call `close()` on the factory to close idle easy handles.

After `close()` has been called, the handler must not be reused. Create a new
handler and handler stack for future requests.

If `CurlMultiHandler::close()` is called while transfers are pending, those
promises are rejected with `GuzzleHttp\Exception\HandlerClosedException`.
Destructors perform best-effort cleanup and do not reject pending promises.
Explicit `close()` calls may throw if native cleanup fails.

`Client` and `HandlerStack` do not expose `close()`. Keep a reference to the
cURL handler if your application needs deterministic cleanup. Closing a cURL
handler closes only the `CurlFactory` instance that Guzzle created for that
handler. If you pass a custom `handle_factory`, Guzzle treats that factory as
caller-owned and does not close it.

```php
use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\HandlerStack;

$handler = new CurlMultiHandler();
$stack = HandlerStack::create($handler);
$client = new Client(['handler' => $stack]);

try {
    $client->request('GET', 'https://example.com');
} finally {
    $handler->close();
}
```

When closing a multi handler with in-flight work, handle
`HandlerClosedException` like any other transfer failure if the pending promises
may still be observed:

```php
use GuzzleHttp\Exception\HandlerClosedException;
use GuzzleHttp\Psr7\Request;

$promise = $handler(new Request('GET', 'https://example.com'), []);

$handler->close();

try {
    $promise->wait();
} catch (HandlerClosedException $e) {
    // The handler was closed before this transfer completed.
}
```

## Transport Sharing

By default, Guzzle does not ask handlers to share low-level transport state.

Use the `transport_sharing` client constructor option when Guzzle creates the
default handler:

```php
use GuzzleHttp\Client;
use GuzzleHttp\TransportSharing;

$client = new Client([
    'transport_sharing' => TransportSharing::PERSISTENT_PREFER,
]);
```

`TransportSharing::NONE` disables transport sharing. This is the default.

`TransportSharing::HANDLER_PREFER` asks the selected handler to share transport
state for the lifetime of that handler when it can. Guzzle's cURL handlers use
cURL share handles to share DNS and SSL session cache state. When PHP 8.6+
provides the OpenSSL session API, the stream handler can share HTTPS TLS
sessions for the lifetime of that stream handler. If sharing cannot be
configured, or if the selected handler does not support sharing, Guzzle
continues without sharing.

Guzzle only enables cURL transport sharing for libcurl versions that support the
requested shared state safely. Handler-lifetime cURL sharing requires libcurl
7.35.0 or newer. SSL session cache sharing requires libcurl 8.6.0 or newer and
libcurl SSL support. On older libcurl versions that still meet the
handler-lifetime sharing floor, `TransportSharing::HANDLER_PREFER` shares DNS
cache state without sharing SSL session cache state.

`TransportSharing::HANDLER_REQUIRE` requires handler-lifetime transport
sharing. Guzzle fails when it cannot select a cURL handler with cURL share
support or a PHP 8.6+ stream handler with the OpenSSL session API that can apply
TLS session resumption for the request, when sharing cannot be configured, or
when a request is routed to a handler that does not support sharing. When the
installed libcurl version cannot safely share both DNS and SSL session cache
state, Guzzle does not select the cURL handlers, so required sharing must be
satisfied by a PHP 8.6+ stream handler with the OpenSSL session API instead.

`TransportSharing::PERSISTENT_PREFER` asks Guzzle to use the strongest sharing
available in the current environment. Guzzle first tries persistent cURL share
handles, which can share DNS, connection, and SSL session cache state across
handler lifetimes. If persistent sharing is unavailable or cannot be created,
Guzzle falls back to `TransportSharing::HANDLER_PREFER`. If handler-lifetime
sharing is also unavailable, Guzzle continues without sharing.

Persistent cURL sharing requires PHP persistent cURL share handle support and
libcurl 8.12.0 or newer because persistent sharing includes libcurl connection
cache state. `TransportSharing::PERSISTENT_PREFER` falls back to
handler-lifetime sharing when persistent connection sharing is unavailable.
`TransportSharing::PERSISTENT_REQUIRE` fails when persistent connection sharing
is unavailable.

`TransportSharing::PERSISTENT_REQUIRE` requires PHP persistent cURL share
handles and does not fall back to handler-lifetime sharing. If persistent
sharing is unavailable or cannot be created, Guzzle fails while creating the
handler.

Because `TransportSharing::PERSISTENT_REQUIRE` requires connection cache
sharing, Guzzle rejects request-level cURL options or proxy tunnel cases that
require a fresh connection for safety. This includes anonymous HTTP and HTTPS
proxy tunnels: the worker-global pool could lend them a tunnel another
producer authenticated. `TransportSharing::PERSISTENT_PREFER` forces such
tunnels onto fresh, non-reusable connections instead.

> [!IMPORTANT]
> **Persistent connection sharing carries two independent risks.**
>
> 1. *libcurl version: mTLS client certificates.* For a default `https://`
>    request, meaning default verification with no client certificate and no
>    proxy authentication, libcurl's connection-reuse matching is correct from
>    the 8.12.0 floor. It is **not** complete for client-certificate private-key
>    options until libcurl 8.21.0 (CVE-2026-8932), and Guzzle does not section
>    direct `cert` / `ssl_key` requests the way it sections proxy tunnels. So on
>    libcurl 8.12.0–8.20.x a worker that uses **different client-certificate
>    identities for the same host** can reuse a connection authenticated with a
>    different key. Enabling persistent sharing accepts that risk; to avoid it,
>    run libcurl 8.21.0+ or do not mix client-certificate identities under one
>    pool. `HANDLER_*` narrows the exposure to your own code but does not fix
>    the libcurl bug. Proxy tunnels are unaffected: Guzzle forces a fresh
>    tunnel for recognized proxy authentication and for anonymous tunnels
>    through the worker-global pool, or rejects the request under
>    `PERSISTENT_REQUIRE`.
> 2. *Worker-global scope, at every libcurl version.* The persistent pool is
>    keyed only by which cache types it shares and lives in process- or
>    thread-global state, so it **cannot be scoped to Guzzle alone**: any other
>    code in the worker that enables persistent sharing draws from the same
>    pool. Enable it only when you control the whole worker, or when worker-wide
>    sharing is acceptable. It requires PHP 8.5 or newer.
>
> For connection reuse that stays private to your code, prefer `HANDLER_*` with
> a long-lived client, especially under long-running runtimes such as
> RoadRunner, Swoole, or FrankenPHP worker mode.

Transport sharing does not share cookies. Cookies are managed by Guzzle
middleware.

The stream handler's sharing support is narrower than cURL's. It only shares TLS
session state for HTTPS requests, scoped to one stream handler instance. It does
not share DNS entries, live connections, or persistent process-wide state.
Persistent sharing remains a cURL-only feature: `PERSISTENT_PREFER` may fall
back to stream handler TLS session sharing, but `PERSISTENT_REQUIRE` cannot be
satisfied by the stream handler.

> [!WARNING]
> TLS resumption reuses the original certificate-chain verification result
> instead of building and verifying the chain again. Guzzle includes ambient
> trust configuration paths in the cache identity, but cannot detect
> trust-store contents rewritten at an unchanged path. Recreate the handler
> or disable transport sharing when trust changes must take effect
> immediately.

New sessions are held temporarily and committed only after the HTTPS stream
opens successfully. If PHP rejects peer verification or another stream-open
step fails, any session reported during that attempt is discarded.

When the stream handler sees requests or TLS settings that cannot safely share
automatic session state, such as plain `http://` requests, proxies that use TLS
stream transports, certificate-capture stream context options, `no_ticket`,
`verify` with a path, `cert`, or `ssl_key`, it will not apply automatic TLS
session sharing. Preferred modes continue without sharing, and `HANDLER_REQUIRE`
fails loudly. Unsupported or conflicting raw stream context TLS options are
still rejected by the stream handler before sharing is applied.

When constructing cURL handlers manually, configure sharing with the handler
`transport_sharing` option:

```php
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\TransportSharing;

$handler = new CurlHandler([
    'transport_sharing' => TransportSharing::HANDLER_REQUIRE,
]);
```

The stream handler accepts the same option for handler-lifetime HTTPS TLS
session sharing on PHP 8.6+ with the OpenSSL session API, subject to the
narrower scope described above:

```php
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\TransportSharing;

$handler = new StreamHandler([
    'transport_sharing' => TransportSharing::HANDLER_REQUIRE,
]);
```

## Creating a Handler

As stated earlier, a handler is a function that accepts a
`Psr\Http\Message\RequestInterface` and an array of request options. A handler
used with Guzzle middleware returns a
`GuzzleHttp\Promise\PromiseInterface<Psr\Http\Message\ResponseInterface, mixed>`
that is fulfilled with a `Psr\Http\Message\ResponseInterface` or rejected with a
reason.

```php
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * @return PromiseInterface<ResponseInterface, mixed>
 */
function handler(RequestInterface $request, array $options): PromiseInterface
{
    // Send the request and settle the returned promise with a response.
}
```

Most custom handlers should be wrapped with
`GuzzleHttp\HandlerStack::create($handler)`. This keeps Guzzle's default
middleware behavior for redirects, cookies, HTTP errors, and request body
preparation while still allowing the custom handler to own the underlying
transport.

Synchronous client methods set `GuzzleHttp\RequestOptions::SYNCHRONOUS` before
invoking the handler and then call `wait()` on the returned promise. The
`synchronous` option is a hint that the caller intends to wait, not permission
for a handler to return a response directly. A handler promise's `wait()` method
must complete the transfer or throw the rejection reason. Asynchronous client
methods return the promise without blocking.

### Request Option Ownership

Request options are applied by different parts of Guzzle. A custom handler
should know which options are already reflected in the request it receives and
which options still need transport support.

| Owner | Examples | Notes |
| --- | --- | --- |
| Client-applied options | `base_uri`, `headers`, `body`, `form_params`, `multipart`, `json`, `query`, `version`, `idn_conversion` | These options affect request construction or request mutation before the final handler sends the request. |
| Middleware-dependent options | `allow_redirects`, Basic and Digest `auth`, `cookies`, `http_errors`, `expect` | These options require the relevant middleware, normally from `HandlerStack::create()`. |
| Handler-owned options | `delay`, `timeout`, `connect_timeout`, `read_timeout`, `stream`, `sink`, `verify`, `cert`, `ssl_key`, `proxy`, `force_ip_resolve`, `decode_content`, `progress`, `on_headers`, `on_stats`, `debug` | These options describe transport behavior and need explicit handler support or clear unsupported behavior. |

Some options have split responsibilities. Basic and Digest `auth` are applied by
the auth middleware. Legacy NTLM authentication is not a built-in `auth` type;
it can only be attempted through cURL HTTP authentication options while the
selected libcurl build still supports NTLM. curl/libcurl has deprecated NTLM
because it is weak, deprecated by Microsoft, and does not work over HTTP/2 or
HTTP/3. curl made NTLM opt-in in curl 8.20.0 and plans to remove support in
September 2026. The `expect` option is used by the body preparation middleware
to add `Expect: 100-Continue`, but the transport still determines whether the
protocol workflow is supported. The `decode_content` option can affect the
`Accept-Encoding` request header, but response decoding is handled by the
transport. Redirect middleware validates redirect targets with
`allow_redirects.protocols`, but the handler is still responsible for enforcing
which schemes it can send.

Raw custom handlers do not receive Basic or Digest authentication automatically.
Wrap custom handlers with `HandlerStack::create($handler)` or add
`Middleware::auth()` to a custom stack when those built-in authentication types
are needed.

### Handler-Owned Transfer Options

A handler is responsible for applying the following request options. These
request options are a subset of request options called "transfer options".

- [`cert`](request-options.md#cert)
- [`cert_type`](request-options.md#cert_type)
- [`connect_timeout`](request-options.md#connect_timeout)
- [`crypto_method`](request-options.md#crypto_method)
- [`crypto_method_max`](request-options.md#crypto_method_max)
- [`debug`](request-options.md#debug)
- [`delay`](request-options.md#delay)
- [`decode_content`](request-options.md#decode_content)
- [`expect`](request-options.md#expect)
- [`force_ip_resolve`](request-options.md#force_ip_resolve)
- [`multiplex`](request-options.md#multiplex)
- [`on_headers`](request-options.md#on_headers)
- [`on_stats`](request-options.md#on_stats)
- [`on_trailers`](request-options.md#on_trailers)
- [`progress`](request-options.md#progress)
- [`protocols`](request-options.md#protocols)
- [`proxy`](request-options.md#proxy)
- [`read_timeout`](request-options.md#read_timeout)
- [`sink`](request-options.md#sink)
- [`timeout`](request-options.md#timeout)
- [`ssl_key`](request-options.md#ssl_key)
- [`ssl_key_type`](request-options.md#ssl_key_type)
- [`stream`](request-options.md#stream)
- [`verify`](request-options.md#verify)

Transport-specific options such as `curl` and `stream_context` are intended for
the handlers that understand them. A non-cURL handler should reject or document
how it treats cURL-specific options, and a non-stream handler should do the same
for PHP stream context options.

Handlers that support the `proxy` option should use
`GuzzleHttp\ProxyOptions::resolve()` unless they intentionally document
different proxy selection semantics. The returned `GuzzleHttp\ProxySelection`
identifies the selected proxy string, no-proxy bypasses, and explicit
proxy-disable cases. The handler is still responsible for applying that
selection to its transport. The environment-variable fallback performed by the
built-in handlers is not part of the helper; handlers that want it must
implement their own environment lookup.

First-party handlers should not silently ignore documented handler-owned options
that users reasonably expect to affect transport behavior. They should implement
the option, reject the request with a clear exception when the option is
present, or document a deliberate no-op where the option has no meaningful
transport equivalent. Custom handlers should follow the same pattern where
practical.

### Callback Semantics

The `on_headers` option is invoked after the final response headers, or a `101
Switching Protocols` response, have been received and before response body bytes
are written to the configured `sink`. Built-in handlers do not invoke it for
other informational `1xx` responses. The callback receives the response object
and the corresponding request object. If it throws, the request promise is
rejected with a `GuzzleHttp\Exception\ResponseException` that wraps the thrown
exception.

The `on_stats` option is invoked when the handler has finished sending a
request, with a `GuzzleHttp\TransferStats` object that describes the response
received or the error encountered. Exceptions thrown by `on_stats` are not
wrapped by Guzzle and may escape from the handler wait path. Built-in cURL
handlers release native cURL handles before invoking `on_stats` and may invoke
it per low-level transfer attempt.

The `progress` option is invoked with the documented argument order: the total
number of bytes expected to be downloaded, the number of bytes downloaded so
far, the total number of bytes expected to be uploaded, and the number of bytes
uploaded so far. With the built-in cURL handlers, returning a truthy value
aborts the transfer and rejects the request promise with a
`GuzzleHttp\Exception\ResponseException` when a response is available, or a
`GuzzleHttp\Exception\RequestException` otherwise. If the callback throws, the
cURL handlers reject the promise with the same response-aware classification
while wrapping the thrown exception. The built-in stream handler treats progress
callbacks as notifications and ignores return values. A handler that cannot
provide progress information should reject the option or clearly document that
progress reporting is unsupported.

### Promise Queue Integration

Guzzle promises settle callbacks through the Guzzle promise task queue. The
queue is drained automatically during synchronous `wait()`, but it is not
automatically driven by arbitrary event loops. A handler that resolves or
rejects Guzzle promises from an external scheduler must ensure the Guzzle
promise task queue is drained while that scheduler is running.

After resolving or rejecting a Guzzle promise from an external scheduler,
schedule `GuzzleHttp\Promise\Utils::queue()->run()` on that scheduler soon,
without blocking the scheduler.

```php
use GuzzleHttp\Promise\Utils as PromiseUtils;

// Pseudocode for an external scheduler callback.
$promise->resolve($response);

ExternalLoop::queue(static function (): void {
    PromiseUtils::queue()->run();
});
```

Do not rely only on `wait()` to drain the queue because asynchronous users may
attach callbacks and expect them to run while their event loop is active. Avoid
running the queue in a tight polling loop, and avoid replacing the global task
queue unless your library fully owns the process runtime. External futures or
promises should generally be adapted by intentionally settling a Guzzle promise,
because `then()`, `wait()`, and `cancel()` semantics often differ between
promise implementations.

## Related

- [Middleware](middleware.md)
- [Request Options](request-options.md)
- [Testing Guzzle Clients](testing-guzzle-clients.md)
