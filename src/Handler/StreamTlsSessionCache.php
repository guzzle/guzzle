<?php

declare(strict_types=1);

namespace GuzzleHttp\Handler;

use GuzzleHttp\Exception\InvalidArgumentException;
use GuzzleHttp\HostIdentity;
use GuzzleHttp\NonSerializableTrait;
use GuzzleHttp\Psr7;
use Openssl\Session;

/**
 * Per-handler in-memory TLS session resumption cache for the stream handler.
 *
 * Backed by the OpenSSL TLS session API added in PHP 8.6. A session is only
 * ever replayed for an identical TLS identity: the non-secret identity is
 * encoded in the lookup key and secret material is matched in constant time.
 * TLS resumption reuses the original certificate-chain verification result
 * instead of building and verifying the chain again. Guzzle includes ambient
 * trust configuration paths in the cache identity, but cannot detect
 * trust-store contents rewritten at an unchanged path. Recreate the handler
 * or disable transport sharing when trust changes must take effect
 * immediately.
 *
 * New sessions are held temporarily and committed only after the HTTPS stream
 * opens successfully. If PHP rejects peer verification or another stream-open
 * step fails, any session reported during that attempt is discarded.
 *
 * @internal
 */
final class StreamTlsSessionCache
{
    use NonSerializableTrait;

    /**
     * Sessions retained per peer. libcurl uses 2 to buffer single-use TLS 1.3
     * tickets; the synchronous stream handler does not need more.
     */
    public const MAX_SESSIONS_PER_KEY = 2;

    /**
     * TLS 1.3 tickets should not live longer than RFC 8446 allows. All
     * pre-TLS-1.3 sessions use libcurl's tighter one-day cap.
     */
    private const MAX_TLS13_LIFETIME = 604800;
    private const MAX_PRE_TLS13_LIFETIME = 86400;

    private const USER_MANAGED_SESSION_OPTIONS = [
        'session_cache' => true,
        'session_cache_size' => true,
        'session_data' => true,
        'session_get_cb' => true,
        'session_id_context' => true,
        'session_new_cb' => true,
        'session_remove_cb' => true,
        'session_stream' => true,
        'session_timeout' => true,
    ];

    private const USER_MANAGED_PSK_OPTIONS = [
        'psk_client_cb' => true,
        'psk_server_cb' => true,
    ];

    /**
     * TLS 1.3 early data (0-RTT) options are deliberately never shared: an
     * injected cached session would let PHP send the replayable early data
     * payload ahead of the HTTP request.
     */
    private const USER_MANAGED_EARLY_DATA_OPTIONS = [
        'early_data' => true,
        'early_data_cb' => true,
        'max_early_data' => true,
    ];

    /**
     * File/path-bearing SSL context options are deliberately not shared: their
     * contents can change outside this handler.
     */
    private const PATH_OPTIONS = [
        'SNI_server_certs' => true,
        'cafile' => true,
        'capath' => true,
        'dh_param' => true,
        'local_cert' => true,
        'local_pk' => true,
    ];

    private const CERT_CAPTURE_OPTIONS = [
        'capture_peer_cert' => true,
        'capture_peer_cert_chain' => true,
    ];

    /**
     * Scalar custom SSL context options that are known to be self-contained
     * and safe to include in the TLS session cache identity.
     */
    private const CUSTOM_SCALAR_KEYABLE_OPTIONS = [
        'SNI_enabled' => true,
        'allow_self_signed' => true,
        'alpn_protocols' => true,
        'ciphers' => true,
        'crypto_method' => true,
        'disable_compression' => true,
        'max_proto_version' => true,
        'min_proto_version' => true,
        'peer_name' => true,
        'security_level' => true,
        'verify_depth' => true,
        'verify_peer' => true,
        'verify_peer_name' => true,
    ];

    private const INVALID_PEER_FINGERPRINT_REASON = 'the SSL context option "peer_fingerprint" must be a string or a non-empty, flat array with string algorithm names and string fingerprints.';

    private int $maxKeys;

    /**
     * @var array<string, list<array{session: Session, credentials: string, expiresAt: int, singleUse: bool}>>
     */
    private array $sessions = [];

    public function __construct(int $maxKeys)
    {
        if ($maxKeys < 1) {
            throw new InvalidArgumentException('maxKeys must be a positive integer.');
        }

        $this->maxKeys = $maxKeys;
    }

    public static function isSupported(): bool
    {
        // Any PHP 8.6 build qualifies, including pre-release and nightly
        // builds; the class check keeps unstable builds that do not carry
        // the final API fail-closed.
        return \PHP_VERSION_ID >= 80600 && \class_exists(Session::class, false);
    }

