# Middleware

A Guzzle client wraps the [handler](handlers.md) that sends requests in a stack
of middleware. This page covers the built-in middleware, how to write your own,
and the `HandlerStack` that composes them.

## Middleware

Middleware augments the functionality of handlers by invoking them in the
process of generating responses. Middleware is implemented as a higher order
function that takes the following form.

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

Middleware functions return a function that accepts the next handler to invoke.
This returned function then returns another function that acts as a composed
handler-- it accepts a request and options, and returns a promise that is
fulfilled with a response. Your composed middleware can modify the request, add
custom request options, and modify the promise returned by the downstream
handler.

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

Once a middleware has been created, you can add it to a client by either
wrapping the handler used by the client or by decorating a handler stack.

```php
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Client;

$stack = new HandlerStack();
$stack->setHandler(new CurlHandler());
$stack->push(add_header('X-Foo', 'bar'));
$client = new Client(['handler' => $stack]);
```

Now when you send a request, the client will use a handler composed with your
added middleware, adding a header to each request.

Here's an example of creating a middleware that modifies the response of the
downstream handler. This example adds a header to the response.

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

Creating a middleware that modifies a request is made much simpler using the
`GuzzleHttp\Middleware::mapRequest()` middleware. This middleware accepts a
function that takes the request argument and returns the request to send.

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

Modifying a response is also much simpler using the
`GuzzleHttp\Middleware::mapResponse()` middleware.

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

### Tap Middleware

Use `GuzzleHttp\Middleware::tap()` to observe requests and responses as they
flow through the stack without modifying them. This is useful for metrics,
tracing, and debugging.

`tap()` accepts two optional callables:

- `$before` is invoked as `$before($request, $options)` immediately before the
  request is forwarded to the next handler.
- `$after` is invoked as `$after($request, $options, $promise)` immediately
  after the request is forwarded. It receives the response *promise* rather than
  a response, because the transfer may still be in progress.

The return values of both callables are ignored. Tap middleware cannot change
the request, the options, or the response; attach a `then()` callback to the
promise in `$after` if you need to read the settled response.

```php
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

$stack = HandlerStack::create();

$stack->push(Middleware::tap(
    function (RequestInterface $request, array $options) {
        echo 'Sending ' . $request->getMethod() . ' ' . $request->getUri() . "\n";
    },
    function (RequestInterface $request, array $options, PromiseInterface $promise) {
        $promise->then(function (ResponseInterface $response) {
            echo 'Received ' . $response->getStatusCode() . "\n";
        });
    }
));

$client = new Client(['handler' => $stack]);
$client->request('GET', 'https://example.com');
```

### Logging Middleware

`GuzzleHttp\Middleware::log()` logs requests, responses, and errors using a
`GuzzleHttp\MessageFormatterInterface` implementation.

```php
public static function log(
    Psr\Log\LoggerInterface $logger,
    GuzzleHttp\MessageFormatterInterface $formatter,
    string $logLevel = 'info'
): callable
```

`$logger` is any PSR-3 logger. Successful responses are logged at `$logLevel`
(`info` by default); failed transfers are always logged at the `error` level,
regardless of `$logLevel`.

```php
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\MessageFormatter;
use GuzzleHttp\Middleware;

// $logger is any Psr\Log\LoggerInterface implementation.
$stack = HandlerStack::create();
$stack->push(Middleware::log($logger, new MessageFormatter(MessageFormatter::DEBUG), 'debug'));

$client = new Client(['handler' => $stack]);
$client->request('GET', 'https://example.com');
```

`GuzzleHttp\MessageFormatter` builds each log line from a template string,
substituting `{placeholder}` tokens with values from the request, response, and
any error. It ships with three preset templates:

