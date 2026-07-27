<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests\Handler;

use GuzzleHttp\Exception\InvalidArgumentException;
use GuzzleHttp\Handler\StreamTlsSessionCache;
use PHPUnit\Framework\TestCase;

/**
 * Covers the pure, security-critical key derivation and context vetting, plus
 * PHP 8.6-gated coverage of the runtime cache using sessions captured from a
 * loopback TLS handshake.
 */
class StreamTlsSessionCacheTest extends TestCase
{
    public function testPhp86OpenSslProvidesSessionApi(): void
    {
        if (\PHP_VERSION_ID < 80600) {
            self::markTestSkipped('This test requires PHP 8.6+.');
        }

        if (!\extension_loaded('openssl')) {
            self::markTestSkipped('This test requires ext-openssl.');
        }

        self::assertTrue(
            \class_exists(\Openssl\Session::class, false),
            'PHP 8.6 with ext-openssl must expose Openssl\\Session.'
        );
        self::assertTrue(StreamTlsSessionCache::isSupported());
    }

    public function testPeerKeyIsStableForIdenticalConfig(): void
    {
        $ssl = ['peer_name' => 'example.com', 'verify_peer' => true, 'verify_peer_name' => true];

        self::assertSame(
            StreamTlsSessionCache::peerKey('example.com', 443, $ssl),
            StreamTlsSessionCache::peerKey('example.com', 443, $ssl)
        );
    }

    public function testPeerKeyIsCaseInsensitiveOnHost(): void
    {
        $ssl = ['peer_name' => 'example.com'];

        self::assertSame(
            StreamTlsSessionCache::peerKey('EXAMPLE.com', 443, $ssl),
            StreamTlsSessionCache::peerKey('example.COM', 443, $ssl)
        );
    }

    public function testPeerKeyCanonicalizesIpv6Hosts(): void
    {
        $ssl = ['peer_name' => '[2001:db8::1]'];

        self::assertSame(
            StreamTlsSessionCache::peerKey('[2001:0DB8:0:0:0:0:0:1]', 443, $ssl),
            StreamTlsSessionCache::peerKey('[2001:db8::1]', 443, $ssl)
        );
    }

    public function testPeerKeyCanonicalizesIpv6PeerNames(): void
    {
        self::assertSame(
            StreamTlsSessionCache::peerKey('[2001:db8::1]', 443, ['peer_name' => '[2001:0DB8:0:0:0:0:0:1]']),
            StreamTlsSessionCache::peerKey('[2001:db8::1]', 443, ['peer_name' => '[2001:db8::1]'])
        );
    }

    public function testPeerKeyCanonicalizesIpv6HostAndPeerNameTogether(): void
    {
        self::assertSame(
            StreamTlsSessionCache::peerKey('[2001:0DB8:0:0:0:0:0:1]', 443, ['peer_name' => '[2001:0DB8:0:0:0:0:0:1]']),
            StreamTlsSessionCache::peerKey('[2001:db8::1]', 443, ['peer_name' => '[2001:db8::1]'])
        );
    }

    public function testPeerKeyDiffersByIpv6Address(): void
    {
        self::assertNotSame(
            StreamTlsSessionCache::peerKey('[2001:db8::1]', 443, []),
            StreamTlsSessionCache::peerKey('[2001:db8::2]', 443, [])
        );
    }

    public function testPeerKeyFallsBackToCaseFoldingForIpvFutureLiterals(): void
    {
        self::assertSame(
            StreamTlsSessionCache::peerKey('[v1.AB]', 443, []),
            StreamTlsSessionCache::peerKey('[v1.ab]', 443, [])
        );
    }

    /**
     * Invalid host text must never become a usable cache key.
     *
     * @dataProvider invalidHostProvider
     */
    public function testPeerKeyRejectsInvalidHosts(string $host): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('valid RFC 3986 hosts');

