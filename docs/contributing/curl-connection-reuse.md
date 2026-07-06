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
**`curl_reset()` does not close the underlying connection** — reuse is the
whole point — so a pooled handle can carry a live connection from one request
into the next.

The option pipeline is: Guzzle request options (`proxy`, `cert`, …) → an
internal `$conf` array of `CURLOPT_* => value` → `curl_setopt_array()`.
Low-level options passed through the `curl` request option are merged in too,
but only those on an allow-list (`supportedCurlOptions()`); options that
conflict with Guzzle-managed behavior are rejected. On 8.0 a non-allow-listed or
conflicting raw option throws; on 7.x it is deprecated (and 8.0 will reject it).
This gate is about option-key availability and known conflicts only. Unless
Guzzle documents a specific mitigation, the meaning, safety, and runtime effects
of raw cURL option values remain the caller's responsibility.

**PHP detail — the `\defined()` guard.** A `CURLOPT_*` constant is only
defined when the linked libcurl/PHP build supports that option, so code that
touches an optional option guards with `\defined()`/`\constant()`. This is safe
by construction: PHP's `curl_setopt()` never forwards an *unknown* option
integer to libcurl (PHP 8 throws `ValueError`; PHP 7 silently no-ops), and a
constant is registered under the same `#if LIBCURL_VERSION_NUM` guard as its
setopt handler, so an undefined constant can never become a live channel.

## 2. Why reuse is both good and dangerous

**Good:** skipping the TCP handshake, the TLS handshake, and (for a proxy
tunnel) the `CONNECT` exchange is a large latency win.

**Dangerous:** a reused connection carries *identity*. If libcurl reuses a
connection that was authenticated or encrypted under one identity for a request
that expects a different one, that is a leak. libcurl decides whether to reuse a
connection by **matching connection attributes**, and bugs in that matching have
produced CVEs (§4, §7).

libcurl matches on host, port, scheme, proxy type/host/port, and — for TLS —
its "primary" SSL config. There is an important split in libcurl's data model
that several decisions below depend on:

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
| Connection cache | `CONNECT` | 8.12.0 | `CONNECTION_SHARING_VERSION` |

The connection cache is shared only on 8.0 and only from 8.12.0; **7.x never
shares the connection cache.** The DNS/handler and TLS-session floors are each
the version at which that share class became safe (§7). The connection-cache
floor is set differently. **8.12.0** is the lowest libcurl that meets two
conditions. First, it fixes every connection-reuse and TLS-session-reuse defect
reachable by a *default* `https://` request, meaning default verification with
no client certificate and no proxy authentication; the binding fix is
CVE-2024-0853, released in 8.6.0. Second, its session-cache rewrite in curl PR
#16245 lets the co-shared `SSL_SESSION` lock resume sessions across easy
handles, where before 8.12.0 the share is accepted but sessions stay largely
handle-local. Proxy-credential reuse fixes that would otherwise argue for 8.20.0
do not hold this floor up, because Guzzle sections proxy credentials
independently under `PROXY_CREDENTIAL_REUSE_VERSION` (§8). Direct mTLS
client-certificate reuse stays incomplete below 8.21.0 under CVE-2026-8932, a
documented caveat for client-cert users rather than a floor-mover for the
majority. The absolute hard floor is 7.83.1, from CVE-2022-27782, below which a
shared connection cache must never be used.

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
Guzzle forces a fresh connection — purging pooled idle handles that might hold
a foreign tunnel — so libcurl cannot hand one request a tunnel established
under a different identity.

**`CurlMultiHandler` active-attachment isolation.** A single
`CurlMultiHandler` reuses one libcurl multi handle — and therefore one
connection cache — across transfers. It tracks the proxy tunnel section that
owns that idle cache in a scalar `$proxyTunnelOwner`, handed over only when the
handle is fully idle.
Concurrency is governed separately by a reference-counted map of the proxy
tunnel signatures *currently attached* to the multi handle. While a foreign
signature is attached, even an owner-compatible transfer is isolated with
`CURLOPT_FRESH_CONNECT` + `CURLOPT_FORBID_REUSE` — the `A / B / A` case —
because the latched owner records who inherited the idle cache, not which
sections are in flight right now.

## 5. The signature: what it covers and why

