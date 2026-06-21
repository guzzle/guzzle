# cURL connection reuse and sharing (contributor reference)

Guzzle's cURL handlers reuse network connections for performance, and can
optionally share them across handlers. Reuse is normally invisible, but it
interacts with proxy credentials, TLS client identity, and DNS in ways that have
produced real security bugs in libcurl. This document explains, for someone new
to the handler code, **how connection reuse works, what can go wrong, and the
safety rules Guzzle enforces — and why.** It assumes general HTTP/TLS
familiarity; libcurl and PHP-cURL specifics are introduced as they come up.

The connection-reuse safety code lives on both the 7.x maintenance branches and
8.0; this reference is maintained on 8.0, and 7.x differences are called out
where they matter. For *which exception type* to throw, see
[exception-guidelines.md](exception-guidelines.md).

## 1. How the cURL handlers send a request

`GuzzleHttp\Handler\CurlHandler` (synchronous) and `CurlMultiHandler`
(asynchronous) are the built-in cURL handlers. Both delegate to `CurlFactory`,
which builds and **pools cURL "easy handles."**

A cURL easy handle (`CurlHandle`) is libcurl's per-transfer object. Creating one
is cheap; the expensive thing is the network connection it opens. libcurl keeps
connections alive in a **connection cache** and reuses them for later transfers
on the same handle — or across handles, if you share them (§3).

`CurlFactory` pools easy handles: on `release()` it calls `curl_reset()` (which
clears per-request options) and returns the handle to a small pool; `create()`
pops a pooled handle and re-applies the next request's options. The key fact:
**`curl_reset()` does not close the underlying connection** — reuse is the whole
point — so a pooled handle can carry a live connection from one request into the
next.

The option pipeline is: Guzzle request options (`proxy`, `cert`, …) → an
internal `$conf` array of `CURLOPT_* => value` → `curl_setopt_array()`.
Low-level options passed through the `curl` request option are merged in too,
but only those on an allow-list (`supportedCurlOptions()`); options that
conflict with Guzzle-managed behavior are rejected. On 8.0 a non-allow-listed or
conflicting raw option throws; on 7.x it is deprecated (and 8.0 will reject it).
This gate is about option-key availability and known conflicts only. Unless
Guzzle documents a specific mitigation, the meaning, safety, and runtime effects
of raw cURL option values remain the caller's responsibility.

**PHP detail — the `\defined()` guard.** A `CURLOPT_*` constant is only defined
when the linked libcurl/PHP build supports that option, so code that touches an
optional option guards with `\defined()`/`\constant()`. This is safe by
construction: PHP's `curl_setopt()` never forwards an *unknown* option integer
to libcurl (PHP 8 throws `ValueError`; PHP 7 silently no-ops), and a constant is
registered under the same `#if LIBCURL_VERSION_NUM` guard as its setopt handler,
so an undefined constant can never become a live channel.

## 2. Why reuse is both good and dangerous

**Good:** skipping the TCP handshake, the TLS handshake, and (for a proxy
tunnel) the `CONNECT` exchange is a large latency win.

**Dangerous:** a reused connection carries *identity*. If libcurl reuses a
connection that was authenticated or encrypted under one identity for a request
that expects a different one, that is a leak. libcurl decides whether to reuse a
connection by **matching connection attributes**, and bugs in that matching have
produced CVEs (§4, §7).

libcurl matches on host, port, scheme, proxy type/host/port, and — for TLS — its
"primary" SSL config. There is an important split in libcurl's data model that
several decisions below depend on:

- **`ssl_primary_config` (compared for reuse):** client certificate and cert
  blob, CA info, TLS version, cipher lists, pinned key, TLS-SRP credentials,
  verify flags, issuer cert.
- **`ssl_config_data` (not compared):** the private key, key blob, key
  passphrase, and the cert/key *encoding* (PEM/DER/…).

## 3. transport_sharing: sharing connections across handlers (CURLSH)

