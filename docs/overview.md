# Overview

## Requirements

1.  PHP 7.2.5
2.  To use the PHP stream handler, `allow_url_fopen` must be enabled in your system's php.ini.
3.  To use the cURL handler, you must have a recent version of cURL >= 7.19.4 compiled with OpenSSL and zlib.

> [!NOTE]
> Guzzle no longer requires cURL in order to send HTTP requests. Guzzle will use the PHP stream wrapper to send HTTP requests if cURL is not installed. Alternatively, you can provide your own HTTP handler used to send requests. Keep in mind that cURL is still required for sending concurrent requests.

## Installation

The recommended way to install Guzzle is with [Composer](https://getcomposer.org). Composer is a dependency management tool for PHP that allows you to declare the dependencies your project needs and installs them into your project.

```bash
# Install Composer
curl -sS https://getcomposer.org/installer | php
```

You can add Guzzle as a dependency using Composer:

```bash
composer require guzzlehttp/guzzle:^7.0
```

Alternatively, you can specify Guzzle as a dependency in your project's existing composer.json file:

```js
{
  "require": {
     "guzzlehttp/guzzle": "^7.0"
  }
}
```

After installing, you need to require Composer's autoloader:

```php
require 'vendor/autoload.php';
```

You can find out more on how to install Composer, configure autoloading, and other best-practices for defining dependencies at [getcomposer.org](https://getcomposer.org).

## Upgrading

The git repository contains an [upgrade guide](../UPGRADING.md) that details what changed between the major versions.

## Contributing

### Guidelines

1.  Guzzle utilizes PSR-1, PSR-2, PSR-4, and PSR-7.
2.  Guzzle is meant to be lean and fast with very few dependencies. This means that not every feature request will be accepted.
3.  Guzzle has a minimum PHP version requirement of PHP 7.2. Pull requests must not require a PHP version greater than PHP 7.2 unless the feature is only utilized conditionally and the file can be parsed by PHP 7.2.
4.  All pull requests must include unit tests to ensure the change works as expected and to prevent regressions.

### Running the tests

In order to contribute, you'll need to checkout the source from GitHub and install Guzzle's dependencies using Composer:

```bash
git clone https://github.com/guzzle/guzzle.git
cd guzzle && composer install
```

Guzzle is unit tested with PHPUnit:

```bash
vendor/bin/phpunit
```

> [!NOTE]
> You'll need Node.js `^20.19 || ^22.13 || >=24` available as `node` in order to perform integration tests on Guzzle's HTTP handlers.

## Reporting a security vulnerability

If you discover a security vulnerability within this package, please send an email to security@tidelift.com. All security vulnerabilities will be promptly addressed. Please do not disclose security-related issues publicly until a fix has been announced. Please see the [Security Policy](https://github.com/guzzle/guzzle/security/policy) for more information.
