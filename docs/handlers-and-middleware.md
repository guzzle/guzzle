# Handlers and Middleware

Guzzle clients use a handler and middleware system to send HTTP requests.

## Handlers

A handler function accepts a `Psr\Http\Message\RequestInterface` and array of request options and returns a `GuzzleHttp\Promise\PromiseInterface<Psr\Http\Message\ResponseInterface, mixed>` that is fulfilled with a `Psr\Http\Message\ResponseInterface` or rejected with a reason.

You can provide a custom handler to a client using the `handler` option of a client constructor. It is important to understand that several request options used by Guzzle require that specific middlewares wrap the handler used by the client. You can ensure that the handler you provide to a client uses the default middlewares by wrapping the handler in the `GuzzleHttp\HandlerStack::create(callable $handler = null)` static method.

```php
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Handler\CurlHandler;

$handler = new CurlHandler();
$stack = HandlerStack::create($handler); // Wrap w/ middleware
$client = new Client(['handler' => $stack]);
```

The `create` method adds default handlers to the `HandlerStack`. When the `HandlerStack` is resolved, the handlers will execute in the following order:

1.  Sending request:

> 1.  `http_errors` - No op when sending a request. The response status code is checked in the response processing when returning a response promise up the stack.
> 2.  `allow_redirects` - No op when sending a request. Following redirects occurs when a response promise is being returned up the stack.
> 3.  `cookies` - Adds cookies to requests.
> 4.  `prepare_body` - The body of an HTTP request will be prepared (e.g., add default headers like Content-Length, Content-Type, etc.).
> 5.  <send request with handler>

2.  Processing response:

> 1.  `prepare_body` - no op on response processing.
> 2.  `cookies` - extracts response cookies into the cookie jar.
> 3.  `allow_redirects` - Follows redirects.
> 4.  `http_errors` - throws exceptions when the response status code `>=` 400.

When provided no `$handler` argument, `GuzzleHttp\HandlerStack::create()` will choose the most appropriate handler based on the extensions available on your system.

> [!IMPORTANT]
> The handler provided to a client determines how request options are applied and utilized for each request sent by a client. For example, if you do not have a cookie middleware associated with a client, then setting the `cookies` request option will have no effect on the request.

### PSR-17 factories

The PSR-17 factory request options are owned by different layers:

- `request_factory` and `uri_factory` are used by the client when it builds the outgoing request and resolves URIs.
- `response_factory` is handler-owned: the built-in cURL and stream handlers use it to create the response message (status code, reason phrase, headers, and protocol version). It should return an empty, header-less response.
- `stream_factory` has split responsibility. The client uses it for request body creation (`body`, `form_params`, `json`) and redirect body resets, while the built-in handlers use it to wrap response body resources where practical.

The default handlers consume `response_factory` and `stream_factory` when constructing responses. `MockHandler` returns the responses you queue, and custom handlers are responsible for honoring these options themselves.

> [!WARNING]
> Replacing Guzzle's PSR-7 implementation through these options is an advanced feature that moves responsibility for correctness and security to your code. Guzzle validates only that each value implements the relevant PSR-17 interface — it does not validate the objects a factory returns. An implementation that does not honor the documented contracts can introduce bugs or security issues: streams that drop live `timed_out` metadata silently disable read-timeout detection, streams that do not close their resource leak file descriptors, response factories that pre-seed headers corrupt the message, and URIs that misreport scheme, host, or port can defeat the cross-origin credential stripping that protects against credential leaks on redirects. See the per-option notes under [Request Options](request-options.md) for specifics.

### Closing cURL Handlers

The cURL handlers own native cURL resources. These resources are normally released automatically when the handler is garbage collected. Applications that need deterministic cleanup may call `close()` on `GuzzleHttp\Handler\CurlHandler` or `GuzzleHttp\Handler\CurlMultiHandler`. Applications that construct `GuzzleHttp\Handler\CurlFactory` directly may also call `close()` on the factory to close idle easy handles.

After `close()` has been called, the handler must not be reused. Create a new handler and handler stack for future requests.

