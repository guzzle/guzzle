# Guzzle and PSR-7

This page covers how Guzzle integrates PSR-7 requests, responses, streams, and
URIs while sending HTTP requests. Generic PSR-7 message, URI, stream, and helper
documentation lives in
[`guzzlehttp/psr7`](https://github.com/guzzle/psr7/blob/3.1/README.md).

## Sending PSR-7 Requests

You can create a PSR-7 request and send it with `Client::send()`.

```php
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;

$client = new Client();
$request = new Request('GET', 'https://example.com/api');
$response = $client->send($request);
```

Guzzle also provides named shortcut methods for common HTTP methods, such as
`get()`, `post()`, `put()`, `patch()`, `delete()`, and `head()`. See the
[Quick Start](quick-start.md#sending-requests) for request examples.

## Method Casing

HTTP method names are case-sensitive. Guzzle sends the method string exactly as
provided by the request. Use uppercase standard methods such as `GET`, `POST`,
and `HEAD` when you want standard method-specific behavior from Guzzle's
middleware and handlers.

## Streams

Guzzle uses PSR-7 stream objects to represent request and response bodies. For
general stream creation, metadata, decorators, and PHP stream resource
integration, see the
[PSR-7 stream documentation](https://github.com/guzzle/psr7/blob/3.1/docs/streams-and-decorators.md).

For response bodies, the built-in stream handler creates or wraps the
sink/default stream with the configured
[`stream_factory`](request-options.md#stream_factory) where practical. When
response content decoding is enabled, the stream handler may layer Guzzle's
`InflateStream` over that stream because PSR-17 cannot express a decoding
stream.

The built-in cURL handlers use libcurl for content decoding when it is enabled.
libcurl writes the decoded bytes to the configured sink or default response body
stream.

## PSR-17 Factories

Guzzle uses `GuzzleHttp\Psr7\HttpFactory` by default. As an advanced feature,
applications that need a different PSR-7 implementation can supply their own
PSR-17 factories through the
[`request_factory`](request-options.md#request_factory),
[`response_factory`](request-options.md#response_factory),
[`uri_factory`](request-options.md#uri_factory), and
[`stream_factory`](request-options.md#stream_factory) request options. Guzzle
only checks that each value implements the relevant interface, not that the
objects it returns behave correctly, so an implementation that does not honor
the contracts Guzzle relies on can introduce bugs or security issues. See each
option's notes in [Request Options](request-options.md) for the specifics.

When Guzzle creates request body streams from supported `body`, `form_params`,
or `json` option values, [`stream_factory`](request-options.md#stream_factory)
can replace the default PSR-17 stream factory. Streams supplied directly as
`Psr\Http\Message\StreamInterface` instances are used as provided, while
callable and iterator bodies use Guzzle's existing stream handling.

The built-in handlers also create response body streams with the configured
[`stream_factory`](request-options.md#stream_factory) where practical. Because
handlers read response bodies through those streams, a custom factory's
`createStreamFromResource()` must return a stream backed by the supplied
resource that exposes live metadata such as `timed_out` and closes the resource
when the stream is closed. Caller-owned resource sinks keep Guzzle's own stream
wrapper so write-only sink resources remain supported and closing the response
body detaches without closing the caller's resource. File-path sinks keep using
`GuzzleHttp\Psr7\LazyOpenStream` so the file is not opened until first use,
which PSR-17's `createStreamFromFile()` cannot express. The response message
itself is created with the
[`response_factory`](request-options.md#response_factory) request option.

## Related

- [Quick Start](quick-start.md)
- [Request Options](request-options.md)
- [PSR-7 stream documentation](https://github.com/guzzle/psr7/blob/3.1/docs/streams-and-decorators.md)
