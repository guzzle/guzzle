# Testing Guzzle Clients

Guzzle provides several tools that will enable you to easily mock the HTTP layer without needing to send requests over the internet.

- Mock handler
- History middleware
- Node.js web server for integration testing

## Mock Handler

When testing HTTP clients, you often need to simulate specific scenarios like returning a successful response, returning an error, or returning specific responses in a certain order. Because unit tests need to be predictable, easy to bootstrap, and fast, hitting an actual remote API is a test smell.

Guzzle provides a mock handler that can be used to fulfill HTTP requests with queued responses, response promises, request-aware callables, or reject them with queued throwables by shifting return values off of a queue.

```php
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Exception\RequestException;

// Create a mock and queue two responses.
$mock = new MockHandler([
    new Response(200, ['X-Foo' => 'Bar'], 'Hello, World'),
    new Response(202, ['Content-Length' => '0']),
    new RequestException('Error Communicating with Server', new Request('GET', 'test'))
]);

$handlerStack = HandlerStack::create($mock);
$client = new Client(['handler' => $handlerStack]);

// The first request is intercepted with the first response.
$response = $client->request('GET', '/');
echo $response->getStatusCode();
//> 200
echo $response->getBody();
//> Hello, World
// The second request is intercepted with the second response.
echo $client->request('GET', '/')->getStatusCode();
//> 202

// Reset the queue and queue up a new response
$mock->reset();
$mock->append(new Response(201));

// As the mock was reset, the new response is the 201 CREATED,
// instead of the previously queued RequestException
echo $client->request('GET', '/')->getStatusCode();
//> 201
```

When no more responses are in the queue and a request is sent, an `OutOfBoundsException` is thrown.

Queued callables receive the `Psr\Http\Message\RequestInterface` and request options array passed to the mock handler. They may return a `Psr\Http\Message\ResponseInterface`, a `GuzzleHttp\Promise\PromiseInterface<Psr\Http\Message\ResponseInterface, mixed>`, or a throwable rejection reason.

The `on_headers` request option is invoked when a queued response or fulfilled response promise is available. It is not invoked for queued throwables or rejected promises.

The optional `MockHandler` constructor callbacks are invoked with the fulfilled response or rejected reason after the queued value has settled.

## History Middleware

When using things like the `Mock` handler, you often need to know if the requests you expected to send were sent exactly as you intended. While the mock handler responds with mocked responses, the history middleware maintains a history of the requests that were sent by a client.

```php
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;

$container = [];
$history = Middleware::history($container);

$handlerStack = HandlerStack::create();
// or $handlerStack = HandlerStack::create($mock); if using the Mock handler.

// Add the history middleware to the handler stack.
$handlerStack->push($history);

$client = new Client(['handler' => $handlerStack]);

$client->request('GET', 'http://httpbin.org/get');
$client->request('HEAD', 'http://httpbin.org/get');

// Count the number of transactions
echo count($container);
//> 2

// Iterate over the transaction history. Each transaction contains:
// - request: the request that was sent
// - response: the response, or null when the transfer was rejected
// - error: the rejection reason, or null when the transfer succeeded
// - options: the request options used for the transfer
foreach ($container as $transaction) {
    echo $transaction['request']->getMethod();
    //> GET, HEAD
    if ($transaction['response']) {
        echo $transaction['response']->getStatusCode();
        //> 200, 200
    } elseif ($transaction['error']) {
        echo $transaction['error'];
        //> exception
    }
    var_dump($transaction['options']);
    //> dumps the request options of the sent request.
}
```

## Test Web Server

Using mock responses is almost always enough when testing a web service client. When implementing custom [HTTP handlers](handlers-and-middleware.md), you'll need to send actual HTTP requests in order to sufficiently test the handler. However, a best practice is to contact a local web server rather than a server over the internet.

- Tests are more reliable
- Tests do not require a network connection
- Tests have no external dependencies

### Using the Test Server

> [!TIP]
> You almost never need to use this test web server. You should only ever consider using it when developing HTTP handlers. The test web server is not necessary for mocking requests. For that, please use the Mock handler and history middleware.

The test server is distributed separately as [`guzzlehttp/test-server`](https://github.com/guzzle/test-server/blob/1.0/docs/usage.md). It is not installed with Guzzle by default. The package provides a Node.js server that receives requests, returns responses from a queue, and records received requests for inspection.

See the [Test Server Usage](https://github.com/guzzle/test-server/blob/1.0/docs/usage.md) documentation for installation, lifecycle, response queueing, request inspection, and shutdown details.
