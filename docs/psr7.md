# Guzzle and PSR-7

Guzzle uses PSR-7 interfaces for HTTP requests, responses, streams, and URIs. This lets Guzzle work with libraries that also use `psr/http-message`, and it lets applications send their own PSR-7 request objects through a Guzzle client.

Guzzle's default PSR-7 implementation is provided by [`guzzlehttp/psr7`](https://github.com/guzzle/psr7). Generic PSR-7 message, URI, stream, and helper documentation lives in that package:

- [PSR-7 Messages](https://github.com/guzzle/psr7/blob/3.0/docs/messages.md)
- [Streams and Decorators](https://github.com/guzzle/psr7/blob/3.0/docs/streams.md)
- [Static API Helpers](https://github.com/guzzle/psr7/blob/3.0/docs/static-api.md)
- [URI Helpers](https://github.com/guzzle/psr7/blob/3.0/docs/uri.md)

This page focuses on how PSR-7 objects are used by Guzzle itself.

## Sending PSR-7 Requests

You can create a PSR-7 request and send it with `Client::send()`.

```php
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;

$client = new Client();
$request = new Request('GET', 'https://example.com/api');
$response = $client->send($request);
```

Guzzle also provides named shortcut methods for common HTTP methods, such as `get()`, `post()`, `put()`, `patch()`, `delete()`, and `head()`. See the [Quick Start](quickstart.md#sending-requests) for request examples.

## Method Casing

HTTP method names are case-sensitive. Guzzle sends the method string exactly as
provided by the request. Use uppercase standard methods such as `GET`, `POST`,
and `HEAD` when you want standard method-specific behavior from Guzzle's
middleware and handlers.

## Streams

Guzzle uses PSR-7 stream objects to represent request and response bodies. For general stream creation, metadata, decorators, and PHP stream resource integration, see the [PSR-7 stream documentation](https://github.com/guzzle/psr7/blob/3.0/docs/streams.md).

## PSR-17 Factories

Guzzle uses `GuzzleHttp\Psr7\HttpFactory` by default. As an advanced feature, applications that need a different PSR-7 implementation can supply their own PSR-17 factories through the `request_factory`, `response_factory`, `uri_factory`, and `stream_factory` request options. Guzzle only checks that each value implements the relevant interface, not that the objects it returns behave correctly, so an implementation that does not honor the contracts Guzzle relies on can introduce bugs or security issues. See each option's notes in [Request Options](request-options.md) for the specifics.

When Guzzle creates request body streams from supported `body`, `form_params`, or `json` option values, the `stream_factory` request option can replace the default PSR-17 stream factory. Streams supplied directly as `Psr\Http\Message\StreamInterface` instances are used as provided, while callable and iterator bodies use Guzzle's existing stream handling.

The built-in cURL and stream handlers also create response body streams with the configured `stream_factory` where practical: the underlying transport resource (and the default `php://temp` sink) is wrapped via `createStreamFromResource()`, while Guzzle's own decorators (`InflateStream` for content decoding, `FnStream` for caller-owned sinks) are layered on top. Content decoding wraps the same factory-created stream, since PSR-17 cannot express a decoding stream. Because the handlers read response bodies through that stream — including read-timeout detection, which inspects the underlying resource's live `timed_out` metadata — a custom factory's `createStreamFromResource()` must return a stream backed by the supplied resource that exposes its live metadata and closes the resource when the stream is closed. Caller-owned resource sinks keep Guzzle's own stream wrapper so write-only sink resources remain supported and closing the response body detaches without closing the caller's resource. File-path sinks keep using `GuzzleHttp\Psr7\LazyOpenStream` so the file is not opened until first use, which PSR-17's `createStreamFromFile()` cannot express. The response message itself is created with the `response_factory` request option.