The `transport_sharing` client option attaches a cURL **share handle**
(`CURLSH`, via `CURLOPT_SHARE`) to the pooled easy handles so they share state.
Modes: `NONE` (default), `HANDLER_PREFER`/`HANDLER_REQUIRE` (share within one
handler), and `PERSISTENT_PREFER`/`PERSISTENT_REQUIRE` (share a long-lived
cache). `*_REQUIRE` errors if sharing is unavailable; `*_PREFER` falls back to
no sharing.

What a share handle can share, and the libcurl floor Guzzle requires for each
(see `CurlVersion`):

| Shared state | `CURL_LOCK_DATA_*` | Floor | Constant |
|---|---|---|---|
| Share handles usable at all | — | 7.35.0 | `HANDLER_SHARING_VERSION` |
| DNS cache | `DNS` | 7.35.0 | (with handler sharing) |
| TLS session cache | `SSL_SESSION` | 8.6.0 | `SSL_SESSION_SHARING_VERSION` |
| Connection cache | `CONNECT` | 8.20.0 | `CONNECTION_SHARING_VERSION` |

The connection cache is shared only on 8.0 and only from 8.20.0; **7.x never
shares the connection cache.** Each floor is the version at which that share
class became safe (§7).

## 4. The proxy-tunnel credential hazard (why `proxyTunnelSignature` exists)

When you tunnel a request through an HTTP(S) proxy, libcurl sends a `CONNECT` to
the proxy carrying the proxy credentials (`Proxy-Authorization`), then keeps
that established tunnel in the connection cache.

**The bug (CVE-2026-3784 family).** libcurl 7.7–8.18.0 matched a proxy
connection by type/host/port but **not** by the proxy credentials. A pooled
tunnel established with credentials A could therefore be reused for a later
request carrying credentials B — leaking A's tunnel to B. curl 8.19.0 added a
credential comparison to `proxy_info_matches()`; 8.20.0 fixed related
proxy-credential leaks (credentials surviving a redirect, or a port/scheme
change).

**Guzzle's mitigation.** `CurlFactory::proxyTunnelSignature()` computes a
per-request signature over the proxy identity. When it changes between requests,
Guzzle forces a fresh connection — purging pooled idle handles that might hold a
foreign tunnel — so libcurl cannot hand one request a tunnel established under a
different identity.

## 5. The signature: what it covers and why

**Domain (when a signature is computed at all).** Only for a request that
establishes a proxy `CONNECT` tunnel through an HTTP(S), non-SOCKS proxy.
`usesProxyTunnel()` is true for an `https://` target, an explicit
`CURLOPT_HTTPPROXYTUNNEL`, or an `http://` target with a non-empty
`CURLOPT_CONNECT_TO` (§6); `isHttpProxyForConnectionReuse()` excludes SOCKS.
Direct, SOCKS, and non-tunnel requests get a `null` signature and never disturb
the pool.

**The channels hashed:** the effective proxy URL, the proxy credential and
TLS-identity options, and any literal `Proxy-Authorization` header value.

**Necessary vs defense-in-depth.**

- *Necessary:* the proxy credentials (`PROXYUSERPWD`/`PROXYUSERNAME`/
  `PROXYPASSWORD`) — the exact channel the CVE missed — and the literal
  `Proxy-Authorization` header (`CURLOPT_PROXYHEADER`), which libcurl **never**
  keys reuse on at any version (it matches *parsed* credentials, not opaque
  request headers). The header must therefore be sectioned even on fixed
  libcurl.
- *Mostly defense-in-depth:* the proxy-TLS options (client cert, cert blob, TLS
  version, TLS-SRP). libcurl keys reuse on these via `ssl_primary_config` for an
  HTTPS proxy — but only from 7.50.1 for the client cert (CVE-2016-5420) and
  7.83.1 for TLS-SRP (CVE-2022-27782). Below those they are load-bearing; above,
  redundant-but-free. They are hashed unconditionally rather than version-gated.

**Deliberate omissions.** `PROXY_SSLKEY_BLOB`, `PROXY_SSLKEYTYPE`, and
`PROXY_SSLCERTTYPE` are **not** hashed. libcurl's reuse matcher never keys on
the private key or on cert/key encoding (they live in `ssl_config_data`, outside
`ssl_primary_config`). They are safe to omit because the proxy-visible identity
is the **X.509 client certificate** — which *is* hashed — and a certificate has
exactly one matching key, so a key- or encoding-only change cannot present a
different identity to the proxy.