**Domain (when a signature is computed at all).** Two proxy families section: a
request that establishes a proxy `CONNECT` tunnel through an HTTP(S) proxy —
`usesProxyTunnel()` is true for an `https://` target, an explicit
`CURLOPT_HTTPPROXYTUNNEL`, or an `http://` target with a non-empty
`CURLOPT_CONNECT_TO` (§6) — and any request through a SOCKS proxy on libcurl
older than 7.69.0 (below). Direct and non-tunnel HTTP-proxy requests get a
`null` signature and never disturb the pool. Real delegated tunnels on fixed
libcurl get a non-`null` sentinel, so they stay distinct from genuine
non-tunnels and from literal proxy-header tunnel owners.

> Non-tunnel proxy requests get a `null` signature and are deliberately left
> unsectioned. For per-request (HTTP Basic) proxy auth this is safe: `proxy` URL
> userinfo and the allow-listed `CURLOPT_PROXYUSERPWD` re-authenticate on every
> request, so a reused non-tunnel proxy connection cannot inherit a foreign
> identity. Connection-oriented schemes (NTLM, Negotiate) bind identity to the
> TCP connection, and a caller can still express one through a literal
> `Proxy-Authorization` header — sent as a PSR header or via the allow-listed
> raw `CURLOPT_PROXYHEADER` — even though raw `CURLOPT_PROXYAUTH` is rejected.
> A non-tunnel request carrying such a header is therefore an accepted residual:
> it is left unsectioned, because sectioning non-tunnel proxy connections would
> extend the signature domain beyond CONNECT tunnels, which is intentionally out
> of scope. (A single static header value cannot complete NTLM's multi-leg
> handshake, but a single-leg Negotiate token can, so the residual is real.)

