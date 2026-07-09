# Exceptions

When a transfer fails, first ask whether Guzzle has a response object yet. The
answer determines which branch below to catch: `NetworkException` for
no-response network failures, `ResponseException` for failures with a response,
and `RequestException` for other request failures. Transfer-level failures after
response headers are received use `ResponseTransferException`. Use
`TransferException` or `GuzzleException` only when one catch block should handle
every Guzzle transfer failure.

```
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

If a network problem prevents Guzzle from receiving a response, it throws
`NetworkException`. This covers transport failures while opening the connection
or moving bytes over the network. When the handler can determine a more specific
failure, Guzzle uses a more specific subtype such as `ConnectException`,
`ConnectTimeoutException`, or `NetworkTimeoutException`.

If Guzzle has parsed response headers into a response object, later failures use
`ResponseException`. This is the only branch that exposes `getResponse()`.
Transfer failures after headers use `ResponseTransferException`, with response
timeouts as `ResponseTimeoutException`. Local response failures, such as a sink
write, failed sink rewind, or response length that cannot be represented on the
current platform, stay plain `ResponseException`. With Guzzle request methods,
middleware can also turn completed responses into exceptions: `http_errors`
turns 4xx responses into `ClientException` and 5xx responses into
`ServerException`, and redirect middleware can throw
`TooManyRedirectsException`. `Client::sendRequest()` follows PSR-18 and returns
redirect, 4xx, and 5xx responses normally instead.

The [Timeout phases](request-options.md#timeout-phases) table shows which
exception each timeout produces at each phase of a request sent by the built-in
handlers.

All `TransferException` instances expose `getRequest()`. If a non-network
request failure occurs before Guzzle has a response object, it throws
`RequestException`. This includes invalid or handler-unsupported HTTP protocol
versions, malformed response data that cannot be parsed into a PSR-7 response,
and no-response transfers aborted by application code. `RequestException` does
not expose `getResponse()`. When handling these cases separately, catch
no-response transport failures first, response-aware failures second, and other
request failures last.

```php
use GuzzleHttp\Exception\NetworkException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\Exception\ResponseTransferException;
use GuzzleHttp\Psr7\Message;

try {
    $client->request('GET', 'https://github.com/_abc_123_404');
} catch (NetworkException $e) {
    echo Message::toString($e->getRequest());
} catch (ResponseTransferException $e) {
    echo Message::toString($e->getRequest());
    echo Message::toString($e->getResponse());
} catch (ResponseException $e) {
    echo Message::toString($e->getRequest());
    echo Message::toString($e->getResponse());
} catch (RequestException $e) {
    echo Message::toString($e->getRequest());
}
```

`HandlerClosedException` sits outside the request/response lifecycle. It is used
when a built-in handler rejects a transfer because the handler was explicitly
closed before the transfer completed. For example, pending `CurlMultiHandler`
transfers are rejected with this exception when `CurlMultiHandler::close()` is
called.

## Related

- [Quick Start](quick-start.md)
- [Request Options](request-options.md)
- [Exception guidelines (contributor reference)](contributing/exception-guidelines.md)
