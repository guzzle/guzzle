<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests;

use GuzzleHttp\AuthMiddleware;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class AuthMiddlewareTest extends TestCase
{
    /**
     * @dataProvider basicAuthProvider
     */
    public function testAppliesBasicAuth(array $auth): void
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);

        $client->get('http://example.com', ['auth' => $auth]);

        self::assertSame('Basic YTpi', $mock->getLastRequest()->getHeaderLine('Authorization'));
        self::assertArrayNotHasKey('auth', $mock->getLastOptions());
    }

    public static function basicAuthProvider(): iterable
    {
        yield 'implicit' => [['a', 'b']];
        yield 'explicit' => [['a', 'b', 'basic']];
        yield 'mixed case' => [['a', 'b', 'BaSiC']];
        yield 'null type' => [['a', 'b', null]];
    }

    public function testBasicAuthReplacesExistingAuthorizationHeader(): void
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);

        $client->get('http://example.com', [
            'auth' => ['a', 'b'],
            'headers' => ['authorization' => 'Bearer token'],
        ]);

        self::assertSame('Basic YTpi', $mock->getLastRequest()->getHeaderLine('Authorization'));
    }

    public function testCustomAuthStringPassesThrough(): void
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);

        $client->get('http://example.com', ['auth' => 'custom']);

        self::assertFalse($mock->getLastRequest()->hasHeader('Authorization'));
        self::assertSame('custom', $mock->getLastOptions()['auth']);
    }

    public function testHandlesDigestChallenge(): void
    {
        $requests = [];
        $mock = new MockHandler([
            static function (RequestInterface $request) use (&$requests): ResponseInterface {
                $requests[] = $request;

                return new Response(401, [
                    'WWW-Authenticate' => 'Digest realm="testrealm@host.com", nonce="dcd98b7102dd2f0e8b11d0f600bfb0c093", qop="auth"',
                ]);
            },
            static function (RequestInterface $request) use (&$requests): ResponseInterface {
                $requests[] = $request;

                return new Response(200);
            },
        ]);
        $client = new Client(['handler' => self::handlerWithAuth($mock)]);

        $response = $client->get('http://example.com/dir/index.html', [
            'auth' => ['Mufasa', 'Circle Of Life', 'digest'],
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, $requests);
        self::assertFalse($requests[0]->hasHeader('Authorization'));
        self::assertStringContainsString('Digest ', $requests[1]->getHeaderLine('Authorization'));
        self::assertStringContainsString('response="6629fae49393a05397450978507c4ef1"', $requests[1]->getHeaderLine('Authorization'));
    }

    public function testDigestAuthIntOnlyChallengeDoesNotRetry(): void
    {
        $mock = new MockHandler([
            new Response(401, ['WWW-Authenticate' => 'Digest realm="test", nonce="abc", qop="auth-int"']),
            new Response(200),
        ]);
        $client = new Client(['handler' => self::handlerWithAuth($mock)]);

        $response = $client->get('http://example.com', [
            'auth' => ['a', 'b', 'digest'],
            'http_errors' => false,
        ]);

        self::assertSame(401, $response->getStatusCode());
        self::assertCount(1, $mock);
    }

    public function testDigestStaleChallengeRetriesOnceMore(): void
    {
        $requests = [];
        $mock = new MockHandler([
            static function (RequestInterface $request) use (&$requests): ResponseInterface {
                $requests[] = $request;

                return new Response(401, ['WWW-Authenticate' => 'Digest realm="test", nonce="one", qop="auth"']);
            },
            static function (RequestInterface $request) use (&$requests): ResponseInterface {
                $requests[] = $request;

                return new Response(401, ['WWW-Authenticate' => 'Digest realm="test", nonce="two", qop="auth", stale=true']);
            },
            static function (RequestInterface $request) use (&$requests): ResponseInterface {
                $requests[] = $request;

                return new Response(200);
            },
        ]);
        $cnonces = ['first', 'second'];
        $client = new Client(['handler' => self::handlerWithAuth($mock, static function () use (&$cnonces): string {
            return (string) \array_shift($cnonces);
        })]);

        $response = $client->get('http://example.com', ['auth' => ['a', 'b', 'digest']]);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(3, $requests);
        self::assertStringContainsString('nonce="one"', $requests[1]->getHeaderLine('Authorization'));
        self::assertStringContainsString('nonce="two"', $requests[2]->getHeaderLine('Authorization'));
    }

    public function testDigestBodyRewindFailureRejects(): void
    {
        $previous = new \RuntimeException('cannot rewind');
        $body = Psr7\FnStream::decorate(Psr7\Utils::streamFor('data'), [
            'tell' => static function (): int {
                return 4;
            },
            'rewind' => static function () use ($previous): void {
                throw $previous;
            },
        ]);
        $mock = new MockHandler([
            new Response(401, ['WWW-Authenticate' => 'Digest realm="test", nonce="abc", qop="auth"']),
        ]);
        $client = new Client(['handler' => self::handlerWithAuth($mock)]);

        try {
            $client->send(new Request('POST', 'http://example.com', [], $body), ['auth' => ['a', 'b', 'digest']]);
            self::fail('Expected ResponseException.');
        } catch (ResponseException $e) {
            self::assertSame('Digest authentication failed because the request body could not be rewound', $e->getMessage());
            self::assertSame($previous, $e->getPrevious());
        }
    }

    public function testDigestDoesNotWriteChallengeBodyToUserSink(): void
    {
        $sink = \tempnam(\sys_get_temp_dir(), 'guzzle-auth-sink');
        self::assertIsString($sink);

        try {
            $mock = new MockHandler([
                new Response(401, ['WWW-Authenticate' => 'Digest realm="test", nonce="abc", qop="auth"'], 'challenge'),
                new Response(200, [], 'ok'),
            ]);
            $client = new Client(['handler' => self::handlerWithAuth($mock)]);

            $client->get('http://example.com', [
                'auth' => ['a', 'b', 'digest'],
                'sink' => $sink,
            ]);

            self::assertSame('ok', \file_get_contents($sink));
        } finally {
            if (\file_exists($sink)) {
                \unlink($sink);
            }
        }
    }

    public function testCookiesFromDigestChallengeAreSentOnRetry(): void
    {
        $mock = new MockHandler([
            new Response(401, [
                'WWW-Authenticate' => 'Digest realm="test", nonce="abc", qop="auth"',
                'Set-Cookie' => 'foo=bar; Domain=example.com',
            ]),
            static function (RequestInterface $request): ResponseInterface {
                self::assertSame('foo=bar', $request->getHeaderLine('Cookie'));

                return new Response(200);
            },
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);

        $response = $client->get('http://example.com', [
            'auth' => ['a', 'b', 'digest'],
            'cookies' => new CookieJar(),
        ]);

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * @param (callable(): string)|null $cnonceGenerator
     */
    private static function handlerWithAuth(MockHandler $mock, ?callable $cnonceGenerator = null): HandlerStack
    {
        $stack = new HandlerStack($mock);
        $stack->push(static function (callable $handler) use ($cnonceGenerator): AuthMiddleware {
            return new AuthMiddleware($handler, $cnonceGenerator ?? static function (): string {
                return '0a4f113b';
            });
        }, 'auth');

        return $stack;
    }
}
