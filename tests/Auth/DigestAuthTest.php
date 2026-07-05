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

    /**
     * @dataProvider token68ChallengeProvider
     */
    public function testSkipsToken68ChallengeAndUsesLaterDigest(string $prefix): void
    {
        $challenge = DigestAuth::selectChallenge(new Response(401, [
            'WWW-Authenticate' => $prefix.', Digest realm="good", nonce="n", qop="auth"',
        ]));

        self::assertNotNull($challenge);
        self::assertSame('good', $challenge->realm);
        self::assertSame('n', $challenge->nonce);
        self::assertSame('auth', $challenge->qop);
    }

    public static function token68ChallengeProvider(): iterable
    {
        yield 'basic padding' => ['Basic dGVzdA=='];
        yield 'negotiate slash padding' => ['Negotiate abc/def+ghi=='];
        yield 'unknown token68 chars' => ['Newauth a+b/c_~=='];
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

    public function testMissingParameterSeparatorInvalidatesChallenge(): void
    {
        $challenge = DigestAuth::selectChallenge(new Response(401, [
            'WWW-Authenticate' => 'Digest realm="test", nonce="abc" qop="auth"',
        ]));

        self::assertNull($challenge);
    }

    public function testTrailingGarbageInvalidatesChallenge(): void
    {
        $challenge = DigestAuth::selectChallenge(new Response(401, [
            'WWW-Authenticate' => 'Digest realm="test", nonce="abc", qop="auth"; charset=utf-8',
        ]));

        self::assertNull($challenge);
    }

    public function testMalformedChallengeInOneHeaderDoesNotAffectOtherHeader(): void
    {
        $challenge = DigestAuth::selectChallenge(new Response(401, [
            'WWW-Authenticate' => [
                'Digest realm="bad", nonce="one" qop="auth"',
                'Digest realm="good", nonce="two", qop="auth"',
            ],
        ]));

        self::assertNotNull($challenge);
        self::assertSame('good', $challenge->realm);
        self::assertSame('two', $challenge->nonce);
    }

    public function testGarbageParameterNameInvalidatesChallenge(): void
    {
        $challenge = DigestAuth::selectChallenge(new Response(401, [
            'WWW-Authenticate' => 'Digest realm="r", nonce="n", ="x"',
        ]));

        self::assertNull($challenge);
    }

    public function testTrailingCommaAndWhitespaceStaysValid(): void
    {
        $challenges = DigestAuth::parseAuthenticateHeader("Digest realm=\"r\", nonce=\"n\", qop=\"auth\", \t");

        self::assertCount(1, $challenges);
        self::assertSame('digest', $challenges[0]['scheme']);
        self::assertFalse($challenges[0]['invalid']);
        self::assertSame('auth', $challenges[0]['params']['qop']);
    }

    public function testKnownDigestParameterWithoutValueInvalidatesChallenge(): void
    {
        $challenge = DigestAuth::selectChallenge(new Response(401, [
            'WWW-Authenticate' => 'Digest realm="test", nonce="abc", qop',
        ]));

        self::assertNull($challenge);
    }

    public function testKnownDigestParameterWithoutValueDoesNotHideLaterDigestInSameHeader(): void
    {
        $challenge = DigestAuth::selectChallenge(new Response(401, [
            'WWW-Authenticate' => 'Digest realm="bad", nonce="one", qop, Digest realm="good", nonce="two", qop="auth"',
        ]));

        self::assertNotNull($challenge);
        self::assertSame('good', $challenge->realm);
        self::assertSame('two', $challenge->nonce);
        self::assertSame('auth', $challenge->qop);
    }

    public function testTrailingEmptyParameterElementsRemainAccepted(): void
    {
        $challenge = DigestAuth::selectChallenge(new Response(401, [
            'WWW-Authenticate' => 'Digest realm="test", nonce="abc", qop="auth", , ',
        ]));

        self::assertNotNull($challenge);
        self::assertSame('abc', $challenge->nonce);
        self::assertSame('auth', $challenge->qop);
    }

    public function testEmptyParameterValueInvalidatesChallenge(): void
    {
        $challenge = DigestAuth::selectChallenge(new Response(401, [
            'WWW-Authenticate' => 'Digest realm="r", nonce="n", qop="auth", opaque=',
        ]));

        self::assertNull($challenge);
    }

    /**
     * @dataProvider nonDigestChallengeAfterDigestProvider
     */
    public function testNonDigestChallengeAfterDigestDoesNotInvalidateDigest(string $suffix): void
    {
        $challenge = DigestAuth::selectChallenge(new Response(401, [
            'WWW-Authenticate' => 'Digest realm="test", nonce="abc"'.$suffix,
        ]));

        self::assertNotNull($challenge);
        self::assertSame('test', $challenge->realm);
        self::assertSame('abc', $challenge->nonce);
    }

    public static function nonDigestChallengeAfterDigestProvider(): iterable
    {
        yield 'basic challenge' => [', Basic realm="basic"'];
        yield 'unknown auth-param challenge' => [', Newauth realm="apps", type=1'];
        yield 'unknown token68 challenge' => [', Newauth abc/def+ghi=='];
        yield 'unknown bare challenge' => [', Newauth'];
    }

    public function testAuthIntOnlyChallengeIsUnsupported(): void
    {
        $challenge = DigestAuth::selectChallenge(new Response(401, [
            'WWW-Authenticate' => 'Digest realm="test", nonce="abc", qop="auth-int"',
        ]));

        self::assertNull($challenge);
    }

    /**
     * @dataProvider sessAlgorithmProvider
     */
    public function testSessAlgorithmWithoutQopIsUnsupported(string $algorithm): void
    {
        $challenge = DigestAuth::selectChallenge(new Response(401, [
            'WWW-Authenticate' => \sprintf('Digest realm="r", nonce="n", algorithm=%s', $algorithm),
        ]));

        self::assertNull($challenge);
    }

    public static function sessAlgorithmProvider(): iterable
    {
        yield 'MD5-sess' => ['MD5-sess'];
        yield 'SHA-256-sess' => ['SHA-256-sess'];
        yield 'SHA-512-256-sess' => ['SHA-512-256-sess'];
    }

    public function testSkipsSessWithoutQopAndUsesLaterValidDigest(): void
    {
        $challenge = DigestAuth::selectChallenge(new Response(401, [
            'WWW-Authenticate' => 'Digest realm="bad", nonce="one", algorithm=MD5-sess, Digest realm="good", nonce="two", algorithm=MD5',
        ]));

        self::assertNotNull($challenge);
        self::assertSame('good', $challenge->realm);
        self::assertSame('two', $challenge->nonce);
    }

    public function testRejectsHeaderUnsafeUsernameAndCnonce(): void
    {
        $challenge = DigestAuth::selectChallenge(new Response(401, [
            'WWW-Authenticate' => 'Digest realm="test", nonce="abc", qop="auth"',
        ]));

        self::assertNotNull($challenge);
        self::assertNull(DigestAuth::authorizationHeader(
            new Request('GET', 'http://example.com/'),
            $challenge,
            "bad\x01user",
            'b',
            'cnonce'
        ));
        self::assertNull(DigestAuth::authorizationHeader(
            new Request('GET', 'http://example.com/'),
            $challenge,
            'a',
            'b',
            "bad\x7Fcnonce"
        ));
    }

    public function testRejectsHeaderUnsafeChallengeValues(): void
    {
        $challenge = DigestAuth::selectChallenge(new Response(401, [
            'WWW-Authenticate' => 'Digest realm="test", nonce="abc", qop="auth", opaque="safe"',
        ]));

        self::assertNotNull($challenge);

        $request = new Request('GET', 'http://example.com/');

        $unsafeRealm = clone $challenge;
        $unsafeRealm->realm = "bad\x01realm";
        self::assertNull(DigestAuth::authorizationHeader($request, $unsafeRealm, 'a', 'b', 'cnonce'));

        $unsafeNonce = clone $challenge;
        $unsafeNonce->nonce = "bad\x7Fnonce";
        self::assertNull(DigestAuth::authorizationHeader($request, $unsafeNonce, 'a', 'b', 'cnonce'));

        $unsafeOpaque = clone $challenge;
        $unsafeOpaque->opaque = "bad\x1Fopaque";
        self::assertNull(DigestAuth::authorizationHeader($request, $unsafeOpaque, 'a', 'b', 'cnonce'));
    }

    public function testRejectsUnsafeNonceCount(): void
    {
        $challenge = DigestAuth::selectChallenge(new Response(401, [
            'WWW-Authenticate' => 'Digest realm="test", nonce="abc", qop="auth"',
        ]));

        self::assertNotNull($challenge);
        self::assertNull(DigestAuth::authorizationHeader(
            new Request('GET', 'http://example.com/'),
            $challenge,
            'a',
            'b',
            'cnonce',
            '00000001, stale=true'
        ));
        self::assertNull(DigestAuth::authorizationHeader(
            new Request('GET', 'http://example.com/'),
            $challenge,
            'a',
            'b',
            'cnonce',
            '00000000'
        ));
        self::assertStringContainsString('nc=00000002', (string) DigestAuth::authorizationHeader(
            new Request('GET', 'http://example.com/'),
            $challenge,
            'a',
            'b',
            'cnonce',
            '00000002'
        ));
    }

    public function testSelectsStrongestSupportedDigestChallenge(): void
    {
        if (!\in_array('sha512/256', \hash_algos(), true)) {
            self::markTestSkipped('sha512/256 is not available.');
        }

        $challenge = DigestAuth::selectChallenge(new Response(401, [
            'WWW-Authenticate' => 'Digest realm="weak", nonce="one", qop="auth", algorithm=MD5, Digest realm="strong", nonce="two", qop="auth", algorithm=SHA-512-256',
        ]));

        self::assertNotNull($challenge);
        self::assertSame('strong', $challenge->realm);
        self::assertSame('SHA-512-256', $challenge->algorithm['name']);
    }

    public function testRejectsNonUtf8Charset(): void
    {
        $challenge = DigestAuth::selectChallenge(new Response(401, [
            'WWW-Authenticate' => 'Digest realm="test", nonce="abc", qop="auth", charset=ISO-8859-1',
        ]));

        self::assertNull($challenge);
    }

    public function testUnknownDigestAlgorithmIsUnsupported(): void
    {
        $challenge = DigestAuth::selectChallenge(new Response(401, [
            'WWW-Authenticate' => 'Digest realm="r", nonce="n", qop="auth", algorithm=SHA-999',
        ]));

        self::assertNull($challenge);
    }

    public function testGeneratesFipsSha512256UserhashHeaderWithUtf8Credentials(): void
    {
        if (!\in_array('sha512/256', \hash_algos(), true)) {
            self::markTestSkipped('sha512/256 is not available.');
        }

        $challenge = DigestAuth::selectChallenge(new Response(401, [
            'WWW-Authenticate' => 'Digest realm="api@example.org", qop="auth", algorithm=SHA-512-256, nonce="5TsQWLVdgBdmrQ0XsxbDODV+57QdFR34I9HAbC/RVvkK", opaque="HRPCssKJSGjCrkzDg8OhwpzCiGPChXYjwrI2QmXDnsOS", charset=UTF-8, userhash=true',
        ]));

        self::assertNotNull($challenge);

        $header = DigestAuth::authorizationHeader(
            new Request('GET', 'http://api.example.org/doe.json'),
            $challenge,
            "J\xC3\xA4s\xC3\xB8n Doe",
            'Secret, or not?',
            'NTg6RKcb9boFIAS3KrFK9BGeh+iDa/sm6jUMp2wds69v'
        );

        self::assertNotNull($header);
        self::assertStringContainsString('username="793263caabb707a56211940d90411ea4a575adeccb7e360aeb624ed06ece9b0b"', $header);
        self::assertStringContainsString('response="3798d4131c277846293534c3edc11bd8a5e4cdcbff78b05db9d95eeb1cec68a5"', $header);
        self::assertStringContainsString('algorithm=SHA-512-256', $header);
        self::assertStringContainsString('opaque="HRPCssKJSGjCrkzDg8OhwpzCiGPChXYjwrI2QmXDnsOS"', $header);
        self::assertStringContainsString('userhash=true', $header);
    }
}