If `CurlMultiHandler::close()` is called while transfers are pending, those promises are rejected with `GuzzleHttp\Exception\HandlerClosedException`. Destructors perform best-effort cleanup and do not reject pending promises. Explicit `close()` calls may throw if native cleanup fails.

`Client` and `HandlerStack` do not expose `close()`. Keep a reference to the cURL handler if your application needs deterministic cleanup. Closing a cURL handler closes only the `CurlFactory` instance that Guzzle created for that handler. If you pass a custom `handle_factory`, Guzzle treats that factory as caller-owned and does not close it.

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

When closing a multi handler with in-flight work, handle `HandlerClosedException` like any other transfer failure if the pending promises may still be observed:

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

## Middleware

Middleware augments the functionality of handlers by invoking them in the process of generating responses. Middleware is implemented as a higher order function that takes the following form.

```php
use Psr\Http\Message\RequestInterface;

function my_middleware()
{
    return function (callable $handler) {
        return function (RequestInterface $request, array $options) use ($handler) {
            return $handler($request, $options);
        };
    };
}
```

Middleware functions return a function that accepts the next handler to invoke. This returned function then returns another function that acts as a composed handler-- it accepts a request and options, and returns a promise that is fulfilled with a response. Your composed middleware can modify the request, add custom request options, and modify the promise returned by the downstream handler.

Here's an example of adding a header to each request.

```php
use Psr\Http\Message\RequestInterface;

function add_header($header, $value)
{
    return function (callable $handler) use ($header, $value) {
        return function (
            RequestInterface $request,
            array $options
        ) use ($handler, $header, $value) {
            $request = $request->withHeader($header, $value);
            return $handler($request, $options);
        };
    };
}
```

Once a middleware has been created, you can add it to a client by either wrapping the handler used by the client or by decorating a handler stack.

```php
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Client;

$stack = new HandlerStack();
$stack->setHandler(new CurlHandler());
$stack->push(add_header('X-Foo', 'bar'));
$client = new Client(['handler' => $stack]);
```

Now when you send a request, the client will use a handler composed with your added middleware, adding a header to each request.

Here's an example of creating a middleware that modifies the response of the downstream handler. This example adds a header to the response.

```php
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Client;

function add_response_header($header, $value)
{
    return function (callable $handler) use ($header, $value) {
        return function (
            RequestInterface $request,
            array $options
        ) use ($handler, $header, $value) {
            $promise = $handler($request, $options);
            return $promise->then(
                function (ResponseInterface $response) use ($header, $value) {
                    return $response->withHeader($header, $value);
                }
            );
        };
    };
}

$stack = new HandlerStack();
$stack->setHandler(new CurlHandler());
$stack->push(add_response_header('X-Foo', 'bar'));
$client = new Client(['handler' => $stack]);
```

Creating a middleware that modifies a request is made much simpler using the `GuzzleHttp\Middleware::mapRequest()` middleware. This middleware accepts a function that takes the request argument and returns the request to send.

```php
use Psr\Http\Message\RequestInterface;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Client;
use GuzzleHttp\Middleware;

$stack = new HandlerStack();
$stack->setHandler(new CurlHandler());

$stack->push(Middleware::mapRequest(function (RequestInterface $request) {
    return $request->withHeader('X-Foo', 'bar');
}));

$client = new Client(['handler' => $stack]);
```

Modifying a response is also much simpler using the `GuzzleHttp\Middleware::mapResponse()` middleware.

```php
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Client;
use GuzzleHttp\Middleware;

$stack = new HandlerStack();
$stack->setHandler(new CurlHandler());

$stack->push(Middleware::mapResponse(function (ResponseInterface $response) {
    return $response->withHeader('X-Foo', 'bar');
}));

$client = new Client(['handler' => $stack]);
```

### Logging Middleware

