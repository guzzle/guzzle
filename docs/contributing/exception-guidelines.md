# Exception guidelines (contributor reference)

Guzzle code must be deliberate about which exception it throws, because
`GuzzleHttp\Client` is a PSR-18 client: PSR-18 requires that **every** exception
escaping `sendRequest()` implement `Psr\Http\Client\ClientExceptionInterface`.
All Guzzle exceptions implement it via `GuzzleHttp\Exception\GuzzleException`, so
"throw a Guzzle exception" and "stay PSR-18 compliant" are the same rule.

This document covers the two exceptions contributors most often have to choose
between — `InvalidArgumentException` (caller error) and `RequestException`
(runtime request failure) — and the handful of cases where a bare SPL exception
is correct.

`GuzzleHttp\Exception\NetworkException` exists only in Guzzle 8.0 and newer. On
Guzzle 7.x maintenance branches, network failures without a response use
`ConnectException`, which implements `Psr\Http\Client\NetworkExceptionInterface`
directly.

## The decision tree

```
Is the failure caused by the CALLER passing an invalid argument / option / value
that they could fix by changing their code?
├─ YES ─────────────────────────────► GuzzleHttp\Exception\InvalidArgumentException
│                                       (a ClientExceptionInterface, but NOT a
│                                        RequestExceptionInterface — see below)
│
└─ NO — it is a runtime failure.
   │
   ├─ Is it happening while sending a specific request (RequestInterface in scope)?
   │  ├─ Network failure, no response yet ──► NetworkException / ConnectException
   │  │                                        / ConnectTimeoutException / NetworkTimeoutException
   │  ├─ A response was received, then it failed ──► ResponseException / ResponseTransferException
   │  │                                              / ResponseTimeoutException / BadResponseException
   │  └─ Cannot even begin sending this request
   │     (curl handle init, sink directory missing, unsupported protocol) ──► RequestException
   │
   └─ No request in scope — an environment / construction-time check
      (e.g. "the cURL extension is not available") ──► \RuntimeException (bare SPL is fine here)
```

## `GuzzleHttp\Exception\InvalidArgumentException`

`final class InvalidArgumentException extends \InvalidArgumentException implements GuzzleException`

**Use it when** the caller supplied something invalid: a bad option value, the
wrong type, conflicting options, a non-callable callback, or a path that does not
exist. It is the only SPL-extending Guzzle exception, by design.

