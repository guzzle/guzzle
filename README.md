![Guzzle](.github/logo.png?raw=true)

# Guzzle, PHP HTTP Client

Guzzle is a PHP HTTP client that makes it easy to send HTTP requests and
trivial to integrate with web services.

- Simple interface for building query strings, POST requests, streaming large
  uploads, streaming large downloads, using HTTP cookies, uploading JSON data,
  etc...
- Can send both synchronous and asynchronous requests using the same interface.
- Uses PSR-7 interfaces for requests, responses, and streams. This allows you
  to utilize other PSR-7 compatible libraries with Guzzle.
- Supports PSR-18, allowing interoperability with other PSR-18 HTTP clients.
- Abstracts away the underlying HTTP transport, allowing you to write
  environment and transport agnostic code; i.e., no hard dependency on cURL,
  PHP streams, sockets, or non-blocking event loops.
- Middleware system allows you to augment and compose client behavior.

## Installation

The recommended way to install Guzzle is through
[Composer](https://getcomposer.org/).

```bash
composer require guzzlehttp/guzzle
```

## Version Guidance

| Version | Status       | PHP Version  |
|---------|--------------|--------------|
| 8.0     | Latest       | >=7.4,<8.6   |
| 7.15    | Maintenance  | >=7.2.5,<8.6 |
| 6.5     | End of Life  | >=5.5,<8.0   |

## Quick Start

```php
$client = new \GuzzleHttp\Client();
$response = $client->request('GET', 'https://api.example.com/users/123');

echo $response->getStatusCode(); // 200
echo $response->getHeaderLine('content-type'); // 'application/json'
echo $response->getBody(); // '{"id": 123, "name": "Ada"}'
```

For more examples, see the [Quick Start](docs/quick-start.md).

## Documentation

- [Quick Start](docs/quick-start.md)
- [Overview](docs/overview.md)
- [Request Options](docs/request-options.md)
- [Uploading Data](docs/uploading-data.md)
- [Cookies](docs/cookies.md)
- [Exceptions](docs/exceptions.md)
- [Guzzle and PSR-7](docs/guzzle-and-psr-7.md)
- [Handlers](docs/handlers.md)
- [Middleware](docs/middleware.md)
- [Testing Guzzle Clients](docs/testing-guzzle-clients.md)
- [FAQ](docs/faq.md)
- [Package Ecosystem](docs/package-ecosystem.md)
- [Upgrade Guide](UPGRADING.md)
- [Changelog](CHANGELOG.md)

We use GitHub issues only to discuss bugs and new features. For support, use
[Stack Overflow](https://stackoverflow.com/questions/tagged/guzzle), the
[#guzzle](https://app.slack.com/client/T0D2S9JCT/CE6UAAKL4) channel on
[PHP-HTTP Slack](https://slack.httplug.io/), or
[Gitter](https://gitter.im/guzzle/guzzle).

## Security

If you discover a security vulnerability within this package, please send an
email to security@tidelift.com. All security vulnerabilities will be promptly
addressed. Please do not disclose security-related issues publicly until a fix
has been announced. Please see
[Security Policy](https://github.com/guzzle/guzzle/security/policy) for more
information.

## License

Guzzle is made available under the MIT License (MIT). Please see
[License File](LICENSE) for more information.

## For Enterprise

Available as part of the Tidelift Subscription

The maintainers of Guzzle and thousands of other packages are working with
Tidelift to deliver commercial support and maintenance for the open source
dependencies you use to build your applications. Save time, reduce risk, and
improve code health, while paying the maintainers of the exact dependencies you
use.
[Learn more.](https://tidelift.com/subscription/pkg/packagist-guzzlehttp-guzzle?utm_source=packagist-guzzlehttp-guzzle&utm_medium=referral&utm_campaign=enterprise&utm_term=repo)
