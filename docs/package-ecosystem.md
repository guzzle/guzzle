# Package Ecosystem

This page helps you choose between `guzzlehttp/guzzle` and related packages
maintained by the Guzzle organization. Start with `guzzlehttp/guzzle` if you
want to send HTTP requests; install another package directly only when your
application needs that package's lower-level or specialized behavior.

## Core HTTP Client Stack

| Package | Use it for | Documentation |
|---------|------------|---------------|
| `guzzlehttp/guzzle` | Sending synchronous and asynchronous HTTP requests. | [Quick Start](quick-start.md) |
| `guzzlehttp/psr7` | Creating and manipulating PSR-7 requests, responses, streams, and URIs. | [PSR-7 docs](https://github.com/guzzle/psr7/blob/3.0/docs/psr-7-messages.md) |
| `guzzlehttp/promises` | Working directly with promises returned by asynchronous operations. | [Promises docs](https://github.com/guzzle/promises/blob/3.0/docs/promise-quick-start.md) |

Install `guzzlehttp/guzzle` for normal application HTTP work. It already depends
on `guzzlehttp/psr7` and `guzzlehttp/promises`, so you do not need to install
them separately unless your application uses them directly.

Use `guzzlehttp/psr7` directly when you need message, stream, or URI objects
without sending requests. Use `guzzlehttp/promises` directly when you need
promise composition outside of Guzzle's HTTP client APIs.

## Service Client Packages

| Package | Use it for | Documentation |
|---------|------------|---------------|
| `guzzlehttp/command` | Building command-based SDK-style clients with named operations and results. | [Command docs](https://github.com/guzzle/command/blob/2.0/docs/service-clients.md) |
| `guzzlehttp/guzzle-services` | Building service-description-driven clients on top of Guzzle Command. | [Guzzle Services docs](https://github.com/guzzle/guzzle-services/blob/2.0/docs/service-descriptions.md) |

Use `guzzlehttp/command` when you want a higher-level client API where
application code calls operations rather than manually building HTTP requests.
Use `guzzlehttp/guzzle-services` when those operations should be described by
service description arrays that define request serialization and response
models.

## Utilities and Add-Ons

| Package | Use it for | Documentation |
|---------|------------|---------------|
| `guzzlehttp/uri-template` | Expanding RFC 6570 URI templates. | [URI Template docs](https://github.com/guzzle/uri-template/blob/2.0/docs/uri-template-usage.md) |
| `guzzlehttp/oauth-subscriber` | Signing Guzzle requests with OAuth 1.0 middleware. | [OAuth Subscriber docs](https://github.com/guzzle/oauth-subscriber/blob/1.0/docs/oauth-1-0-middleware-usage.md) |
| `guzzlehttp/test-server` | Testing HTTP clients against a local controllable server. | [Test Server docs](https://github.com/guzzle/test-server/blob/1.0/docs/test-server-usage.md) |

Use `guzzlehttp/uri-template` when an API provides URI templates that need to be
expanded before sending requests. Use `guzzlehttp/oauth-subscriber` only for
OAuth 1.0 request signing; OAuth 2.0 bearer tokens usually only need an
`Authorization` header. Use `guzzlehttp/test-server` as a development dependency
for integration tests, not as production infrastructure.

## Related

- [Quick Start](quick-start.md)
- [Guzzle and PSR-7](guzzle-and-psr-7.md)
- [Testing Guzzle Clients](testing-guzzle-clients.md)