- `MessageFormatter::CLF` — the
  [Apache Common Log Format](https://httpd.apache.org/docs/2.4/logs.html#common).
  This is the default when no template is passed to the constructor.
- `MessageFormatter::DEBUG` — a full dump of the request, the response, and any
  error.
- `MessageFormatter::SHORT` — a short, single-line summary.

```php
// Use a preset...
$formatter = new MessageFormatter(MessageFormatter::SHORT);
// ...or supply a custom template.
$formatter = new MessageFormatter('{method} {uri} {code}');
```

The following placeholders are supported:

| Placeholder | Description |
| --- | --- |
| `{request}` | Full HTTP request message |
| `{response}` | Full HTTP response message |
| `{req_headers}` | Request start line and headers |
| `{res_headers}` | Response start line and headers |
| `{req_body}` | Request body |
| `{res_body}` | Response body |
| `{method}` | Method of the request |
| `{uri}` / `{url}` | URI of the request |
| `{target}` | Request target (path, query, and fragment) |
| `{host}` | `Host` header of the request |
| `{hostname}` | Hostname of the machine that sent the request |
| `{version}` / `{req_version}` | Protocol version of the request |
| `{res_version}` | Protocol version of the response |
| `{code}` | Status code of the response |
| `{phrase}` | Reason phrase of the response |
| `{error}` | Error message, if any |
| `{ts}` / `{date_iso_8601}` | ISO 8601 date in GMT |
| `{date_common_log}` | Apache Common Log date in the configured timezone |
| `{req_header_*}` | A single request header; replace `*` with the header name |
| `{res_header_*}` | A single response header; replace `*` with the header name |

> [!WARNING]
> Templates that include full messages, headers, bodies, URIs, URLs, or dynamic
> header placeholders can include sensitive data such as credentials, cookies,
> tokens, or request bodies. Avoid using debug or full-message templates in
> production unless logs are protected, or provide a custom formatter or logger
> processor that redacts sensitive data before logs are written.

### Customizing Error Messages

When the `http_errors` request option is enabled, the `http_errors` middleware
throws a `GuzzleHttp\Exception\ClientException` for `4xx` responses and a
`GuzzleHttp\Exception\ServerException` for `5xx` responses, including a short
summary of the response body in the exception message.
`GuzzleHttp\Middleware::httpErrors()` accepts an optional
`GuzzleHttp\BodySummarizerInterface` that controls how that body is summarized.

The bundled `GuzzleHttp\BodySummarizer` takes an optional byte limit. Pass an
integer to cap how much of the response body appears in exception messages, or
implement `BodySummarizerInterface` for full control. This is useful for keeping
large or binary response bodies out of exception messages and logs.

The default handler stack registers this middleware under the name
`http_errors`, pushed first so that it is the outermost middleware. Remove it
and unshift a replacement to keep that position while changing the summarizer:

```php
use GuzzleHttp\BodySummarizer;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;

$stack = HandlerStack::create();
$stack->remove('http_errors');
$stack->unshift(Middleware::httpErrors(new BodySummarizer(500)), 'http_errors');

$client = new Client(['handler' => $stack]);
```

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

A handler stack represents a stack of middleware to apply to a base handler
function. You can push middleware to the stack to add to the top of the stack,
and unshift middleware onto the stack to add to the bottom of the stack. When
the stack is resolved, the handler is pushed onto the stack. Each value is then
popped off of the stack, wrapping the previous value popped off of the stack.

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

You can give middleware a name, which allows you to add middleware before other
named middleware, after other named middleware, or remove middleware by name.

```php
use Psr\Http\Message\RequestInterface;
use GuzzleHttp\Middleware;

// Add a middleware with a name
$stack->push(Middleware::mapRequest(function (RequestInterface $r) {
    return $r->withHeader('X-Foo', 'Bar');
}), 'add_foo');

// Add a middleware before a named middleware (unshift before).
$stack->before('add_foo', Middleware::mapRequest(function (RequestInterface $r) {
    return $r->withHeader('X-Baz', 'Qux');
}), 'add_baz');

// Add a middleware after a named middleware (pushed after).
$stack->after('add_baz', Middleware::mapRequest(function (RequestInterface $r) {
    return $r->withHeader('X-Lorem', 'Ipsum');
}), 'add_lorem');

// Remove a middleware by name
$stack->remove('add_foo');
```

## Related

- [Handlers](handlers.md)
- [Request Options](request-options.md)
- [Testing Guzzle Clients](testing-guzzle-clients.md)
