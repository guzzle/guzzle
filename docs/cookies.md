# Cookies

Guzzle can maintain a cookie session for you if instructed using the `cookies`
request option. When sending a request, the `cookies` option must be set to an
instance of `GuzzleHttp\Cookie\CookieJarInterface`, or `false` to disable
cookies for that request.

```php
// Use a specific cookie jar
$jar = new \GuzzleHttp\Cookie\CookieJar;
$r = $client->request('GET', 'http://httpbin.org/cookies', [
    'cookies' => $jar
]);
```

You can set `cookies` to `true` in a client constructor if you would like Guzzle
to create a shared cookie jar for all requests. This `true` shorthand is only
valid in the client constructor; per-request `cookies` values must be `false` or
a `CookieJarInterface`.

```php
// Use a shared client cookie jar
$client = new \GuzzleHttp\Client(['cookies' => true]);
$r = $client->request('GET', 'http://httpbin.org/cookies');
```

Different implementations exist for the `GuzzleHttp\Cookie\CookieJarInterface` :

- The `GuzzleHttp\Cookie\CookieJar` class stores cookies as an array.
- The `GuzzleHttp\Cookie\FileCookieJar` class persists non-session cookies using
  a JSON formatted file.
- The `GuzzleHttp\Cookie\SessionCookieJar` class persists cookies in the client
  session.

You can manually set cookies into a cookie jar with the named constructor
`fromArray(array $cookies, $domain)`.

```php
$jar = \GuzzleHttp\Cookie\CookieJar::fromArray(
    [
        'some_cookie' => 'foo',
        'other_cookie' => 'barbaz1234'
    ],
    'example.org'
);
```

Domainless `SetCookie` instances can be stored in a cookie jar for representing
or forwarding `Set-Cookie` headers. Because manually-created domainless cookies
do not include an origin host, Guzzle will not send them on outgoing requests.

You can get a cookie by its name with the `getCookieByName($name)` method which
returns a `GuzzleHttp\Cookie\SetCookie` instance.

#### Cookie Name Case Sensitivity

Cookie names are case-sensitive. `getCookieByName($name)` matches the exact
cookie name, so a jar can hold distinct `SID` and `sid` cookies and each must be
retrieved with its exact case.

```php
$cookie = $jar->getCookieByName('some_cookie');

$cookie->getValue(); // 'foo'
$cookie->getDomain(); // 'example.org'
$cookie->getExpires(); // expiration date as a Unix timestamp
```

The cookies can be also fetched into an array thanks to the `toArray()` method.
The `GuzzleHttp\Cookie\CookieJarInterface` interface extends `Traversable` so it
can be iterated in a foreach loop.

## Persisting Cookies

`FileCookieJar` and `SessionCookieJar` persist cookies between requests so a jar
can outlive a single PHP process.

```php
use GuzzleHttp\Cookie\FileCookieJar;
use GuzzleHttp\Cookie\SessionCookieJar;

// Persist cookies to a JSON file.
$jar = new FileCookieJar('/path/to/cookies.json');

// Persist cookies in the PHP session under the given key.
$jar = new SessionCookieJar('guzzle_cookies');
```

`FileCookieJar(string $cookieFile, bool $storeSessionCookies = false)` loads any
existing cookies from the file when constructed and saves them back when the jar
is destroyed; you can also call `save($filename)` and `load($filename)`
explicitly.
`SessionCookieJar(string $sessionKey, bool $storeSessionCookies = false)` stores
cookies in `$_SESSION[$sessionKey]` and requires an active PHP session.

The `storeSessionCookies` flag controls which cookies are persisted. When it is
`false` (the default), only cookies with an expiry are saved; set it to `true`
to also persist session cookies that have no expiry.

Both persistent jars expect stored cookie data to be a JSON list. Each list
entry must decode to an array, and recognized `SetCookie` fields must use their
documented constructor types. Malformed JSON or a different stored shape throws
a `RuntimeException`. Cookie records are constructed before any are passed to
`setCookie()`, so invalid stored shapes and recognized fields with the wrong
types leave existing cookies unchanged.

Loading an empty cookie file is a no-op. `SessionCookieJar` treats a missing or
`null` session value as no stored data; any other value must be a string
containing a JSON list.

> [!NOTE]
> `FileCookieJar` writes the cookie file with owner-only permissions (`0600`)
> where the filesystem supports them. Persisted cookies can include credentials,
> so store the file in a directory only the owner can access and keep it out of
> any web-served location.

## Managing Cookies

`CookieJar` implements the full `CookieJarInterface`, so you can inspect and
mutate the jar directly. The constructor accepts a strict-mode flag and an
optional set of cookies to seed the jar:

```php
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;

// In strict mode, adding an invalid cookie throws instead of being ignored.
$jar = new CookieJar(true, [
    new SetCookie(['Name' => 'foo', 'Value' => 'bar', 'Domain' => 'example.org']),
]);

// Add or replace a cookie. Returns false if the cookie was rejected.
$jar->setCookie(new SetCookie(['Name' => 'baz', 'Value' => 'qux', 'Domain' => 'example.org']));

echo count($jar); // number of cookies in the jar

// Remove cookies. With no arguments, every cookie is removed; narrow the
// selection by domain, then path, then name.
$jar->clear('example.org');

// Remove session cookies, keeping cookies that have an expiry.
$jar->clearSessionCookies();
```

## Working with Set-Cookie Headers

`GuzzleHttp\Cookie\SetCookie` represents a single cookie. Parse a raw
`Set-Cookie` header with `SetCookie::fromString()`, or build one from an array
keyed by `Name`, `Value`, `Domain`, `Path`, `Max-Age`, `Expires`, `Secure`,
`Discard`, and `HttpOnly`.

```php
use GuzzleHttp\Cookie\SetCookie;

$cookie = SetCookie::fromString('session=abc123; Domain=example.org; Path=/; HttpOnly');

$cookie->getName();    // 'session'
$cookie->getValue();   // 'abc123'
$cookie->getDomain();  // 'example.org'
$cookie->isExpired();  // false
$cookie->toArray();    // the cookie as an associative array
```

## Related

- [Quick Start](quick-start.md)
- [Request Options](request-options.md)