`GuzzleHttp\Middleware::log()` logs requests, responses, and errors using a `GuzzleHttp\MessageFormatterInterface` implementation. `GuzzleHttp\MessageFormatter` formats exactly the values requested by its template. Templates that include full messages, headers, bodies, URIs, URLs, or dynamic header placeholders can include sensitive data such as credentials, cookies, tokens, or request bodies. Avoid using debug or full-message templates in production unless logs are protected, or provide a custom formatter or logger processor that redacts sensitive data before logs are written.

## Retry Middleware

Use `GuzzleHttp\Middleware::retry()` to retry requests when a custom decider
returns `true`. The decider receives the current retry count, the request, the
response for fulfilled responses, and the rejection reason for failed transfers.
A rejection reason may itself expose a response, for example when it is a
`ResponseException`. A conservative retry strategy should retry only connection
establishment errors and too many requests errors.

```php
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

$stack = HandlerStack::create();

$stack->push(Middleware::retry(
    function (
        int $retries,
        RequestInterface $request,
        ?ResponseInterface $response = null,
        $reason = null
    ): bool {
        if ($retries >= 3) {
            return false;
        }

        if ($reason instanceof ConnectException) {
            return true;
        }

        return $response !== null && $response->getStatusCode() === 429;
    },
    function (
        int $retries,
        ?ResponseInterface $response,
        RequestInterface $request
    ): int {
        return 1000 * $retries;
    }
));

$client = new Client(['handler' => $stack]);
$response = $client->request('GET', 'https://example.com');
```

The retry middleware keeps track of the current retry count in the `retries`
request option. The option is initialized to `0` before the first attempt and
incremented before each retry. You can read this option in custom middleware or
seed it on a per-request basis:

```php
$response = $client->request('GET', 'https://example.com', [
    'retries' => 1,
]);
```

The `retries` option must be an integer. If you do not provide a delay
callback, the middleware uses an exponential backoff delay and writes the
computed wait time in milliseconds to the `delay` request option before
invoking the next attempt. Delay callbacks must return an integer number of
milliseconds.

## HandlerStack

A handler stack represents a stack of middleware to apply to a base handler function. You can push middleware to the stack to add to the top of the stack, and unshift middleware onto the stack to add to the bottom of the stack. When the stack is resolved, the handler is pushed onto the stack. Each value is then popped off of the stack, wrapping the previous value popped off of the stack.

```php
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Utils;
use Psr\Http\Message\RequestInterface;

$stack = new HandlerStack();
$stack->setHandler(Utils::chooseHandler());

$stack->push(Middleware::mapRequest(function (RequestInterface $r) {
    echo 'A';
    return $r;
}));

$stack->push(Middleware::mapRequest(function (RequestInterface $r) {
    echo 'B';
    return $r;
}));

$stack->push(Middleware::mapRequest(function (RequestInterface $r) {
    echo 'C';
    return $r;
}));

$client->request('GET', 'http://httpbin.org/');
// echoes 'ABC';

$stack->unshift(Middleware::mapRequest(function (RequestInterface $r) {
    echo '0';
    return $r;
}));

$client = new Client(['handler' => $stack]);
$client->request('GET', 'http://httpbin.org/');
// echoes '0ABC';
```

You can give middleware a name, which allows you to add middleware before other named middleware, after other named middleware, or remove middleware by name.

