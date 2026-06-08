<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests\Auth;

use GuzzleHttp\Auth\DigestAuth;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class DigestAuthTest extends TestCase
{
    /**
     * @dataProvider algorithmProvider
     */
    public function testGeneratesDigestAuthorizationHeader(string $algorithm, string $hashAlgorithm, string $expectedResponse): void
    {
        if (!\in_array($hashAlgorithm, \hash_algos(), true)) {
            self::markTestSkipped(\sprintf('Hash algorithm for %s is not available.', $algorithm));
        }

        $challenge = DigestAuth::selectChallenge(new Response(401, [
            'WWW-Authenticate' => \sprintf(
                'Digest realm="testrealm@host.com", nonce="dcd98b7102dd2f0e8b11d0f600bfb0c093", qop="auth", algorithm=%s',
                $algorithm
            ),
        ]));

        self::assertNotNull($challenge);

        $header = DigestAuth::authorizationHeader(
            new Request('GET', 'http://example.com/dir/index.html'),
            $challenge,
            'Mufasa',
            'Circle Of Life',
            '0a4f113b'
        );

        self::assertNotNull($header);
        self::assertStringContainsString('response="'.$expectedResponse.'"', $header);
        self::assertStringContainsString('qop=auth', $header);
        self::assertStringContainsString('nc=00000001', $header);
        self::assertStringContainsString('cnonce="0a4f113b"', $header);
    }

    public static function algorithmProvider(): iterable
    {
        yield 'MD5' => ['MD5', 'md5', '6629fae49393a05397450978507c4ef1'];
        yield 'MD5-sess' => ['MD5-sess', 'md5', '8e3825c57e897f5a0dec6c2d4e5059d0'];
        yield 'SHA-256' => ['SHA-256', 'sha256', '5abdd07184ba512a22c53f41470e5eea7dcaa3a93a59b630c13dfe0a5dc6e38b'];
        yield 'SHA-256-sess' => ['SHA-256-sess', 'sha256', 'b8822e12417cb7750f4e2b8515f0dcf25b7dd26993e80bee1426201446a7f59b'];
        yield 'SHA-512-256' => ['SHA-512-256', 'sha512/256', 'f23c08ec7334a881f8286e68450ddbd9f0cd91c41481f0e1433604da8113c6dc'];
        yield 'SHA-512-256-sess' => ['SHA-512-256-sess', 'sha512/256', '0d21f0db3ec5cda5b850c0afa3bc29b4a3c5a6191959ff1baf511d4b38eb6b1e'];
    }

    public function testGeneratesLegacyDigestAuthorizationHeaderWithoutQop(): void
    {
        $challenge = DigestAuth::selectChallenge(new Response(401, [
            'WWW-Authenticate' => 'Digest realm="testrealm@host.com", nonce="dcd98b7102dd2f0e8b11d0f600bfb0c093"',
        ]));

        self::assertNotNull($challenge);

        $header = DigestAuth::authorizationHeader(
            new Request('GET', 'http://example.com/dir/index.html'),
            $challenge,
            'Mufasa',
            'Circle Of Life',
            '0a4f113b'
        );

        self::assertNotNull($header);
        self::assertStringContainsString('response="670fd8c2df070c60b045671b8b24ff02"', $header);
        self::assertStringNotContainsString('qop=', $header);
        self::assertStringNotContainsString('nc=', $header);
        self::assertStringNotContainsString('cnonce=', $header);
    }

    public function testParsesQuotedCommasAndEscapes(): void
    {
        $challenge = DigestAuth::selectChallenge(new Response(401, [
            'WWW-Authenticate' => 'Basic realm="basic", Digest realm="a,b\"c", nonce="n", qop="auth,auth-int"',
        ]));

        self::assertNotNull($challenge);
        self::assertSame('a,b"c', $challenge->realm);
        self::assertSame('auth', $challenge->qop);
    }

    public function testSkipsInvalidChallengeAndUsesLaterValidChallenge(): void
    {
        $challenge = DigestAuth::selectChallenge(new Response(401, [
            'WWW-Authenticate' => 'Digest realm="bad", nonce="one", nonce="two", Digest realm="good", nonce="three"',
        ]));

        self::assertNotNull($challenge);
        self::assertSame('good', $challenge->realm);
        self::assertSame('three', $challenge->nonce);
    }

    public function testAuthIntOnlyChallengeIsUnsupported(): void
    {
        $challenge = DigestAuth::selectChallenge(new Response(401, [
            'WWW-Authenticate' => 'Digest realm="test", nonce="abc", qop="auth-int"',
        ]));

        self::assertNull($challenge);
    }
}