        StreamTlsSessionCache::peerKey($host, 443, []);
    }

    /**
     * @dataProvider invalidHostProvider
     */
    public function testPeerKeyRejectsInvalidPeerNames(string $host): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('valid RFC 3986 hosts');

        StreamTlsSessionCache::peerKey('example.com', 443, ['peer_name' => $host]);
    }

    public static function invalidHostProvider(): iterable
    {
        yield 'zone-bearing ipv6 literal' => ['[fe80::1%25eth0]'];
        yield 'invalid bracketed literal' => ['[not-an-ip]'];
        yield 'missing closing bracket' => ['[2001:db8::1'];
        yield 'missing opening bracket' => ['2001:db8::1]'];
        yield 'unbracketed ipv6 literal' => ['2001:db8::1'];
        yield 'embedded whitespace' => ['exa mple.com'];
    }

    public function testPeerKeyDiffersByHostAndPort(): void
    {
        self::assertNotSame(
            StreamTlsSessionCache::peerKey('a.example.com', 443, []),
            StreamTlsSessionCache::peerKey('b.example.com', 443, [])
        );
        self::assertNotSame(
            StreamTlsSessionCache::peerKey('example.com', 443, []),
            StreamTlsSessionCache::peerKey('example.com', 8443, [])
        );
    }

    /**
     * Verification downgrade prevention: a verified and an unverified context
     * must never collide on the same key.
     */
    public function testPeerKeyDiffersWhenVerificationDisabled(): void
    {
        $verified = StreamTlsSessionCache::peerKey('example.com', 443, ['verify_peer' => true, 'verify_peer_name' => true]);
        $noPeer = StreamTlsSessionCache::peerKey('example.com', 443, ['verify_peer' => false, 'verify_peer_name' => true]);
        $noHost = StreamTlsSessionCache::peerKey('example.com', 443, ['verify_peer' => true, 'verify_peer_name' => false]);

        self::assertNotSame($verified, $noPeer);
        self::assertNotSame($verified, $noHost);
        self::assertNotSame($noPeer, $noHost);
    }

    public function testPeerKeyDiffersByPeerName(): void
    {
        self::assertNotSame(
            StreamTlsSessionCache::peerKey('example.com', 443, ['peer_name' => 'a.example.com']),
            StreamTlsSessionCache::peerKey('example.com', 443, ['peer_name' => 'b.example.com'])
        );
    }

    public function testPeerKeyDiffersWhenCaBundleDiffers(): void
    {
        $a = StreamTlsSessionCache::peerKey('example.com', 443, ['verify_peer' => true, 'cafile' => '/etc/ssl/a.pem']);
        $b = StreamTlsSessionCache::peerKey('example.com', 443, ['verify_peer' => true, 'cafile' => '/etc/ssl/b.pem']);

        self::assertNotSame($a, $b);
    }

    public function testPeerKeyDiffersByWorkingDirectoryForRelativeTrustPaths(): void
    {
        $cwd = \getcwd();
        self::assertNotFalse($cwd);
        $certFile = \getenv('SSL_CERT_FILE');
        $dirA = \sys_get_temp_dir().'/'.\uniqid('guzzle-trust-a', true);
        $dirB = \sys_get_temp_dir().'/'.\uniqid('guzzle-trust-b', true);

        try {
            foreach ([$dirA, $dirB] as $dir) {
                self::assertTrue(\mkdir($dir, 0700));
                self::assertNotFalse(\file_put_contents($dir.'/ca.pem', ''));
            }

            \putenv('SSL_CERT_FILE=ca.pem');

            \chdir($dirA);
            $a = StreamTlsSessionCache::peerKey('example.com', 443, ['verify_peer' => true]);
            \chdir($dirB);
            $b = StreamTlsSessionCache::peerKey('example.com', 443, ['verify_peer' => true]);

            self::assertNotSame($a, $b);
        } finally {
            \chdir($cwd);
            \putenv($certFile === false ? 'SSL_CERT_FILE' : 'SSL_CERT_FILE='.$certFile);
            foreach ([$dirA, $dirB] as $dir) {
                @\unlink($dir.'/ca.pem');
                @\rmdir($dir);
            }
        }
    }

    public function testPeerKeyDiffersByProtocolRangeAndCiphers(): void
    {
        $base = ['verify_peer' => true];

        self::assertNotSame(
            StreamTlsSessionCache::peerKey('example.com', 443, $base + ['min_proto_version' => 1]),
            StreamTlsSessionCache::peerKey('example.com', 443, $base + ['min_proto_version' => 2])
        );
        self::assertNotSame(
            StreamTlsSessionCache::peerKey('example.com', 443, $base + ['ciphers' => 'HIGH']),
            StreamTlsSessionCache::peerKey('example.com', 443, $base + ['ciphers' => 'LOW'])
        );
    }

    public function testPeerKeyDiffersByCryptoMethodSecurityAndAlpn(): void
    {
        self::assertNotSame(
            StreamTlsSessionCache::peerKey('example.com', 443, ['crypto_method' => \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT]),
            StreamTlsSessionCache::peerKey('example.com', 443, ['crypto_method' => \STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT])
        );
        self::assertNotSame(
            StreamTlsSessionCache::peerKey('example.com', 443, ['security_level' => 1]),
            StreamTlsSessionCache::peerKey('example.com', 443, ['security_level' => 2])
        );
        self::assertNotSame(
            StreamTlsSessionCache::peerKey('example.com', 443, ['alpn_protocols' => 'http/1.1']),
            StreamTlsSessionCache::peerKey('example.com', 443, ['alpn_protocols' => 'h2,http/1.1'])
        );
    }

    public function testPeerKeyDiffersBySniAndVerifyDepth(): void
    {
        self::assertNotSame(
            StreamTlsSessionCache::peerKey('example.com', 443, ['SNI_enabled' => true]),
            StreamTlsSessionCache::peerKey('example.com', 443, ['SNI_enabled' => false])
        );
        self::assertNotSame(
            StreamTlsSessionCache::peerKey('example.com', 443, ['verify_depth' => 3]),
            StreamTlsSessionCache::peerKey('example.com', 443, ['verify_depth' => 4])
        );
    }

    public function testPeerKeyHandlesNonFiniteFloatContextValues(): void
    {
        \set_error_handler(static function (int $severity, string $message): bool {
            throw new \ErrorException($message, 0, $severity);
        });

        try {
            $nan = StreamTlsSessionCache::peerKey(
                'example.com',
                443,
                ['security_level' => \NAN]
            );
            $nanAgain = StreamTlsSessionCache::peerKey(
                'example.com',
                443,
                ['security_level' => \NAN]
            );
            $inf = StreamTlsSessionCache::peerKey(
                'example.com',
                443,
                ['security_level' => \INF]
            );
            $negativeInf = StreamTlsSessionCache::peerKey(
                'example.com',
                443,
                ['security_level' => -\INF]
            );
        } finally {
            \restore_error_handler();
        }

        self::assertSame($nan, $nanAgain);
        self::assertNotSame($nan, $inf);
        self::assertNotSame($inf, $negativeInf);
    }

    /**
     * @dataProvider collidingTlsPolicyFloatProvider
     */
    public function testPeerKeyDistinguishesCollidingTlsPolicyFloats(string $option, float $first, float $second): void
    {
        $previousPrecision = (string) \ini_get('precision');

        try {
            \ini_set('precision', '14');

            self::assertSame((string) $first, (string) $second);
            self::assertNotSame((int) $first, (int) $second);
            self::assertNotSame(
                StreamTlsSessionCache::peerKey('example.com', 443, [$option => $first]),
                StreamTlsSessionCache::peerKey('example.com', 443, [$option => $second])
            );
        } finally {
            \ini_set('precision', $previousPrecision);
        }
    }

    public static function collidingTlsPolicyFloatProvider(): iterable
    {
        yield 'verify depth' => ['verify_depth', 1.9999999999999998, 2.0];
        yield 'security level' => ['security_level', 2.9999999999999996, 3.0];
    }

    public function testPeerKeyFloatIdentityIsIndependentOfPrecision(): void
    {
        $previousPrecision = (string) \ini_get('precision');
        $previousSerializePrecision = (string) \ini_get('serialize_precision');

        try {
            \ini_set('precision', '14');
            \ini_set('serialize_precision', '17');
            $normalPrecision = StreamTlsSessionCache::peerKey(
                'example.com',
                443,
                ['security_level' => 1.9]
            );

            \ini_set('precision', '1');
            \ini_set('serialize_precision', '1');
            $lowPrecision = StreamTlsSessionCache::peerKey(
                'example.com',
                443,
                ['security_level' => 1.9]
            );
            $differentValue = StreamTlsSessionCache::peerKey(
                'example.com',
                443,
                ['security_level' => 2.1]
            );

            self::assertSame($normalPrecision, $lowPrecision);
            self::assertNotSame($lowPrecision, $differentValue);
        } finally {
            \ini_set('precision', $previousPrecision);
            \ini_set('serialize_precision', $previousSerializePrecision);
        }
    }

    public function testPeerKeyUsesCanonicalHashInsteadOfDelimiterConcatenation(): void
    {
        self::assertNotSame(
            StreamTlsSessionCache::peerKey('example.com', 443, ['peer_name' => 'a|b', 'ciphers' => 'c']),
            StreamTlsSessionCache::peerKey('example.com', 443, ['peer_name' => 'a', 'ciphers' => 'b|c'])
        );
    }

    public function testPeerKeyDiffersByClientCertificate(): void
    {
        self::assertNotSame(
            StreamTlsSessionCache::peerKey('example.com', 443, ['local_cert' => '/a/client.pem']),
            StreamTlsSessionCache::peerKey('example.com', 443, ['local_cert' => '/b/client.pem'])
        );
    }

    public function testCredentialFingerprintIsHashedAndExcludesPlaintextSecret(): void
    {
        $fingerprint = StreamTlsSessionCache::credentialFingerprint(['passphrase' => 'super-secret']);

        self::assertDoesNotMatchRegularExpression('/super-secret/', $fingerprint);
        self::assertSame(64, \strlen($fingerprint), 'Expected a sha256 hex digest');
    }

    public function testPeerKeyExcludesPlaintextSecret(): void
    {
        $key = StreamTlsSessionCache::peerKey('example.com', 443, ['passphrase' => 'super-secret']);

        self::assertStringNotContainsString('super-secret', $key);
        self::assertSame(64, \strlen($key), 'Expected a sha256 hex digest');
    }

    public function testPeerKeyIsStableAcrossPassphrases(): void
    {
        self::assertSame(
            StreamTlsSessionCache::peerKey('example.com', 443, ['verify_peer' => true, 'passphrase' => 'one']),
            StreamTlsSessionCache::peerKey('example.com', 443, ['verify_peer' => true, 'passphrase' => 'two'])
        );
    }

    public function testCredentialFingerprintDiffersByPassphraseAndCert(): void
    {
        self::assertNotSame(
            StreamTlsSessionCache::credentialFingerprint(['passphrase' => 'one']),
            StreamTlsSessionCache::credentialFingerprint(['passphrase' => 'two'])
        );
        self::assertNotSame(
            StreamTlsSessionCache::credentialFingerprint(['local_pk' => '/a/key.pem']),
            StreamTlsSessionCache::credentialFingerprint(['local_pk' => '/b/key.pem'])
        );
    }

    public function testUnsupportedContextReasonRejectsUserManagedSessionOptions(): void
    {
        self::assertSame(
            'the SSL context option "session_new_cb" is user-managed TLS session state.',
            StreamTlsSessionCache::unsupportedContextReason(['session_new_cb' => static function (): void {
            }])
        );
    }

    public function testUnsupportedContextReasonRejectsUserManagedPskOptions(): void
    {
        self::assertSame(
            'the SSL context option "psk_client_cb" is user-managed TLS PSK state.',
            StreamTlsSessionCache::unsupportedContextReason(['psk_client_cb' => static function (): void {
            }])
        );
    }

    /**
     * @dataProvider pathSslOptionProvider
     */
    public function testUnsupportedContextReasonRejectsFinalPathOptions(string $option, $value): void
    {
        self::assertSame(
            \sprintf('the SSL context option "%s" uses file or path state that cannot be safely shared.', $option),
            StreamTlsSessionCache::unsupportedContextReason([$option => $value])
        );
    }

    public static function pathSslOptionProvider(): iterable
    {
        yield 'SNI_server_certs' => ['SNI_server_certs', ['example.com' => '/tmp/server.pem']];
        yield 'cafile' => ['cafile', '/tmp/ca.pem'];
        yield 'capath' => ['capath', '/tmp/certs'];
        yield 'dh_param' => ['dh_param', '/tmp/dh.pem'];
        yield 'local_cert' => ['local_cert', '/tmp/client.pem'];
        yield 'local_pk' => ['local_pk', '/tmp/key.pem'];
    }

    public function testUnsupportedContextReasonRejectsCertificateCapture(): void
    {
        self::assertSame(
            'the SSL context option "capture_peer_cert" requires a fresh peer certificate handshake.',
            StreamTlsSessionCache::unsupportedContextReason(['capture_peer_cert' => true])
        );
        self::assertSame(
            'the SSL context option "capture_peer_cert_chain" requires a fresh peer certificate handshake.',
            StreamTlsSessionCache::unsupportedContextReason(['capture_peer_cert_chain' => true])
        );
        self::assertNull(StreamTlsSessionCache::unsupportedContextReason(['capture_peer_cert' => false]));
    }

    public function testUnsupportedContextReasonRejectsDisabledTickets(): void
    {
        self::assertSame(
            'the SSL context option "no_ticket" disables TLS ticket sharing.',
            StreamTlsSessionCache::unsupportedContextReason(['no_ticket' => true])
        );
        self::assertNull(StreamTlsSessionCache::unsupportedContextReason(['no_ticket' => false]));
    }

    public function testUnsupportedContextReasonAllowsCustomScalarOptions(): void
    {
        self::assertNull(StreamTlsSessionCache::unsupportedContextReason(
            ['peer_name' => 'example.com', 'security_level' => 2],
            ['security_level' => 2]
        ));
    }

    public function testUnsupportedContextReasonAllowsPeerFingerprints(): void
    {
        self::assertNull(StreamTlsSessionCache::unsupportedContextReason([
            'peer_fingerprint' => \str_repeat('a', 40),
        ]));
        self::assertNull(StreamTlsSessionCache::unsupportedContextReason([
            'peer_fingerprint' => [
                'sha256' => \str_repeat('a', 64),
                'sha1' => \str_repeat('b', 40),
            ],
        ]));
    }

    public function testPeerFingerprintMapOrderDoesNotChangePeerKey(): void
    {
        self::assertSame(
            StreamTlsSessionCache::peerKey('example.com', 443, [
                'peer_fingerprint' => ['sha256' => 'a', 'sha1' => 'b'],
            ]),
            StreamTlsSessionCache::peerKey('example.com', 443, [
                'peer_fingerprint' => ['sha1' => 'b', 'sha256' => 'a'],
            ])
        );
        self::assertNotSame(
            StreamTlsSessionCache::peerKey('example.com', 443, [
                'peer_fingerprint' => ['sha256' => 'a'],
            ]),
            StreamTlsSessionCache::peerKey('example.com', 443, [
                'peer_fingerprint' => ['sha256' => 'b'],
            ])
        );
    }

    /**
     * @dataProvider invalidPeerFingerprintProvider
     *
     * @param mixed $value
     */
    public function testUnsupportedContextReasonRejectsInvalidPeerFingerprint($value): void
    {
        self::assertSame(
            'the SSL context option "peer_fingerprint" must be a string or a non-empty, flat array with string algorithm names and string fingerprints.',
            StreamTlsSessionCache::unsupportedContextReason([
                'peer_fingerprint' => $value,
            ])
        );
    }

    public static function invalidPeerFingerprintProvider(): iterable
    {
        yield 'integer' => [1];
        yield 'empty array' => [[]];
        yield 'numeric key' => [[0 => 'fingerprint']];
        yield 'non-string value' => [['sha256' => 1]];
        yield 'nested value' => [['sha256' => ['fingerprint']]];
    }

    public function testRecursivePeerFingerprintIsRejected(): void
    {
        $fingerprint = [];
        $fingerprint['sha256'] = &$fingerprint;

        self::assertSame(
            'the SSL context option "peer_fingerprint" must be a string or a non-empty, flat array with string algorithm names and string fingerprints.',
            StreamTlsSessionCache::unsupportedContextReason([
                'peer_fingerprint' => $fingerprint,
            ])
        );

        $this->expectException(InvalidArgumentException::class);
        StreamTlsSessionCache::peerKey(
            'example.com',
            443,
            ['peer_fingerprint' => $fingerprint]
        );
    }

    public function testRecursiveScalarOptionIsRejected(): void
    {
        $securityLevel = [];
        $securityLevel[] = &$securityLevel;

        self::assertSame(
            'the SSL context option "security_level" cannot be safely included in the TLS session cache identity.',
            StreamTlsSessionCache::unsupportedContextReason([
                'security_level' => $securityLevel,
            ])
        );

        $this->expectException(InvalidArgumentException::class);
        StreamTlsSessionCache::peerKey(
            'example.com',
            443,
            ['security_level' => $securityLevel]
        );
    }

    public function testUnsupportedContextReasonRejectsUnknownCustomSslOptions(): void
    {
        self::assertSame(
            'the custom SSL context option "future_\\x00path\\xFF_option" is not known to be safe for TLS session sharing.',
            StreamTlsSessionCache::unsupportedContextReason(
                ["future_\x00path\xFF_option" => '/tmp/file'],
                ["future_\x00path\xFF_option" => '/tmp/file']
            )
        );
    }

    public function testUnsupportedContextReasonRejectsOpaqueValues(): void
    {
        self::assertSame(
            'the SSL context option "opaque" cannot be safely included in the TLS session cache identity.',
            StreamTlsSessionCache::unsupportedContextReason(['opaque' => new \stdClass()])
        );
    }

    /**
     * @dataProvider expiryFromLifetimesProvider
     */
    public function testExpiryFromLifetimes(?int $expected, bool $isTls13, bool $hasTicket, ?int $ticketLifetimeHint, int $timeout, int $createdAt, int $now): void
    {
        $method = new \ReflectionMethod(StreamTlsSessionCache::class, 'expiryFromLifetimes');
        if (\PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }

        self::assertSame($expected, $method->invoke(null, $isTls13, $hasTicket, $ticketLifetimeHint, $timeout, $createdAt, $now));
    }

    public static function expiryFromLifetimesProvider(): iterable
    {
        yield 'tls 1.3 hint shorter than timeout' => [1100, true, true, 100, 300, 1000, 1000];
        yield 'tls 1.3 timeout shorter than hint' => [1100, true, true, 300, 100, 1000, 1000];
        yield 'tls 1.3 hint and timeout beyond the seven-day cap' => [605800, true, true, 700000, 800000, 1000, 1000];
        yield 'tls 1.3 zero hint' => [null, true, true, 0, 300, 1000, 1000];
        yield 'tls 1.3 negative hint' => [null, true, true, -1, 300, 1000, 1000];
        yield 'tls 1.3 missing hint' => [null, true, true, null, 300, 1000, 1000];
        yield 'tls 1.3 missing ticket' => [null, true, false, 300, 300, 1000, 1000];
        yield 'tls 1.2 ticket with shorter positive hint' => [1100, false, true, 100, 300, 1000, 1000];
        yield 'tls 1.2 ticket with longer positive hint' => [1300, false, true, 500, 300, 1000, 1000];
        yield 'tls 1.2 ticket with unspecified zero hint' => [1300, false, true, 0, 300, 1000, 1000];
        yield 'tls 1.2 ticket beyond the one-day cap' => [87400, false, true, 100000, 200000, 1000, 1000];
        yield 'tls 1.2 session id without ticket' => [1300, false, false, null, 300, 1000, 1000];
        yield 'zero timeout' => [null, false, false, null, 0, 1000, 1000];
        yield 'negative timeout' => [null, false, false, null, -1, 1000, 1000];
        yield 'expiry exactly now' => [null, false, false, null, 300, 1000, 1300];
        yield 'already expired' => [null, false, false, null, 300, 1000, 2000];
    }

    public function testStoreAndFindRoundTripsCapturedSession(): void
    {
        $session = LoopbackTlsSession::capture(\STREAM_CRYPTO_METHOD_TLS_CLIENT, \STREAM_CRYPTO_METHOD_TLS_SERVER);

        $cache = new StreamTlsSessionCache(4);
        $cache->store('peer-key', 'credentials', $session);

        self::assertSame($session, $cache->find('peer-key', 'credentials'));
    }

    public function testFindRequiresMatchingCredentials(): void
    {
        $session = LoopbackTlsSession::capture(\STREAM_CRYPTO_METHOD_TLS_CLIENT, \STREAM_CRYPTO_METHOD_TLS_SERVER);

        $cache = new StreamTlsSessionCache(4);
        $cache->store('peer-key', 'credentials-a', $session);

        self::assertNull($cache->find('peer-key', 'credentials-b'));
        self::assertSame($session, $cache->find('peer-key', 'credentials-a'));
    }

    public function testFindConsumesSingleUseTls13SessionOnTake(): void
    {
        $session = LoopbackTlsSession::capture(\STREAM_CRYPTO_METHOD_TLS_CLIENT, \STREAM_CRYPTO_METHOD_TLS_SERVER);
        if ($session->getProtocol() !== 'TLSv1.3') {
            self::markTestSkipped('This test requires a TLS 1.3 session.');
        }

        $cache = new StreamTlsSessionCache(4);
        $cache->store('peer-key', 'credentials', $session);

        self::assertSame($session, $cache->find('peer-key', 'credentials'));
        self::assertNull($cache->find('peer-key', 'credentials'));
    }

    public function testStoreRetainsAtMostTwoSessionsPerKey(): void
    {
        $session = LoopbackTlsSession::capture(\STREAM_CRYPTO_METHOD_TLS_CLIENT, \STREAM_CRYPTO_METHOD_TLS_SERVER);
        if ($session->getProtocol() !== 'TLSv1.3') {
            self::markTestSkipped('This test requires a TLS 1.3 session.');
        }

        $cache = new StreamTlsSessionCache(4);
        $cache->store('peer-key', 'credentials', $session);
        $cache->store('peer-key', 'credentials', $session);
        $cache->store('peer-key', 'credentials', $session);

        // Single-use entries are consumed on take, so only two survive the cap.
        self::assertSame($session, $cache->find('peer-key', 'credentials'));
        self::assertSame($session, $cache->find('peer-key', 'credentials'));
        self::assertNull($cache->find('peer-key', 'credentials'));
    }

    public function testEvictsLeastRecentlyUsedKeyBeyondCapacity(): void
    {
        $session = LoopbackTlsSession::capture(\STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT, \STREAM_CRYPTO_METHOD_TLSv1_2_SERVER);
        if ($session->getProtocol() !== 'TLSv1.2') {
            self::markTestSkipped('This test requires a TLS 1.2 session.');
        }

        $cache = new StreamTlsSessionCache(2);
        $cache->store('peer-a', 'credentials', $session);
        $cache->store('peer-b', 'credentials', $session);

        self::assertSame($session, $cache->find('peer-a', 'credentials'));

        $cache->store('peer-c', 'credentials', $session);

        self::assertNull($cache->find('peer-b', 'credentials'));
        self::assertSame($session, $cache->find('peer-a', 'credentials'));
        self::assertSame($session, $cache->find('peer-c', 'credentials'));
    }
}