**Why this type and not `RequestException`?** Invalid *options* are not an invalid
*request*. The request message is well-formed; the caller misconfigured the
client. PSR-18 reserves `RequestExceptionInterface` for malformed request
*messages* ("method is missing", "body not seekable"). So this class implements
`GuzzleException` (→ `ClientExceptionInterface`) but deliberately **does not**
implement `RequestExceptionInterface`, and it does **not** carry a request.
(Decision recorded in guzzle/guzzle#2474.)

```php
use GuzzleHttp\Exception\InvalidArgumentException;

// GOOD — caller passed a non-callable for a callback option
if (!\is_callable($options['on_headers'])) {
    throw new InvalidArgumentException('on_headers must be callable');
}

// GOOD — caller pointed an option at a file that does not exist
if (!\file_exists($options['verify'])) {
    throw new InvalidArgumentException("SSL CA bundle not found: {$options['verify']}");
}
```

**Do NOT:**

```php
// BAD — the global SPL type does NOT implement ClientExceptionInterface, so it
// escapes sendRequest() and violates PSR-18. Never use it on the request path.
throw new \InvalidArgumentException('on_headers must be callable');

// BAD — invalid options are not a malformed request; do not make them a
// RequestExceptionInterface or attach a request to them.
throw new RequestException('on_headers must be callable', $request);
```

Because `GuzzleHttp\Exception\InvalidArgumentException extends \InvalidArgumentException`,
this is backward compatible: existing `catch (\InvalidArgumentException)` blocks
still catch it. `catch` blocks that need both forms must use the global type:

```php
} catch (\InvalidArgumentException $e) { // catches global AND Guzzle's
```

## `GuzzleHttp\Exception\RequestException`

`class RequestException extends TransferException implements RequestExceptionInterface`
(and `TransferException extends \RuntimeException implements GuzzleException`).

**Use it when** a *runtime* failure prevents Guzzle from completing a specific
request, you have the `RequestInterface` in scope, and no more specific type
applies (it is not a network failure, and no response was received). Typical
cases: the cURL easy handle could not be initialised, the `sink` directory does
not exist, or the requested protocol/scheme is not supported by the handler.

```php
use GuzzleHttp\Exception\RequestException;

// GOOD — runtime failure setting up THIS request; carries the request,
// is a ClientExceptionInterface AND a RequestExceptionInterface.
if (false === $handle) {
    throw new RequestException('Can not initialize cURL handle.', $request);
}
```

**Do NOT:**

```php
// BAD — bare \RuntimeException on the request path is not a ClientExceptionInterface
// (PSR-18 leak), and drops the request.
throw new \RuntimeException('Can not initialize cURL handle.');

// BAD — RequestException is for failures BEFORE a response. If a response was
// received, use the ResponseException family instead.
throw new RequestException('Body transfer failed', $request); // use ResponseTransferException
```

There is intentionally **no** `GuzzleHttp\Exception\RuntimeException`
(guzzle/guzzle#2507): request-bound runtime failures use
`RequestException`/`TransferException`; only non-request environment checks use
the bare `\RuntimeException`.

```php
// OK — construction/environment check, no request involved, not on the
// per-request sendRequest() path.
if (!\extension_loaded('curl')) {
    throw new \RuntimeException('The cURL extension is required.');
}
```

## When a bare SPL exception is the right answer

These are deliberate programmer-error signals. They are **not** GuzzleExceptions,
and that is intentional — they indicate misuse of a Guzzle-specific API, not a
failure to transfer an HTTP request, and they are unreachable through a normal
PSR-18 `sendRequest()` call:

| Situation | Exception | Why bare SPL |
|---|---|---|
| Reusing a handler/factory after `close()` | `\BadMethodCallException` | Lifecycle misuse (a `LogicException`); reachable only via the 8.0 `close()` API |
| Accessing an unsupported magic property | `\BadMethodCallException` | Programmer error |
| `MockHandler` queue exhausted | `\OutOfBoundsException` | Test-setup error |
| `MockHandler` queued an unsupported value | `\TypeError` | Programming error (an `\Error`) |

Do not "wrap" these into GuzzleExceptions to satisfy PSR-18 strictly: it would
break `catch (\BadMethodCallException)` / `catch (\OutOfBoundsException)` and the
tests that assert them, and PSR-18 governs `sendRequest()` transfers, not misuse
of Guzzle's own lifecycle APIs.

## Hard rules (summary)

1. **Never** throw the global `\InvalidArgumentException` from code reachable
   while preparing or sending a request — use
   `GuzzleHttp\Exception\InvalidArgumentException`.
2. **Never** create a `GuzzleHttp\Exception\RuntimeException` — it does not exist.
   Use `RequestException`/`TransferException` (request-bound) or bare
   `\RuntimeException` (environment-only).
3. **Never** make `InvalidArgumentException` a `RequestExceptionInterface` or give
   it a request — invalid options are not a malformed request.
4. **Never** throw for 4xx/5xx from `sendRequest()` — that is `http_errors`
   middleware, which PSR-18 mode disables.
5. Pick `Network*`/`Connect*` (no response) vs `Response*` (response received)
   vs `RequestException` (can't start) by transfer phase; see the hierarchy in
   `UPGRADING.md`.

## For consumers (catching)

`catch (\GuzzleHttp\Exception\GuzzleException)` (or PSR-18's
`Psr\Http\Client\ClientExceptionInterface`) catches every Guzzle transfer error.
`InvalidArgumentException` is also catchable as `\InvalidArgumentException`.
The bare-SPL signals above (closed handler, exhausted mock) are programmer errors
you should fix, not catch.