    /**
     * @param array $ssl       The assembled 'ssl' stream context array.
     * @param array $customSsl The user-supplied stream_context['ssl'] array.
     */
    public static function unsupportedContextReason(array $ssl, array $customSsl = []): ?string
    {
        foreach ($customSsl as $key => $value) {
            if (
                !isset(self::CUSTOM_SCALAR_KEYABLE_OPTIONS[$key])
                && !isset(self::PATH_OPTIONS[$key])
                && !isset(self::USER_MANAGED_SESSION_OPTIONS[$key])
                && !isset(self::USER_MANAGED_PSK_OPTIONS[$key])
                && !isset(self::USER_MANAGED_EARLY_DATA_OPTIONS[$key])
                && !isset(self::CERT_CAPTURE_OPTIONS[$key])
                && $key !== 'no_ticket'
                && $key !== 'peer_fingerprint'
            ) {
                return \sprintf('the custom SSL context option "%s" is not known to be safe for TLS session sharing.', Psr7\DiagnosticValue::escape((string) $key));
            }
        }

        foreach ($ssl as $key => $value) {
            if (isset(self::PATH_OPTIONS[$key])) {
                return \sprintf('the SSL context option "%s" uses file or path state that cannot be safely shared.', Psr7\DiagnosticValue::escape((string) $key));
            }

            if (isset(self::USER_MANAGED_SESSION_OPTIONS[$key])) {
                return \sprintf('the SSL context option "%s" is user-managed TLS session state.', Psr7\DiagnosticValue::escape((string) $key));
            }

            if (isset(self::USER_MANAGED_PSK_OPTIONS[$key])) {
                return \sprintf('the SSL context option "%s" is user-managed TLS PSK state.', Psr7\DiagnosticValue::escape((string) $key));
            }

            if (isset(self::USER_MANAGED_EARLY_DATA_OPTIONS[$key])) {
                return \sprintf('the SSL context option "%s" is user-managed TLS early data state.', Psr7\DiagnosticValue::escape((string) $key));
            }

            if (isset(self::CERT_CAPTURE_OPTIONS[$key]) && $value) {
                return \sprintf('the SSL context option "%s" requires a fresh peer certificate handshake.', Psr7\DiagnosticValue::escape((string) $key));
            }

            if ($key === 'no_ticket' && $value) {
                return 'the SSL context option "no_ticket" disables TLS ticket sharing.';
            }

            if ($key === 'peer_fingerprint') {
                if (!self::isPeerFingerprint($value)) {
                    return self::INVALID_PEER_FINGERPRINT_REASON;
                }

                continue;
            }

            if (!self::isCanonicalScalar($value)) {
                return \sprintf('the SSL context option "%s" cannot be safely included in the TLS session cache identity.', Psr7\DiagnosticValue::escape((string) $key));
            }
        }

        return null;
    }

    /**
     * Builds the non-secret lookup key: host/port plus every TLS parameter that
     * defines the verification/identity context. Keyed on the connection host
     * a transport reads, with numeric IPv4 spellings folded to one dotted
     * quad; names are never resolved. Secret material is excluded.
     *
     * @param array $ssl The assembled 'ssl' stream context array.
     */
    public static function peerKey(
        string $host,
        ?int $port,
        #[\SensitiveParameter]
        array $ssl
    ): string {
        $identity = [
            'schema' => 'guzzle-stream-tls-session-v1',
            'runtime' => self::runtimeIdentity(),
            'peer' => [
                'host' => self::canonicalHost($host),
                'port' => $port,
            ],
            'ssl' => self::canonicalPeerSslContext($ssl),
        ];

        return \hash('sha256', \serialize($identity));
    }

    /**
     * Builds the secret-aware credential fingerprint matched in constant time,
     * keeping the passphrase out of the loggable peer key.
     *
     * Every credential-bearing context is currently rejected by
     * unsupportedContextReason() before sharing, so fingerprints can only
     * diverge if the allow-lists are ever widened; the constant-time match is
     * retained as defense in depth for that case.
     *
     * @param array $ssl The assembled 'ssl' stream context array.
     */
    public static function credentialFingerprint(array $ssl): string
    {
        $material = [
            'local_cert' => isset($ssl['local_cert']) && \is_string($ssl['local_cert']) ? self::pathIdentity($ssl['local_cert']) : null,
            'local_pk' => isset($ssl['local_pk']) && \is_string($ssl['local_pk']) ? self::pathIdentity($ssl['local_pk']) : null,
            'passphrase' => isset($ssl['passphrase']) && \is_string($ssl['passphrase']) ? $ssl['passphrase'] : null,
        ];

        return \hash('sha256', \serialize($material));
    }

