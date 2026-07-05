<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Server\Server;
use PHPUnit\Framework\TestCase;

class DigestAuthIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        // The request log and the response queue are both global on the
        // node server, and PHPUnit runs in random order.
        Server::flush();
        Server::enqueue([]);
    }

    public function testDigestProbeOmitsBodyOnWire(): void
    {
        // Unprotected path: the node server records both legs and serves the
        // queued responses, so this proves the wire behavior independently
        // of the digest firewall.
        Server::enqueue([
            new Response(401, ['WWW-Authenticate' => 'Digest realm="test", nonce="abc", qop="auth"'], 'challenge'),
            new Response(200, [], 'ok'),
        ]);

        $client = new Client(['http_errors' => false]);
        $response = $client->post(Server::$url.'digest-probe', [
            'auth' => ['a', 'b', 'digest'],
            'body' => 'payload',
        ]);

        self::assertSame(200, $response->getStatusCode());

        $requests = Server::received();
        self::assertCount(2, $requests);
        self::assertFalse($requests[0]->hasHeader('Authorization'));
        self::assertSame('', (string) $requests[0]->getBody());
        self::assertSame('0', $requests[0]->getHeaderLine('Content-Length'));
        self::assertStringStartsWith('Digest ', $requests[1]->getHeaderLine('Authorization'));
        self::assertSame('payload', (string) $requests[1]->getBody());
    }

    public function testDigestQopAuthHandshakeAgainstServerFirewall(): void
    {
        Server::enqueue([new Response(200, [], 'secret')]);

        $client = new Client(['http_errors' => false]);
        $response = $client->post(Server::$url.'secure/by-digest/qop-auth/echo', [
            'auth' => ['me', 'test', 'digest'],
            'body' => 'payload',
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('secret', (string) $response->getBody());

        // The firewall records only the authenticated request; the 401 probe
        // never reaches the request log.
        $requests = Server::received();
        self::assertCount(1, $requests);
        self::assertSame('payload', (string) $requests[0]->getBody());

        $authorization = $requests[0]->getHeaderLine('Authorization');
        self::assertStringStartsWith('Digest ', $authorization);
        self::assertStringContainsString('qop=auth', $authorization);
        self::assertStringContainsString('nc=00000001', $authorization);
    }

    public function testDigestQopAuthUriIncludesQueryString(): void
    {
        // The verifier hashes HA2 from the actual request target, so this
        // fails if Guzzle ever drops the query string from uri=.
        Server::enqueue([new Response(200, [], 'secret')]);

        $client = new Client(['http_errors' => false]);
        $response = $client->get(Server::$url.'secure/by-digest/qop-auth/echo?answer=42', [
            'auth' => ['me', 'test', 'digest'],
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('secret', (string) $response->getBody());

        $requests = Server::received();
        self::assertCount(1, $requests);

        $authorization = $requests[0]->getHeaderLine('Authorization');
        self::assertStringStartsWith('Digest ', $authorization);
        self::assertStringContainsString('realm="Digest Test"', $authorization);
        self::assertStringContainsString('uri="/secure/by-digest/qop-auth/echo?answer=42"', $authorization);
    }

    public function testDigestLegacyHandshakeWithoutQop(): void
    {
        // The /secure/by-digest/ endpoint (no qop segment) issues an
        // RFC 2069 challenge, exercising the legacy computation end-to-end.
        Server::enqueue([new Response(200, [], 'secret')]);

        $client = new Client(['http_errors' => false]);
        $response = $client->get(Server::$url.'secure/by-digest/echo', [
            'auth' => ['me', 'test', 'digest'],
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('secret', (string) $response->getBody());

        $requests = Server::received();
        self::assertCount(1, $requests);

        $authorization = $requests[0]->getHeaderLine('Authorization');
        self::assertStringStartsWith('Digest ', $authorization);
        self::assertStringNotContainsString('qop=', $authorization);
        self::assertStringNotContainsString('nc=', $authorization);
        self::assertStringNotContainsString('cnonce=', $authorization);
    }

    public function testDigestWrongCredentialsReturnUnauthorized(): void
    {
        $client = new Client(['http_errors' => false]);
        $response = $client->get(Server::$url.'secure/by-digest/qop-auth/echo', [
            'auth' => ['me', 'wrong', 'digest'],
        ]);

        self::assertSame(401, $response->getStatusCode());
        self::assertCount(0, Server::received());
    }

    /**
     * @dataProvider invalidDigestAuthorizationProvider
     */
    public function testDigestFirewallRejectsMalformedAuthorization(string $defectTemplate): void
    {
        $client = new Client(['http_errors' => false]);

        // Fetch a genuine server-issued nonce first, so each defect below
        // is the only thing wrong with the handcrafted header (the
        // issued-nonce check would otherwise mask the branch under test).
        $challenge = $client->get(Server::$url.'secure/by-digest/qop-auth/echo');
        self::assertSame(401, $challenge->getStatusCode());
        \preg_match('/nonce="([0-9a-f]+)"/', $challenge->getHeaderLine('WWW-Authenticate'), $matches);
        self::assertNotSame('', $matches[1] ?? '');

        $response = $client->get(Server::$url.'secure/by-digest/qop-auth/echo', [
            'headers' => ['Authorization' => \sprintf($defectTemplate, $matches[1])],
        ]);

        // The firewall rejects before recording, and every defect is
        // detected before the response hash is compared, so a garbage
        // response= value never reaches the comparison.
        self::assertSame(401, $response->getStatusCode());
        self::assertCount(0, Server::received());
    }

    public static function invalidDigestAuthorizationProvider(): iterable
    {
        $garbageResponse = \str_repeat('0', 32);

        yield 'duplicate parameter' => ['Digest username="me", username="me", realm="Digest Test", nonce="%s", uri="/secure/by-digest/qop-auth/echo", response="'.$garbageResponse.'", algorithm=MD5, qop=auth, nc=00000001, cnonce="deadbeef"'];
        yield 'unknown parameter' => ['Digest username="me", realm="Digest Test", nonce="%s", uri="/secure/by-digest/qop-auth/echo", response="'.$garbageResponse.'", algorithm=MD5, qop=auth, nc=00000001, cnonce="deadbeef", foo="bar"'];
        yield 'client-invented nonce' => ['Digest username="me", realm="Digest Test", nonce="00000000000000000000000000000000", uri="/secure/by-digest/qop-auth/echo", response="'.$garbageResponse.'", algorithm=MD5, qop=auth, nc=00000001, cnonce="deadbeef"'];
        yield 'nonce count zero' => ['Digest username="me", realm="Digest Test", nonce="%s", uri="/secure/by-digest/qop-auth/echo", response="'.$garbageResponse.'", algorithm=MD5, qop=auth, nc=00000000, cnonce="deadbeef"'];
        yield 'wrong algorithm' => ['Digest username="me", realm="Digest Test", nonce="%s", uri="/secure/by-digest/qop-auth/echo", response="'.$garbageResponse.'", algorithm=SHA-256, qop=auth, nc=00000001, cnonce="deadbeef"'];
    }
}
