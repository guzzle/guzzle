# Stream TLS session sharing (contributor reference)

On PHP 8.6+, `StreamHandler` can resume TLS sessions across requests using
the OpenSSL session API. Unlike the cURL share machinery, nothing else is
shared: there is no connection pool and no DNS cache, only TLS sessions,
which can reduce handshake latency and reuse the original certificate-chain
verification result. That reuse is exactly why the cache identity is
security-critical: a session must only be replayed for a peer and
verification context identical to the one that produced it. This
document explains the identity model and cache lifecycle in
`StreamTlsSessionCache`, and records the configuration-identity policy the
stream and cURL transports share. The feature and this reference exist on
8.1+ only. For the cURL side - connection reuse, proxy-tunnel signatures,
and CURLSH sharing - see
[curl-connection-reuse.md](curl-connection-reuse.md).

## 1. The configuration-identity policy

Both transport families reduce connection configuration to hashed
identities: the cURL handlers hash proxy identity into the tunnel
signatures described in the companion document, and the stream handler
hashes the TLS verification context into its session-cache key. The same
rules govern both, and changes to either should preserve them.

**Hash literal configuration, never contents.** Identities cover the
configured values themselves, not what those values point at. Rewriting a
trust store at an unchanged path is deliberately undetected; that matches
libcurl and is disclosed in the handler documentation.

**Canonicalize paths and represent ambient state.** Path identities use
the resolved path when `realpath()` succeeds and retain the literal
spelling otherwise, and ambient inputs that change what a literal value
means are part of the identity. The runtime identity includes the
working directory because a relative ambient trust path such as
`openssl.cafile=ca.pem` resolves against it; curl 8.21.0's
`cf_ssl_peer_key_add_path()` is the precedent.

**Fail closed on anything non-canonical.** A value that cannot be
represented canonically excludes the whole context from sharing. It must
never be silently dropped from the key: a key that omits an input equates
two different verification contexts.

**Commit identity-carrying state only after verification.** A session is
stored only once the stream that produced it has opened successfully and
passed peer verification.

**Follow libcurl precedent on divergence.** Where the stream and cURL
models could differ - lifetime caps, per-peer session counts, what is
hashed - libcurl's choice wins unless a comment documents why not.

## 2. The lookup key and the credential fingerprint

`StreamTlsSessionCache::peerKey()` builds the non-secret lookup key: a
SHA-256 over a versioned schema tag, the runtime identity, the canonical
connection host and port, and the canonicalized `ssl` context array. The
key contains no secret material, so it is safe to log.

The runtime identity pins the PHP and OpenSSL versions and the ambient
trust configuration: the `openssl.cafile` and `openssl.capath` ini values,
the `SSL_CERT_FILE` and `SSL_CERT_DIR` environment values, and the working
directory that relative spellings of those paths resolve against.

Context canonicalization is type-aware so no two different configurations
serialize identically. The connection host and `peer_name` fold through
the transport's canonical-host rules, `peer_fingerprint` is normalized
structurally, path-valued options go through `realpath()` as defense in
depth (path-backed contexts are rejected before sharing starts), and every
remaining value must be a canonical scalar. The array is key-sorted before
hashing, and `passphrase` never enters the key.

`credentialFingerprint()` is the secret-aware companion, compared with
`hash_equals()` at lookup time. Every credential-bearing context is
currently rejected before sharing, so fingerprints can only diverge if the
allow-lists are widened later; the constant-time match is retained as
defense in depth for that case.

## 3. What is rejected, and the extension point

`unsupportedContextReason()` vets the assembled `ssl` context and the
user-supplied `stream_context['ssl']` overrides before any sharing starts,
and produces a diagnostic reason for the rejection. It rejects:

- file- and path-backed options (`cafile`, `capath`, `local_cert`,
  `local_pk`, `dh_param`, `SNI_server_certs`), whose contents can change
  outside the handler;
- user-managed session state (`session_*`) and PSK callbacks, whose
  lifecycle belongs to the caller;
- enabled certificate-capture options, which ask for a fresh peer
  handshake that resumption would skip;
- an enabled `no_ticket`;
- malformed `peer_fingerprint` values; and
- any custom option outside `CUSTOM_SCALAR_KEYABLE_OPTIONS`, and any value
  that is not a canonical scalar.

The last rule is the extension point contributors actually hit: a new
scalar `ssl` context option stays rejected until it is added to
`CUSTOM_SCALAR_KEYABLE_OPTIONS`, an explicit decision that the option is
self-contained and partitions the identity correctly. That default is
intentional. An unknown option that influenced verification while staying
out of the key would poison the cache; rejecting it merely costs a
resumption.

## 4. Session lifecycle

Sessions are staged, not stored, during a handshake. The handler installs
a `session_new_cb` whose staging state is local to the request invocation,
so there is no handler-level pending slot for concurrent or nested calls
to overwrite. Staged sessions are committed only after the HTTPS stream
opens successfully; when peer verification or any other stream-open step
fails, they are discarded with the failure.

`find()` enforces the boundary again at lookup: expired entries are
dropped, credentials are compared in constant time, the session must still
report `isResumable()`, and Guzzle treats TLS 1.3 tickets as single-use,
following RFC 8446's recommendation, so a matching ticket is consumed on
take rather than replayed concurrently.

`store()` accepts only resumable sessions with a derivable expiry, bounded
by the session's own lifetime and capped at seven days for TLS 1.3 (RFC
8446) and one day otherwise (libcurl's cap). Each peer keeps at most
`MAX_SESSIONS_PER_KEY` (2) sessions, enough to buffer single-use TLS 1.3
tickets, matching libcurl; the handler caps the cache at
`TLS_SESSION_CACHE_MAX_KEYS` (25) peer keys with least-recently-used
eviction. The cache is per-handler, in-memory only, and deliberately not
serializable.