**SOCKS proxies (sectioned below 7.69.0).** SOCKS authentication is
connection-scoped: the credentials are negotiated once, right after TCP connect,
and the proxy attributes everything else sent on that connection to that
identity — the same identity binding as an authenticated `CONNECT` tunnel, one
layer down. libcurl only started comparing SOCKS credentials when matching
connections for reuse in 7.69.0 (curl #4835); older libcurl matches a SOCKS
proxy by type, host, and port only, so a pooled connection authenticated as one
user could be reused for another — or for a request carrying no credentials at
all. `proxyTunnelSignature()` therefore routes every SOCKS proxy (the `socks`,
`socks4`, `socks4a`, `socks5`, and `socks5h` schemes, or a scheme-less proxy
with a SOCKS `CURLOPT_PROXYTYPE`) to `socksProxySignature()` ahead of the tunnel
domain checks, because SOCKS binds plain `http://` requests as much as
`https://` ones. From 7.69.0 the signature is `null`: libcurl keys reuse on the
parsed SOCKS credentials itself, both URL userinfo and the
`CURLOPT_PROXYUSERPWD` family feed the compared fields, and SOCKS has no
opaque-header analogue of `Proxy-Authorization`, so unlike `CONNECT` tunnels
there is no channel libcurl cannot key. Below 7.69.0 every SOCKS request is
sectioned by a hash of the effective proxy URL and the proxy credential options;
the credential-less state hashes too, distinctly, because an anonymous request
would otherwise match — and inherit — an authenticated pooled connection.
The SOCKS5 auth-method mask, the SOCKS GSSAPI options, and `CURLOPT_PRE_PROXY`
are not hashed: they are rejected raw options on 8.0, and branches that still
apply them as deprecated raw options leave them the caller's responsibility —
an accepted residual.

**The channels hashed:** the effective proxy URL, the proxy credential and
TLS-identity options, and any literal `Proxy-Authorization` header value.

PSR `Proxy-Authorization` headers are normalized into the proxy-header channel
(`CURLOPT_PROXYHEADER` with `CURLOPT_HEADEROPT => CURLHEADER_SEPARATE`) for
effective HTTP and HTTPS proxies wherever libcurl supports header separation
(7.37.0+). A non-empty literal `Proxy-Authorization: <value>` header is never
something libcurl can key connection reuse on, even on versions that key parsed
proxy credentials (8.20.0+), so a tunnel carrying one always sections — its
signature is hashed, never the delegated owner. (The empty
`Proxy-Authorization;` form is migrated for wire correctness but carries no
credential, so it does not section.) On libcurl older than 7.37.0 the header
cannot be separated; the handlers keep a non-empty credential header on the wire
but force a fresh, non-reused connection, which under `PERSISTENT_REQUIRE` is
reported as a conflict rather than silently degrading reuse.

Proxy TLS credential coverage stays tunnel-only and private: it is
reflection-tested hardening for CONNECT tunnels, not public non-tunneled
HTTPS-proxy behavior, because raw proxy TLS cURL options are rejected as public
inputs in 8.0.

**Necessary vs defense-in-depth.**

- *Necessary:* the proxy credentials (`PROXYUSERPWD`/`PROXYUSERNAME`/
  `PROXYPASSWORD`) — the exact channel the CVE missed — and the non-empty
  literal `Proxy-Authorization` header (`CURLOPT_PROXYHEADER`), which libcurl
  **never** keys reuse on at any version (it matches *parsed* credentials, not
  opaque request headers). The non-empty header must therefore be sectioned even
  on fixed libcurl.
- *Mostly defense-in-depth:* the proxy-TLS options (client cert, cert blob, TLS
  version, TLS-SRP). libcurl keys reuse on these via `ssl_primary_config` for an
  HTTPS proxy — but only from 7.52.0 for the proxy client cert (CVE-2016-5420
  in 7.50.1 is the origin-cert precedent, predating HTTPS-proxy support) and
  7.83.1 for TLS-SRP (CVE-2022-27782). Below those they are load-bearing;
  above, redundant-but-free. They are hashed unconditionally rather than
  version-gated.

**Deliberate omissions.** `PROXY_SSLKEY_BLOB`, `PROXY_SSLKEYTYPE`, and
`PROXY_SSLCERTTYPE` are **not** hashed. The private-key *file* and passphrase
(`PROXY_SSLKEY`, `PROXY_KEYPASSWD`) *are* hashed on the non-delegated signature
path as fallback hardening — libcurl's mTLS private-key matching on reuse was
incomplete before 8.21.0 (CVE-2026-8932), see §10. This is not a complete
pre-8.21.0 mitigation: it does not cover the delegated path (>= 8.20.0 with no
non-empty literal proxy-auth header) or configured share handles. The blob and
the two encoding types are an **accepted residual**, not proven-safe.

**The golden rule — over-sectioning is safe.** A non-`null`, changed signature
only ever forces a *fresh* connection; it never relaxes reuse. So an over-broad
signature merely costs an extra connection — it can never cause a leak.
*Under*-covering (omitting a channel libcurl ignores) is the only way to leak.
**When in doubt, include the channel.**

Raw `CURLOPT_PROXY`/`CURLOPT_NOPROXY` are rejected before this logic in 8.0. On
branches that still accept raw `CURLOPT_NOPROXY`, effective-proxy detection must
model only the exact, untrimmed `CURLOPT_NOPROXY === '*'` bypass-all case. Host,
domain, CIDR, and port values must be treated as still proxied; that can
over-section a request libcurl would route direct, but it cannot under-section a
real proxy tunnel.

## 6. CONNECT_TO implicit tunnels

`CURLOPT_CONNECT_TO` redirects the origin connection to a different host:port
(without changing the `Host` header, SNI, or cert verification). With an HTTP
proxy, when the connect-to host or port differs, libcurl automatically
switches to tunnel mode (sets `tunnel_proxy`) — so even a plain `http://`
request becomes a credential-bearing `CONNECT` tunnel, the same hazard class as
§4.

`usesProxyTunnel()` therefore treats an `http://` target with a non-empty
`CURLOPT_CONNECT_TO` as a possible tunnel. The check is deliberately
conservative — any non-empty value, not a full parse of CONNECT_TO's
`host:port:host:port` grammar (including its wildcard and IPv6 forms). By the
over-section rule, a false positive only forces a needless fresh connection,
never a leak; reimplementing libcurl's parser to avoid that would be a
liability, not a feature.

## 7. Connection-sharing safety decisions

**Authenticated proxy + a configured share handle → blanket force-fresh.**
When `transport_sharing` is configured, the shared connection cache hides which
tunnel a pooled connection holds, so the signature cannot reason about
provenance. For an authenticated proxy tunnel Guzzle then sets
`CURLOPT_FRESH_CONNECT` / `CURLOPT_FORBID_REUSE` (and `PERSISTENT_REQUIRE` turns
the conflict into an error rather than silently degrading).

"Authenticated" here mirrors the signature's channels, each gated to the libcurl
version below which libcurl does not itself key reuse on it: a non-empty literal
`Proxy-Authorization` header (every version), Basic/Digest proxy credentials
(below 8.20.0, `PROXY_CREDENTIAL_REUSE_VERSION`), and a proxy TLS credential —
a client certificate or TLS-SRP (below 7.83.1,
`PROXY_TLS_CREDENTIAL_REUSE_VERSION`). libcurl matches the proxy client cert
from 7.52.0 and TLS-SRP only from 7.83.1 (CVE-2022-27782), so both are keyed
under the single 7.83.1 floor. The floor matters more here than for the
signature, because `forceFreshConnectionForAuthenticatedProxy` *throws* under
`PERSISTENT_REQUIRE` rather than degrading to a fresh connection, and persistent
sharing, and therefore that throw, is reachable from
`CONNECTION_SHARING_VERSION` at 8.12.0. So the relationship between each
channel's gate and 8.12.0 decides whether the throw can fire:

- The proxy TLS credential gate at 7.83.1 sits strictly *below* 8.12.0, so a
  proxy client cert or TLS-SRP only forces fresh on builds older than 7.83.1,
  where persistent sharing does not exist. A shareable mTLS-proxy request on a
  persistent-capable build is never turned into a force-fresh or a
  `PERSISTENT_REQUIRE` error by this path; forcing fresh at every version would
  have done exactly that, which is why the 7.83.1 gate is correct.
- The proxy Basic/Digest gate at 8.20.0 sits *above* 8.12.0, and a non-empty
  literal `Proxy-Authorization` header forces fresh at every version. So on
  libcurl 8.12.0–8.19.x a `PERSISTENT_REQUIRE` request that carries proxy
  Basic/Digest credentials, or a non-empty literal `Proxy-Authorization`
  header, reaches `forceFreshConnectionForAuthenticatedProxy` and **throws**:
  persistent sharing is active there, but libcurl on those builds does not yet
  key reuse on the proxy credential, so the only safe options are a fresh
  connection or, under `PERSISTENT_REQUIRE`, rejection. This is intended; it
  rejects unsafe persistent reuse rather than silently sharing a tunnel across
  credentials, and it became reachable when the connection-sharing floor moved
  from 8.20.0 down to 8.12.0. From 8.20.0 the credential is keyed by libcurl,
  so the throw no longer applies to parsed credentials; the non-empty
  literal-header case still does, since libcurl can never key on an opaque
  request header.

**SOCKS proxies under a share handle → no special handling needed.** A shared
connection cache requires libcurl 8.12.0 or newer (§3), which is above the
7.69.0 SOCKS credential floor, so wherever a shared connection cache can exist
libcurl already keys SOCKS credentials itself.
`forceFreshConnectionForAuthenticatedProxy()` needs no SOCKS rule, and
`PERSISTENT_REQUIRE` can never throw for SOCKS credentials.

**SSL session sharing floor = 8.6.0 — why it is safe.** Sharing the TLS
session cache could, in theory, let two handles resume each other's TLS session
across *different* client certificates. It cannot: libcurl matches the client
certificate before reusing a cached session on every version Guzzle shares the
cache on (≥ 8.6.0) — via `match_ssl_primary_config` before 8.12.0 and
`cf_ssl_scache_match_auth` from 8.12.0 — and this runs for shared caches too.
8.6.0 is also the CVE-2024-0853 fix release. curl's 8.12.0 `ssl_peer_key` rework
is a refactor of that same client-cert-aware matching, not a fix 8.6.0 lacks, so
the floor stays at 8.6.0. This safety covers TLS-*session* resumption only;
libcurl's broader connection-reuse matching of the client *private key* is
**not** complete below 8.21.0. See the direct-mTLS note below and CVE-2026-8932.

**Direct (non-proxy) mTLS client certificate under sharing → accepted opt-in
risk, not force-freshed.** The connection-cache floor at 8.12.0 activates a
shared `CURL_LOCK_DATA_CONNECT` cache on libcurl 8.12.0–8.20.x, but libcurl's
connection-reuse matching does not fully account for client-certificate
private-key options below 8.21.0, under CVE-2026-8932. Unlike the
proxy-TLS-credential path above, Guzzle deliberately does **not** force a fresh
connection or reject a request for the direct `cert` and `ssl_key` options here:
a persistent pool that sends requests with different client-certificate
identities to the same host on libcurl older than 8.21.0 can reuse a connection
authenticated with a different private key. This is an accepted opt-in risk for
this release. It requires `PERSISTENT_PREFER` or `PERSISTENT_REQUIRE`, which are
off by default, and is documented in the persistent-sharing caveat in
`handlers.md`. Run libcurl 8.21.0 or newer, or do not mix client-certificate
identities under one shared connection cache.

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
itself, so `proxyTunnelSignature()` returns a shared delegated-owner
sentinel —
**except** when a non-empty literal `Proxy-Authorization` header is present,
which always sections because libcurl can never key on an opaque request header
(§5).

`SOCKS_PROXY_CREDENTIAL_REUSE_VERSION = 7.69.0`. Below it, every SOCKS-proxied
request is sectioned by its credential state, because libcurl matched a SOCKS
proxy by type, host, and port only (curl #4835). At or above it the signature is
`null` — full delegation — since libcurl compares the parsed SOCKS
credentials itself and SOCKS has no literal-header channel it cannot key.

This proxy-credential floor (8.20.0) is **distinct** from the connection-cache
sharing floor (`CONNECTION_SHARING_VERSION = 8.12.0`, §3). The former gates how
Guzzle sections proxy tunnels; the latter gates whether persistent sharing puts
the connection cache on the share handle at all. They are deliberately
decoupled: the proxy hazard 8.20.0 addresses is mitigated by Guzzle regardless
of the connection floor, so it does not hold the connection floor up to 8.20.0.
The mechanism depends on whether a share handle is configured (§7): with no
share handle Guzzle sections the pool via `proxyTunnelSignature()`, while with a
configured share handle, which persistent sharing always uses, it instead forces
a fresh tunnel with `CURLOPT_FRESH_CONNECT` and `CURLOPT_FORBID_REUSE`, or
rejects the request under `PERSISTENT_REQUIRE`.

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

`testSocksProxyCredentialsChangeSocksProxySignatureOnAffectedCurlVersion`, the
SOCKS cases in `proxyTunnelSectionProvider`, and the scheme-less
`CURLOPT_PROXYTYPE` reflection test pin the SOCKS credential channels, the
credential-less sectioning, and the 7.69.0 delegation.

`testProxyTlsCredentialsRequireFreshConnectionOnAffectedCurlVersion` does the
same for the share-handle force-fresh path: it asserts
`requiresFreshConnectionForAuthenticatedProxy` forces a fresh tunnel for a proxy
TLS credential below 7.83.1 and not at or above it.

## 10. Hard rules (summary)

- Never compute a `null` signature for a credential-bearing proxy tunnel.
  Over-section freely; under-sectioning is the only way to leak.
- Always hash the proxy credentials and the non-empty literal
  `Proxy-Authorization` header; the non-empty header sections on **every**
  libcurl version.
- Use a non-`null` delegated sentinel for real proxy tunnels whose parsed proxy
  credentials, if any, are trusted to libcurl.
- Never trust libcurl `< 8.20` to distinguish proxy credentials itself.
- Section every SOCKS-proxied request below 7.69.0 by its credential state,
  including the credential-less state; from 7.69.0 libcurl compares SOCKS
  credentials itself and SOCKS requests are deliberately unsectioned.
- On the non-delegated signature path, key on the proxy private-key file and
  passphrase as fallback hardening (libcurl's mTLS private-key matching on reuse
  was incomplete below 8.21.0, CVE-2026-8932; the delegated path and configured
  share handles are not covered). The key blob and cert/key encoding are an
  accepted residual, not proven-safe.
- `usesProxyTunnel()` must treat `http://` + a non-empty `CURLOPT_CONNECT_TO` as
  a tunnel.
- Share the TLS session cache only from 8.6.0 and the connection cache only from
  8.12.0 (see §3 for why 8.12.0, not 8.20.0).
- Under a configured share handle, force a fresh tunnel for a proxy TLS
  credential (client cert / TLS-SRP) below 7.83.1, mirroring the signature path;
  the 7.83.1 gate keeps it below the version where `PERSISTENT_REQUIRE` would
  throw.

## References

**curl** — CVE-2026-3784 (proxy `CONNECT` credential reuse), CVE-2016-5420
(origin client-cert reuse), CVE-2022-27782 (TLS / TLS-SRP config not compared on
reuse), CVE-2024-0853 (client cert / OCSP on session reuse),
CVE-2026-6253/6429/7168 (proxy-credential leaks on reuse, fixed 8.20.0),
CVE-2026-8932 (incomplete mTLS private-key matching on reuse, fixed 8.21.0),
and curl issue #4835 (SOCKS proxy credentials not compared on connection reuse,
fixed 7.69.0); the 8.12.0 connection-cache floor also draws on curl's 8.12.0
session-cache rewrite
([curl PR #16245](https://github.com/curl/curl/pull/16245)). Source of record:
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
`socksProxySignature`, `usesProxyTunnel`, `isHttpProxyForConnectionReuse`),
`src/Handler/CurlVersion.php` (the version floors), and
[exception-guidelines.md](exception-guidelines.md) for exception types.
