# Package Roadmap

Guzzle is a small ecosystem of packages. Start with `guzzlehttp/guzzle` if you want to send HTTP requests. Install the other packages directly only when you need the lower-level or specialized behavior they provide.

## Core HTTP Client Stack

| Package | Use it for | Documentation |
|---------|------------|---------------|
| `guzzlehttp/guzzle` | Sending synchronous and asynchronous HTTP requests. | [Guzzle docs](index.md) |
| `guzzlehttp/psr7` | Creating and manipulating PSR-7 requests, responses, streams, and URIs. | [PSR-7 docs](https://github.com/guzzle/psr7/blob/3.0/docs/index.md) |
| `guzzlehttp/promises` | Working directly with promises returned by asynchronous operations. | [Promises docs](https://github.com/guzzle/promises/blob/3.0/docs/index.md) |

Install `guzzlehttp/guzzle` for normal application HTTP work. It already depends on `guzzlehttp/psr7` and `guzzlehttp/promises`, so you do not need to install them separately unless your application uses them directly.

Use `guzzlehttp/psr7` directly when you need message, stream, or URI objects without sending requests. Use `guzzlehttp/promises` directly when you need promise composition outside of Guzzle's HTTP client APIs.

## Service Client Packages

| Package | Use it for | Documentation |
|---------|------------|---------------|
| `guzzlehttp/command` | Building command-based SDK-style clients with named operations and results. | [Command docs](https://github.com/guzzle/command/blob/2.0/docs/index.md) |
| `guzzlehttp/guzzle-services` | Building service-description-driven clients on top of Guzzle Command. | [Guzzle Services docs](https://github.com/guzzle/guzzle-services/blob/2.0/docs/index.md) |

Use `guzzlehttp/command` when you want a higher-level client API where application code calls operations rather than manually building HTTP requests. Use `guzzlehttp/guzzle-services` when those operations should be described by service description arrays that define request serialization and response models.

## Utilities And Add-Ons

| Package | Use it for | Documentation |
|---------|------------|---------------|
| `guzzlehttp/uri-template` | Expanding RFC 6570 URI templates. | [URI Template docs](https://github.com/guzzle/uri-template/blob/2.0/docs/index.md) |
| `guzzlehttp/oauth-subscriber` | Signing Guzzle requests with OAuth 1.0 middleware. | [OAuth Subscriber docs](https://github.com/guzzle/oauth-subscriber/blob/1.0/docs/index.md) |
| `guzzlehttp/test-server` | Testing HTTP clients against a local controllable server. | [Test Server docs](https://github.com/guzzle/test-server/blob/1.0/docs/index.md) |

Use `guzzlehttp/uri-template` when an API provides URI templates that need to be expanded before sending requests. Use `guzzlehttp/oauth-subscriber` only for OAuth 1.0 request signing; OAuth 2.0 bearer tokens usually only need an `Authorization` header. Use `guzzlehttp/test-server` as a development dependency for integration tests, not as production infrastructure.