```php
use Psr\Http\Message\RequestInterface;
use GuzzleHttp\Middleware;

// Add a middleware with a name
$stack->push(Middleware::mapRequest(function (RequestInterface $r) {
    return $r->withHeader('X-Foo', 'Bar');
}, 'add_foo'));

// Add a middleware before a named middleware (unshift before).
$stack->before('add_foo', Middleware::mapRequest(function (RequestInterface $r) {
    return $r->withHeader('X-Baz', 'Qux');
}, 'add_baz'));

// Add a middleware after a named middleware (pushed after).
$stack->after('add_baz', Middleware::mapRequest(function (RequestInterface $r) {
    return $r->withHeader('X-Lorem', 'Ipsum');
}));

// Remove a middleware by name
$stack->remove('add_foo');
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
cURL share handles to share DNS and SSL session cache state. If sharing cannot
be configured, or if the selected handler does not support sharing, Guzzle
continues without sharing.

`TransportSharing::HANDLER_REQUIRE` requires handler-lifetime transport
sharing. Guzzle fails when it cannot select a cURL handler with cURL share
support, when sharing cannot be configured, or when a request is routed to a
handler that does not support sharing.

`TransportSharing::PERSISTENT_PREFER` asks Guzzle to use the strongest sharing
available in the current environment. Guzzle first tries persistent cURL share
handles, which can share DNS, connection, and SSL session cache state across
handler lifetimes. If persistent sharing is unavailable or cannot be created,
Guzzle falls back to `TransportSharing::HANDLER_PREFER`. If handler-lifetime
sharing is also unavailable, Guzzle continues without sharing.

`TransportSharing::PERSISTENT_REQUIRE` requires PHP persistent cURL share
handles and does not fall back to handler-lifetime sharing. If persistent
sharing is unavailable or cannot be created, Guzzle fails while creating the
handler.

Because `TransportSharing::PERSISTENT_REQUIRE` requires connection cache
sharing, Guzzle rejects request-level cURL options or proxy tunnel cases that
require a fresh connection for safety.

Transport sharing does not share cookies. Cookies are managed by Guzzle
middleware.

When constructing cURL handlers manually, configure sharing with the handler
`transport_sharing` option:

```php
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\TransportSharing;