    public function find(string $key, string $credentials): ?Session
    {
        if (!isset($this->sessions[$key])) {
            return null;
        }

        $now = \time();
        $entries = $this->sessions[$key];
        $found = null;

        foreach ($entries as $i => $entry) {
            if ($entry['expiresAt'] <= $now) {
                unset($entries[$i]);
                continue;
            }

            if (!\hash_equals($entry['credentials'], $credentials)) {
                continue;
            }

            if (!$entry['session']->isResumable()) {
                unset($entries[$i]);
                continue;
            }

            $found = $entry['session'];

            // TLS 1.3 tickets are single-use; consume on take.
            if ($entry['singleUse']) {
                unset($entries[$i]);
            }

            break;
        }

        if ($entries === []) {
            unset($this->sessions[$key]);
        } else {
            $this->sessions[$key] = \array_values($entries);
            if ($found !== null) {
                $this->touch($key);
            }
        }

        return $found;
    }

    public function store(
        string $key,
        string $credentials,
        #[\SensitiveParameter]
        Session $session
    ): void {
        if (!$session->isResumable()) {
            return;
        }

        $expiresAt = self::expiry($session);
        if ($expiresAt === null) {
            return;
        }

        $list = $this->sessions[$key] ?? [];
        $list[] = [
            'session' => $session,
            'credentials' => $credentials,
            'expiresAt' => $expiresAt,
            'singleUse' => self::isTls13($session),
        ];

        if (\count($list) > self::MAX_SESSIONS_PER_KEY) {
            $list = \array_slice($list, -self::MAX_SESSIONS_PER_KEY);
        }

        $this->sessions[$key] = $list;
        $this->touch($key);
        $this->evictExcessKeys();
    }

    private function touch(string $key): void
    {
        if (!isset($this->sessions[$key])) {
            return;
        }

        $value = $this->sessions[$key];
        unset($this->sessions[$key]);
        $this->sessions[$key] = $value;
    }

    private function evictExcessKeys(): void
    {
        while (\count($this->sessions) > $this->maxKeys) {
            \array_shift($this->sessions);
        }
    }

    private static function isTls13(
        #[\SensitiveParameter]
        Session $session
    ): bool {
        $protocol = $session->getProtocol();

        return $protocol !== null && Psr7\Utils::caselessContains($protocol, '1.3');
    }

    /**
     * Returns the absolute expiry timestamp, or null when the session must
     * not be cached.
     */
    private static function expiry(
        #[\SensitiveParameter]
        Session $session
    ): ?int {
        return self::expiryFromLifetimes(
            self::isTls13($session),
            $session->hasTicket(),
            $session->getTicketLifetimeHint(),
            $session->getTimeout(),
            $session->getCreatedAt(),
            \time()
        );
    }

    /**
     * Decides the absolute expiry timestamp from a session's scalar lifetime
     * attributes, or null when the session must not be cached.
     *
     * A non-positive session timeout is never cached. TLS 1.3 resumption
     * requires a New Session Ticket, so a TLS 1.3 session without a ticket
     * or without a positive RFC 8446 ticket lifetime is not cached, and the
     * ticket lifetime bounds the expiry together with the session timeout
     * and the seven-day cap. Pre-TLS-1.3 tickets treat a zero or missing
     * lifetime hint as unspecified per RFC 5077, so only a positive hint
     * tightens the session timeout and the one-day cap. An expiry that has
     * already passed is not cached.
     */
    private static function expiryFromLifetimes(bool $isTls13, bool $hasTicket, ?int $ticketLifetimeHint, int $timeout, int $createdAt, int $now): ?int
    {
        if ($timeout <= 0) {
            return null;
        }

        $lifetime = \min($timeout, $isTls13 ? self::MAX_TLS13_LIFETIME : self::MAX_PRE_TLS13_LIFETIME);

        if ($isTls13) {
            if (!$hasTicket || $ticketLifetimeHint === null || $ticketLifetimeHint <= 0) {
                return null;
            }

            $lifetime = \min($lifetime, $ticketLifetimeHint);
        } elseif ($hasTicket && $ticketLifetimeHint !== null && $ticketLifetimeHint > 0) {
            $lifetime = \min($lifetime, $ticketLifetimeHint);
        }

        $expiresAt = $createdAt + $lifetime;

        return $expiresAt > $now ? $expiresAt : null;
    }

