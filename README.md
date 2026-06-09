![Guzzle](.github/logo.png?raw=true)

# Guzzle, PHP HTTP Client

[![Latest Version](https://img.shields.io/github/release/guzzle/guzzle.svg?style=flat-square)](https://github.com/guzzle/guzzle/releases)
[![Build Status](https://img.shields.io/github/actions/workflow/status/guzzle/guzzle/ci.yml?label=ci%20build&style=flat-square)](https://github.com/guzzle/guzzle/actions?query=workflow%3ACI)
[![Total Downloads](https://img.shields.io/packagist/dt/guzzlehttp/guzzle.svg?style=flat-square)](https://packagist.org/packages/guzzlehttp/guzzle)

Guzzle is a PHP HTTP client that makes it easy to send HTTP requests and
trivial to integrate with web services.

- Simple interface for building query strings, POST requests, streaming large
  uploads, streaming large downloads, using HTTP cookies, uploading JSON data,
  etc...
- Can send both synchronous and asynchronous requests using the same interface.
- Uses PSR-7 interfaces for requests, responses, and streams. This allows you
  to utilize other PSR-7 compatible libraries with Guzzle.
- Supports PSR-18 allowing interoperability between other PSR-18 HTTP Clients.
- Abstracts away the underlying HTTP transport, allowing you to write
  environment and transport agnostic code; i.e., no hard dependency on cURL,
  PHP streams, sockets, or non-blocking event loops.
- Middleware system allows you to augment and compose client behavior.

```php
$client = new \GuzzleHttp\Client();
$response = $client->request('GET', 'https://api.github.com/repos/guzzle/guzzle');

echo $response->getStatusCode(); // 200
echo $response->getHeaderLine('content-type'); // 'application/json; charset=utf8'
echo $response->getBody(); // '{"id": 1420053, "name": "guzzle", ...}'

// Send an asynchronous request.
$request = new \GuzzleHttp\Psr7\Request('GET', 'http://httpbin.org');
$promise = $client->sendAsync($request)->then(function ($response) {
    echo 'I completed! ' . $response->getBody();
});

$promise->wait();
```

## Help and Docs

We use GitHub issues only to discuss bugs and new features. For support please refer to:

- [Documentation](docs/index.md)
- [Stack Overflow](https://stackoverflow.com/questions/tagged/guzzle)
- [#guzzle](https://app.slack.com/client/T0D2S9JCT/CE6UAAKL4) channel on [PHP-HTTP Slack](https://slack.httplug.io/)
- [Gitter](https://gitter.im/guzzle/guzzle)


## Installing Guzzle

The recommended way to install Guzzle is through
[Composer](https://getcomposer.org/).

```bash
composer require guzzlehttp/guzzle
```

## Version Guidance

| Version | Status       | Documentation | PHP Version  |
|---------|--------------|---------------|--------------|
| 8.x     | Experimental | [8.x docs](docs/index.md) | >=7.4,<8.6   |
| 7.x     | Latest       | [7.x docs](https://github.com/guzzle/guzzle/blob/7.12/docs/index.md) | >=7.2.5,<8.6 |
| 6.x     | End of Life  | [6.x docs](https://github.com/guzzle/guzzle/blob/6.5/docs/index.md) | >=5.5,<8.0   |


## Package Roadmap

Most users should install `guzzlehttp/guzzle` when they want to send HTTP requests. The Guzzle organization also maintains smaller packages for PSR-7 messages, promises, service clients, OAuth 1.0 signing, URI templates, and testing.

| Package | Use it for |
|---------|------------|
| [`guzzlehttp/guzzle`](docs/index.md) | Sending HTTP requests from applications and libraries. |
| [`guzzlehttp/psr7`](https://github.com/guzzle/psr7/blob/3.0/docs/messages.md) | Creating and manipulating PSR-7 requests, responses, streams, and URIs. |
| [`guzzlehttp/promises`](https://github.com/guzzle/promises/blob/3.0/docs/quickstart.md) | Working with promises returned by asynchronous Guzzle operations. |
| [`guzzlehttp/uri-template`](https://github.com/guzzle/uri-template/blob/2.0/docs/usage.md) | Expanding RFC 6570 URI templates. |
| [`guzzlehttp/command`](https://github.com/guzzle/command/blob/2.0/docs/service-clients.md) | Building command-based SDK-style service clients. |
| [`guzzlehttp/guzzle-services`](https://github.com/guzzle/guzzle-services/blob/2.0/docs/service-descriptions.md) | Building service-description-driven clients on top of Guzzle Command. |
| [`guzzlehttp/oauth-subscriber`](https://github.com/guzzle/oauth-subscriber/blob/1.0/docs/usage.md) | Signing Guzzle requests with OAuth 1.0. |
| [`guzzlehttp/test-server`](https://github.com/guzzle/test-server/blob/1.0/docs/usage.md) | Testing HTTP clients against a local controllable server. |

See the [package roadmap](docs/package-roadmap.md) for more guidance on which package to use.


## Security

If you discover a security vulnerability within this package, please send an email to security@tidelift.com. All security vulnerabilities will be promptly addressed. Please do not disclose security-related issues publicly until a fix has been announced. Please see [Security Policy](https://github.com/guzzle/guzzle/security/policy) for more information.

## License

Guzzle is made available under the MIT License (MIT). Please see [License File](LICENSE) for more information.

## For Enterprise

Available as part of the Tidelift Subscription

The maintainers of Guzzle and thousands of other packages are working with Tidelift to deliver commercial support and maintenance for the open source dependencies you use to build your applications. Save time, reduce risk, and improve code health, while paying the maintainers of the exact dependencies you use. [Learn more.](https://tidelift.com/subscription/pkg/packagist-guzzlehttp-guzzle?utm_source=packagist-guzzlehttp-guzzle&utm_medium=referral&utm_campaign=enterprise&utm_term=repo)