**The golden rule — over-sectioning is safe.** A non-`null`, changed signature
only ever forces a *fresh* connection; it never relaxes reuse. So an over-broad
signature merely costs an extra connection — it can never cause a leak.
*Under*-covering (omitting a channel libcurl ignores) is the only way to leak.
**When in doubt, include the channel.**

## 6. CONNECT_TO implicit tunnels

`CURLOPT_CONNECT_TO` redirects the origin connection to a different host:port
(without changing the `Host` header, SNI, or cert verification). With an HTTP
proxy, when the connect-to host or port differs, libcurl automatically switches
to tunnel mode (sets `tunnel_proxy`) — so even a plain `http://` request becomes
a credential-bearing `CONNECT` tunnel, the same hazard class as §4.

`usesProxyTunnel()` therefore treats an `http://` target with a non-empty
`CURLOPT_CONNECT_TO` as a possible tunnel. The check is deliberately
conservative — any non-empty value, not a full parse of CONNECT_TO's
`host:port:host:port` grammar (including its wildcard and IPv6 forms). By the
over-section rule, a false positive only forces a needless fresh connection,
never a leak; reimplementing libcurl's parser to avoid that would be a
liability, not a feature.

## 7. Connection-sharing safety decisions

**Authenticated proxy + a configured share handle → blanket force-fresh.** When
`transport_sharing` is configured, the shared connection cache hides which
tunnel a pooled connection holds, so the signature cannot reason about
provenance. For an authenticated proxy tunnel Guzzle then sets
`CURLOPT_FRESH_CONNECT` / `CURLOPT_FORBID_REUSE` (and `PERSISTENT_REQUIRE` turns
the conflict into an error rather than silently degrading).

"Authenticated" here mirrors the signature's channels, each gated to the libcurl
version below which libcurl does not itself key reuse on it: a literal
`Proxy-Authorization` header (every version), Basic/Digest proxy credentials
(below 8.20.0, `PROXY_CREDENTIAL_REUSE_VERSION`), and a proxy TLS credential — a
client certificate or TLS-SRP (below 7.83.1,
`PROXY_TLS_CREDENTIAL_REUSE_VERSION`). libcurl matches the proxy client cert
from 7.52.0 and TLS-SRP only from 7.83.1 (CVE-2022-27782), so both are keyed
under the single 7.83.1 floor. The floor matters more here than for the
signature: `forceFreshConnectionForAuthenticatedProxy` *throws* under
`PERSISTENT_REQUIRE`, which exists only on libcurl ≥ 8.20.0 — so forcing fresh
for a TLS credential at every version would turn a shareable mTLS-proxy request
into an error, while the 7.83.1 gate keeps the force-fresh strictly below the
versions where persistent sharing (and that throw) exist.

**SSL session sharing floor = 8.6.0 — why it is safe.** Sharing the TLS session
cache could, in theory, let two handles resume each other's TLS session across
*different* client certificates. It cannot: libcurl matches the client
certificate before reusing a cached session on every version Guzzle shares the
cache on (≥ 8.6.0) — via `match_ssl_primary_config` before 8.12.0 and
`cf_ssl_scache_match_auth` from 8.12.0 — and this runs for shared caches too.
8.6.0 is also the CVE-2024-0853 fix release. curl's 8.12.0 `ssl_peer_key` rework
is a refactor of that same client-cert-aware matching, not a fix 8.6.0 lacks, so
the floor stays at 8.6.0.

**`CURLOPT_RESOLVE` under sharing → no special handling.** A request-level
`CURLOPT_RESOLVE` writes the *shared* DNS cache, so its host→IP entries are
visible to later requests on the same share handle. Guzzle leaves this as-is, on
purpose: it has a legitimate use (client-wide DNS pinning via the shared cache,
typically set as a client-level `curl` default), it is advanced custom
configuration that remains the caller's responsibility (like other raw cURL
options), and Guzzle cannot distinguish an intentional client-wide pin from an
accidental per-request one. For per-request routing that does **not** touch the
DNS cache, use `CURLOPT_CONNECT_TO`. (Contrast `CURLOPT_SHARE`, which *is*
rejected under a configured share handle: a second, request-level share handle
is simply incoherent, with no legitimate use.)