    private static function runtimeIdentity(): array
    {
        return [
            'php' => \PHP_VERSION_ID,
            'openssl' => \defined('OPENSSL_VERSION_NUMBER') ? \OPENSSL_VERSION_NUMBER : null,
            'trust' => [
                'openssl.cafile' => (string) \ini_get('openssl.cafile'),
                'openssl.capath' => (string) \ini_get('openssl.capath'),
                'SSL_CERT_FILE' => (string) \getenv('SSL_CERT_FILE'),
                'SSL_CERT_DIR' => (string) \getenv('SSL_CERT_DIR'),
                'cwd' => \getcwd(),
            ],
        ];
    }

    /**
     * Returns the host identity hashed into the cache key. Valid bracketed
     * IPv6 literals are canonicalized to their RFC 5952 form so equivalent
     * spellings of one address share a single session entry; other valid
     * hosts, such as reg-names and IPvFuture literals, fall back to ASCII
     * case folding. Text that is not a valid RFC 3986 host, such as
     * zone-bearing or malformed bracketed literals, must never become a
     * usable cache key, so it is rejected.
     */
    private static function canonicalHost(string $host): string
    {
        if (!Psr7\Rfc3986::isValidHost($host)) {
            throw new InvalidArgumentException('Hosts used in the TLS session cache identity must be valid RFC 3986 hosts.');
        }

        return HostIdentity::canonicalHost($host);
    }

    private static function canonicalPeerSslContext(
        #[\SensitiveParameter]
        array $ssl
    ): array {
        $context = [];

        foreach ($ssl as $key => $value) {
            if (isset(self::USER_MANAGED_SESSION_OPTIONS[$key]) || isset(self::USER_MANAGED_PSK_OPTIONS[$key]) || isset(self::USER_MANAGED_EARLY_DATA_OPTIONS[$key]) || $key === 'passphrase') {
                continue;
            }

            if ($key === 'peer_name' && \is_string($value)) {
                $context[$key] = ['string', self::canonicalHost($value)];

                continue;
            }

            if ($key === 'peer_fingerprint') {
                $context[$key] = self::canonicalPeerFingerprint($value);

                continue;
            }

            if (\in_array($key, ['cafile', 'capath', 'local_cert', 'local_pk'], true) && \is_string($value)) {
                $context[$key] = self::pathIdentity($value);

                continue;
            }

            $context[$key] = self::canonicalScalar($value);
        }

        \ksort($context);

        return $context;
    }

    private static function pathIdentity(string $path): array
    {
        $realPath = \realpath($path);

        return [
            'path',
            $realPath !== false ? $realPath : $path,
        ];
    }

    /**
     * @param mixed $value
     */
    private static function isCanonicalScalar($value): bool
    {
        return $value === null
            || \is_bool($value)
            || \is_int($value)
            || \is_float($value)
            || \is_string($value);
    }

    /**
     * @param mixed $value
     */
    private static function isPeerFingerprint($value): bool
    {
        if (\is_string($value)) {
            return true;
        }

        if (!\is_array($value) || $value === []) {
            return false;
        }

        foreach ($value as $algorithm => $fingerprint) {
            if (!\is_string($algorithm) || !\is_string($fingerprint)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param mixed $value
     *
     * @return array{0: string, 1: mixed}
     */
    private static function canonicalScalar($value): array
    {
        if ($value === null) {
            return ['null', null];
        }

        if (\is_bool($value)) {
            return ['bool', $value];
        }

        if (\is_int($value)) {
            return ['int', $value];
        }

        if (\is_float($value)) {
            return ['float', \bin2hex(\pack('E', $value))];
        }

        if (\is_string($value)) {
            return ['string', $value];
        }

        throw new InvalidArgumentException('SSL context values used in the TLS session cache identity must be scalar or null.');
    }

    /**
     * @param mixed $value
     *
     * @return array{0: string, 1: mixed}
     */
    private static function canonicalPeerFingerprint($value): array
    {
        if (!self::isPeerFingerprint($value)) {
            throw new InvalidArgumentException(self::INVALID_PEER_FINGERPRINT_REASON);
        }

        if (\is_string($value)) {
            return ['string', $value];
        }

        if (!\is_array($value)) {
            throw new InvalidArgumentException(self::INVALID_PEER_FINGERPRINT_REASON);
        }

        $items = [];
        foreach ($value as $algorithm => $fingerprint) {
            $items[$algorithm] = ['string', $fingerprint];
        }
        \ksort($items, \SORT_STRING);

        return ['map', $items];
    }
}