$handler = new CurlHandler([
    'transport_sharing' => TransportSharing::HANDLER_REQUIRE,
]);
```

## Creating a Handler

As stated earlier, a handler is a function that accepts a `Psr\Http\Message\RequestInterface` and an array of request options. A handler used with Guzzle middleware returns a `GuzzleHttp\Promise\PromiseInterface<Psr\Http\Message\ResponseInterface, mixed>` that is fulfilled with a `Psr\Http\Message\ResponseInterface` or rejected with a reason.

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

Most custom handlers should be wrapped with `GuzzleHttp\HandlerStack::create($handler)`. This keeps Guzzle's default middleware behavior for redirects, cookies, HTTP errors, and request body preparation while still allowing the custom handler to own the underlying transport.

Synchronous client methods set `GuzzleHttp\RequestOptions::SYNCHRONOUS` before invoking the handler and then call `wait()` on the returned promise. The `synchronous` option is a hint that the caller intends to wait, not permission for a handler to return a response directly. A handler promise's `wait()` method must complete the transfer or throw the rejection reason. Asynchronous client methods return the promise without blocking.

### Request Option Ownership

Request options are applied by different parts of Guzzle. A custom handler should know which options are already reflected in the request it receives and which options still need transport support.

| Owner | Examples | Notes |
| --- | --- | --- |
| Client-applied options | `base_uri`, `headers`, `body`, `form_params`, `multipart`, `json`, `query`, `version`, `idn_conversion`, basic `auth` | These options affect request construction or request mutation before the final handler sends the request. |
| Middleware-dependent options | `allow_redirects`, `cookies`, `http_errors`, `expect` | These options require the relevant middleware, normally from `HandlerStack::create()`. |
| Handler-owned options | `delay`, `timeout`, `connect_timeout`, `read_timeout`, `stream`, `sink`, `verify`, `cert`, `ssl_key`, `proxy`, `force_ip_resolve`, `decode_content`, `progress`, `on_headers`, `on_stats`, `debug` | These options describe transport behavior and need explicit handler support or clear unsupported behavior. |

Some options have split responsibilities. Basic `auth` adds an `Authorization` header before the handler runs, while digest and NTLM authentication are implemented through cURL options by Guzzle's built-in cURL handlers. The `expect` option is used by the body preparation middleware to add `Expect: 100-Continue`, but the transport still determines whether the protocol workflow is supported. The `decode_content` option can affect the `Accept-Encoding` request header, but response decoding is handled by the transport. Redirect middleware validates redirect targets with `allow_redirects.protocols`, but the handler is still responsible for enforcing which schemes it can send.

### Handler-Owned Transfer Options

A handler is responsible for applying the following request options. These request options are a subset of request options called "transfer options".

- [`cert`](request-options.md#cert)
- [`cert_type`](request-options.md#cert_type)
- [`connect_timeout`](request-options.md#connect_timeout)
- [`crypto_method`](request-options.md#crypto_method)
- [`debug`](request-options.md#debug)
- [`delay`](request-options.md#delay)
- [`decode_content`](request-options.md#decode_content)
- [`expect`](request-options.md#expect)
- [`force_ip_resolve`](request-options.md#force_ip_resolve)
- [`on_headers`](request-options.md#on_headers)
- [`on_stats`](request-options.md#on_stats)
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

Transport-specific options such as `curl` and `stream_context` are intended for the handlers that understand them. A non-cURL handler should reject or document how it treats cURL-specific options, and a non-stream handler should do the same for PHP stream context options.

Handlers that support the `proxy` option should use `GuzzleHttp\ProxyOptions::resolve()` unless they intentionally document different proxy selection semantics. The returned `GuzzleHttp\ProxySelection` identifies the selected proxy string, no-proxy bypasses, and explicit proxy-disable cases. The handler is still responsible for applying that selection to its transport.

First-party handlers should not silently ignore documented handler-owned options that users reasonably expect to affect transport behavior. They should implement the option, reject the request with a clear exception when the option is present, or document a deliberate no-op where the option has no meaningful transport equivalent. Custom handlers should follow the same pattern where practical.

### Callback Semantics

The `on_headers` option is invoked after the final response headers, or a `101 Switching Protocols` response, have been received and before response body bytes are written to the configured `sink`. Built-in handlers do not invoke it for other informational `1xx` responses. The callback receives the response object and the corresponding request object. If it throws, the request promise is rejected with a `GuzzleHttp\Exception\ResponseException` that wraps the thrown exception.

The `on_stats` option is invoked when the handler has finished sending a request, with a `GuzzleHttp\TransferStats` object that describes the response received or the error encountered. Exceptions thrown by `on_stats` are not wrapped by Guzzle and may escape from the handler wait path. Built-in cURL handlers release native cURL handles before invoking `on_stats` and may invoke it per low-level transfer attempt.

The `progress` option is invoked with the documented argument order: the total number of bytes expected to be downloaded, the number of bytes downloaded so far, the total number of bytes expected to be uploaded, and the number of bytes uploaded so far. With the built-in cURL handlers, returning a truthy value aborts the transfer and rejects the request promise with a `GuzzleHttp\Exception\ResponseException` when a response is available, or a `GuzzleHttp\Exception\RequestException` otherwise. If the callback throws, the cURL handlers reject the promise with the same response-aware classification while wrapping the thrown exception. The built-in stream handler treats progress callbacks as notifications and ignores return values. A handler that cannot provide progress information should reject the option or clearly document that progress reporting is unsupported.

### Promise Queue Integration

Guzzle promises settle callbacks through the Guzzle promise task queue. The queue is drained automatically during synchronous `wait()`, but it is not automatically driven by arbitrary event loops. A handler that resolves or rejects Guzzle promises from an external scheduler must ensure the Guzzle promise task queue is drained while that scheduler is running.

After resolving or rejecting a Guzzle promise from an external scheduler, schedule `GuzzleHttp\Promise\Utils::queue()->run()` on that scheduler soon, without blocking the scheduler.

```php
use GuzzleHttp\Promise\Utils as PromiseUtils;

// Pseudocode for an external scheduler callback.
$promise->resolve($response);

ExternalLoop::queue(static function (): void {
    PromiseUtils::queue()->run();
});
```

Do not rely only on `wait()` to drain the queue because asynchronous users may attach callbacks and expect them to run while their event loop is active. Avoid running the queue in a tight polling loop, and avoid replacing the global task queue unless your library fully owns the process runtime. External futures or promises should generally be adapted by intentionally settling a Guzzle promise, because `then()`, `wait()`, and `cancel()` semantics often differ between promise implementations.