## 8. The version trust floor

`PROXY_CREDENTIAL_REUSE_VERSION = 8.20.0`. Below it, hash the credential
channels: libcurl < 8.19.0 ignored proxy credentials when matching connections,
and 8.19.x still carried related proxy-credential leaks fixed in 8.20.0. At or
above it, libcurl keys reuse on option- and URL-supplied proxy credentials
itself, so `proxyTunnelSignature()` short-circuits to `null` — **except** when a
literal `Proxy-Authorization` header is present, which always sections because
libcurl can never key on an opaque request header (§5).

## 9. How the tests enforce this

`tests/Handler/CurlFactoryTest.php` covers the signature by channel and the
pool's purge/reuse behavior. In particular,
`testProxyTlsAuthCredentialChangesProxyTunnelSignature` pins the TLS-SRP channel
as load-bearing; it is a reflection test, so it exercises
`proxyTunnelSignature()` directly without going through the option allow-list
(which would otherwise reject the raw proxy-TLS option on 8.0). **When you add
or remove a channel, add or adjust a test** — a silently dropped channel is
exactly how a leak gets reintroduced, and CI is the backstop the comments point
at.

`testProxyTlsCredentialsRequireFreshConnectionOnAffectedCurlVersion` does the
same for the share-handle force-fresh path: it asserts
`requiresFreshConnectionForAuthenticatedProxy` forces a fresh tunnel for a proxy
TLS credential below 7.83.1 and not at or above it.

## 10. Hard rules (summary)

- Never compute a `null` signature for a credential-bearing proxy tunnel.
  Over-section freely; under-sectioning is the only way to leak.
- Always hash the proxy credentials and the literal `Proxy-Authorization`
  header; the header sections on **every** libcurl version.
- Never trust libcurl `< 8.20` to distinguish proxy credentials itself.
- Do not key the signature on the private key or on cert/key encoding — the
  certificate is the proxy-visible identity.
- `usesProxyTunnel()` must treat `http://` + a non-empty `CURLOPT_CONNECT_TO` as
  a tunnel.
- Share the TLS session cache only from 8.6.0 and the connection cache only from
  8.20.0.
- Under a configured share handle, force a fresh tunnel for a proxy TLS
  credential (client cert / TLS-SRP) below 7.83.1, mirroring the signature path;
  the 7.83.1 gate keeps it below the version where `PERSISTENT_REQUIRE` would
  throw.

## References

**curl** — CVE-2026-3784 (proxy `CONNECT` credential reuse), CVE-2016-5420
(proxy client-cert reuse), CVE-2022-27782 (TLS / TLS-SRP config not compared on
reuse), CVE-2024-0853 (client cert / OCSP on session reuse). Source of record:
`lib/url.c` (`proxy_info_matches`, the connection matcher, `tunnel_proxy`) and
`lib/vtls/` (`ssl_primary_config` vs `ssl_config_data`, the session cache and
`ssl_peer_key`). Docs:
[`CURLOPT_CONNECT_TO`](https://curl.se/libcurl/c/CURLOPT_CONNECT_TO.html),
[`CURLOPT_RESOLVE`](https://curl.se/libcurl/c/CURLOPT_RESOLVE.html), and the
[share interface](https://curl.se/libcurl/c/libcurl-share.html).

**php** — the cURL extension (`curl_setopt`, `curl_reset`, `curl_share_*`).
`CURLOPT_*` constants exist only when the linked libcurl version supports the
option, and `curl_setopt()` rejects unknown option integers (PHP 8
`ValueError`), which is what makes the `\defined()` guard safe.

**guzzle** — `src/Handler/CurlFactory.php` (`proxyTunnelSignature`,
`usesProxyTunnel`, `isHttpProxyForConnectionReuse`),
`src/Handler/CurlVersion.php` (the version floors), and
[exception-guidelines.md](exception-guidelines.md) for exception types.
