<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests;

use GuzzleHttp\AuthMiddleware;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\Exception\ResponseTimeoutException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

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

    public function testDigestProbeOmitsRequestBodyAndEntityHeaders(): void
    {
        $requests = [];
        $bodies = [];
        $mock = new MockHandler([
            static function (RequestInterface $request) use (&$requests, &$bodies): ResponseInterface {
                $requests[] = $request;
                $bodies[] = $request->getBody()->getContents();

                return new Response(401, ['WWW-Authenticate' => 'Digest realm="test", nonce="abc", qop="auth"']);
            },
            static function (RequestInterface $request) use (&$requests, &$bodies): ResponseInterface {
                $requests[] = $request;
                $bodies[] = $request->getBody()->getContents();

                return new Response(200);
            },
        ]);
        $client = new Client(['handler' => self::handlerWithAuth($mock)]);

        $response = $client->post('http://example.com', [
            'auth' => ['a', 'b', 'digest'],
            'body' => 'payload',
            'headers' => [
                'Content-Type' => 'application/json',
                'Expect' => '100-Continue',
                'Transfer-Encoding' => 'chunked',
            ],
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['', 'payload'], $bodies);
        self::assertSame('0', $requests[0]->getHeaderLine('Content-Length'));
        self::assertFalse($requests[0]->hasHeader('Expect'));
        self::assertFalse($requests[0]->hasHeader('Transfer-Encoding'));
        self::assertSame('application/json', $requests[0]->getHeaderLine('Content-Type'));
        self::assertSame('100-Continue', $requests[1]->getHeaderLine('Expect'));
        self::assertSame('chunked', $requests[1]->getHeaderLine('Transfer-Encoding'));
        self::assertSame('application/json', $requests[1]->getHeaderLine('Content-Type'));
    }

    public function testDigestProbeStripsPayloadDescriptorHeaders(): void
    {
        $requests = [];
        $bodies = [];
        $mock = new MockHandler([
            static function (RequestInterface $request) use (&$requests, &$bodies): ResponseInterface {
                $requests[] = $request;
                $bodies[] = $request->getBody()->getContents();

                return new Response(401, ['WWW-Authenticate' => 'Digest realm="test", nonce="abc", qop="auth"']);
            },
            static function (RequestInterface $request) use (&$requests, &$bodies): ResponseInterface {
                $requests[] = $request;
                $bodies[] = $request->getBody()->getContents();

                return new Response(200);
            },
        ]);
        $client = new Client(['handler' => self::handlerWithAuth($mock)]);

        $response = $client->post('http://example.com', [
            'auth' => ['a', 'b', 'digest'],
            'body' => 'payload',
            'headers' => [
                'Content-Length' => '7',
                'Content-Encoding' => 'identity',
                'Trailer' => 'Expires',
                'Content-Range' => 'bytes 0-6/7',
                'Content-MD5' => 'ss+MO0G7pQE0Dqcshfs8Zw==',
                'Digest' => 'md5=ss+MO0G7pQE0Dqcshfs8Zw==',
                'Content-Digest' => 'md5=:ss+MO0G7pQE0Dqcshfs8Zw==:',
                'Repr-Digest' => 'md5=:ss+MO0G7pQE0Dqcshfs8Zw==:',
            ],
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['', 'payload'], $bodies);
        self::assertSame('0', $requests[0]->getHeaderLine('Content-Length'));
        foreach (['Content-Encoding', 'Trailer', 'Content-Range', 'Content-MD5', 'Digest', 'Content-Digest', 'Repr-Digest'] as $header) {
            self::assertFalse($requests[0]->hasHeader($header));
            self::assertTrue($requests[1]->hasHeader($header));
        }
        self::assertSame('7', $requests[1]->getHeaderLine('Content-Length'));
    }

    public function testDigestProbeUsesConfiguredStreamFactory(): void
    {
        $factory = new Psr17SpyFactory();
        $requests = [];
        $mock = new MockHandler([
            static function (RequestInterface $request) use (&$requests): ResponseInterface {
                $requests[] = $request;

                return new Response(401, ['WWW-Authenticate' => 'Digest realm="test", nonce="abc", qop="auth"']);
            },
            static function (RequestInterface $request) use (&$requests): ResponseInterface {
                $requests[] = $request;

                return new Response(200);
            },
        ]);
        $client = new Client(['handler' => self::handlerWithAuth($mock)]);

        $client->post('http://example.com', [
            'auth' => ['a', 'b', 'digest'],
            'body' => 'payload',
            'stream_factory' => $factory,
        ]);

        self::assertCount(2, $requests);
        self::assertInstanceOf(SpyStream::class, $requests[0]->getBody());
        self::assertSame('', (string) $requests[0]->getBody());
        self::assertSame(2, $factory->createStreamCalls);
    }

    public function testDigestWithNonSeekableRequestBodySucceeds(): void
    {
        $bodies = [];
        $mock = new MockHandler([
            static function (RequestInterface $request) use (&$bodies): ResponseInterface {
                $bodies[] = $request->getBody()->getContents();

                return new Response(401, ['WWW-Authenticate' => 'Digest realm="test", nonce="abc", qop="auth"']);
            },
            static function (RequestInterface $request) use (&$bodies): ResponseInterface {
                $bodies[] = $request->getBody()->getContents();

                return new Response(200);
            },
        ]);
        $client = new Client(['handler' => self::handlerWithAuth($mock)]);

        $body = new Psr7\NoSeekStream(Psr7\Utils::streamFor('payload'));
        $response = $client->send(new Request('POST', 'http://example.com', [], $body), [
            'auth' => ['a', 'b', 'digest'],
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['', 'payload'], $bodies);
    }

    public function testDigestWithNonTellableRequestBodySucceeds(): void
    {
        $bodies = [];
        $body = new Psr7\NoSeekStream(Psr7\FnStream::decorate(Psr7\Utils::streamFor('payload'), [
            'tell' => static function (): int {
                throw new \RuntimeException('cannot tell');
            },
        ]));

        $mock = new MockHandler([
            static function (RequestInterface $request) use (&$bodies): ResponseInterface {
                $bodies[] = $request->getBody()->getContents();

                return new Response(401, ['WWW-Authenticate' => 'Digest realm="test", nonce="abc", qop="auth"']);
            },
            static function (RequestInterface $request) use (&$bodies): ResponseInterface {
                $bodies[] = $request->getBody()->getContents();

                return new Response(200);
            },
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);

        $response = $client->send(new Request('POST', 'http://example.com', [], $body), [
            'auth' => ['a', 'b', 'digest'],
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['', 'payload'], $bodies);
    }

    public function testDigestProbeRewindsPrePositionedSeekableBody(): void
    {
        $bodies = [];
        $body = Psr7\Utils::streamFor('payload');
        $body->seek(3);

        $mock = new MockHandler([
            static function (RequestInterface $request) use (&$bodies): ResponseInterface {
                $bodies[] = $request->getBody()->getContents();

                return new Response(401, ['WWW-Authenticate' => 'Digest realm="test", nonce="abc", qop="auth"']);
            },
            static function (RequestInterface $request) use (&$bodies): ResponseInterface {
                $bodies[] = $request->getBody()->getContents();

                return new Response(200);
            },
        ]);
        $client = new Client(['handler' => self::handlerWithAuth($mock)]);

        $response = $client->send(new Request('POST', 'http://example.com', [], $body), [
            'auth' => ['a', 'b', 'digest'],
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['', 'payload'], $bodies);
    }

    public function testDigestProbeStripsBodyWhenSizeCannotBeDetermined(): void
    {
        $bodies = [];
        $body = new Psr7\NoSeekStream(Psr7\FnStream::decorate(Psr7\Utils::streamFor('payload'), [
            'getSize' => static function (): ?int {
                throw new \RuntimeException('cannot size');
            },
            'tell' => static function (): int {
                throw new \RuntimeException('cannot tell');
            },
        ]));

        $mock = new MockHandler([
            static function (RequestInterface $request) use (&$bodies): ResponseInterface {
                $bodies[] = $request->getBody()->getContents();

                return new Response(401, ['WWW-Authenticate' => 'Digest realm="test", nonce="abc", qop="auth"']);
            },
            static function (RequestInterface $request) use (&$bodies): ResponseInterface {
                $bodies[] = $request->getBody()->getContents();

                return new Response(200);
            },
        ]);
        $client = new Client(['handler' => self::handlerWithAuth($mock)]);

        $response = $client->send(new Request('POST', 'http://example.com', [], $body), [
            'auth' => ['a', 'b', 'digest'],
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['', 'payload'], $bodies);
    }

    public function testDigestStaleRetryRewindsAndResendsSeekableBody(): void
    {
        $bodies = [];
        $mock = new MockHandler([
            static function (RequestInterface $request) use (&$bodies): ResponseInterface {
                $bodies[] = $request->getBody()->getContents();

                return new Response(401, ['WWW-Authenticate' => 'Digest realm="test", nonce="one", qop="auth"']);
            },
            static function (RequestInterface $request) use (&$bodies): ResponseInterface {
                $bodies[] = $request->getBody()->getContents();

                return new Response(401, ['WWW-Authenticate' => 'Digest realm="test", nonce="two", qop="auth", stale=true']);
            },
            static function (RequestInterface $request) use (&$bodies): ResponseInterface {
                $bodies[] = $request->getBody()->getContents();

                return new Response(200);
            },
        ]);
        $client = new Client(['handler' => self::handlerWithAuth($mock)]);

        $response = $client->post('http://example.com', [
            'auth' => ['a', 'b', 'digest'],
            'body' => 'payload',
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['', 'payload', 'payload'], $bodies);
    }

    public function testDigestStaleRetryStillFailsWhenConsumedBodyCannotRewind(): void
    {
        $bodies = [];
        $previous = new \RuntimeException('cannot rewind');
        $body = Psr7\FnStream::decorate(Psr7\Utils::streamFor('payload'), [
            'isSeekable' => static function (): bool {
                return false;
            },
            'rewind' => static function () use ($previous): void {
                throw $previous;
            },
        ]);

        $mock = new MockHandler([
            new Response(401, ['WWW-Authenticate' => 'Digest realm="test", nonce="one", qop="auth"']),
            static function (RequestInterface $request) use (&$bodies): ResponseInterface {
                $bodies[] = $request->getBody()->getContents();

                return new Response(401, ['WWW-Authenticate' => 'Digest realm="test", nonce="two", qop="auth", stale=true']);
            },
        ]);
        $client = new Client(['handler' => self::handlerWithAuth($mock)]);

        try {
            $client->send(new Request('POST', 'http://example.com', [], $body), ['auth' => ['a', 'b', 'digest']]);
            self::fail('Expected ResponseException.');
        } catch (ResponseException $e) {
            self::assertSame('Digest authentication failed because the request body could not be rewound', $e->getMessage());
            self::assertSame($previous, $e->getPrevious());
            self::assertSame(['payload'], $bodies);
        }
    }

    /**
     * @dataProvider nonRedirectLikeProbeResponseProvider
     */
    public function testDigestUnchallengedBodyWithheldProbeRejects(ResponseInterface $probeResponse): void
    {
        $mock = new MockHandler([$probeResponse]);
        $client = new Client(['handler' => self::handlerWithAuth($mock)]);

        try {
            $client->post('http://example.com', [
                'auth' => ['a', 'b', 'digest'],
                'body' => 'payload',
            ]);
            self::fail('Expected ResponseException.');
        } catch (ResponseException $e) {
            self::assertSame($probeResponse->getStatusCode(), $e->getResponse()->getStatusCode());
        }
    }

    public static function nonRedirectLikeProbeResponseProvider(): iterable
    {
        yield '200 without challenge' => [new Response(200, [], 'ok')];
        yield '304 not modified' => [new Response(304)];
        yield '302 without location' => [new Response(302)];
        yield '300 with location' => [new Response(300, ['Location' => 'http://example.com/next'])];
        yield '500 server error' => [new Response(500, [], 'boom')];
    }

    public function testDigestUnchallengedBodyWithheldProbeRejectsAfterRestoringSink(): void
    {
        $sink = \tempnam(\sys_get_temp_dir(), 'guzzle-auth-sink');
        self::assertIsString($sink);

        try {
            $mock = new MockHandler([new Response(200, [], 'probe')]);
            $client = new Client(['handler' => self::handlerWithAuth($mock)]);

            try {
                $client->post('http://example.com', [
                    'auth' => ['a', 'b', 'digest'],
                    'body' => 'payload',
                    'sink' => $sink,
                ]);
                self::fail('Expected ResponseException.');
            } catch (ResponseException $e) {
                self::assertSame(200, $e->getResponse()->getStatusCode());
                self::assertSame('probe', \file_get_contents($sink));
                self::assertSame('probe', (string) $e->getResponse()->getBody());
            }
        } finally {
            if (\file_exists($sink)) {
                \unlink($sink);
            }
        }
    }

    public function testDigestUnchallengedProbeWithoutBodyReturnsResponse(): void
    {
        $mock = new MockHandler([new Response(200, [], 'ok')]);
        $client = new Client(['handler' => self::handlerWithAuth($mock)]);

        $response = $client->get('http://example.com', ['auth' => ['a', 'b', 'digest']]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', (string) $response->getBody());
    }

    public function testDigest407ProxyChallengePassesThrough(): void
    {
        $mock = new MockHandler([
            new Response(407, ['Proxy-Authenticate' => 'Digest realm="proxy", nonce="abc", qop="auth"']),
        ]);
        $client = new Client(['handler' => self::handlerWithAuth($mock)]);

        $response = $client->post('http://example.com', [
            'auth' => ['a', 'b', 'digest'],
            'body' => 'payload',
        ]);

        self::assertSame(407, $response->getStatusCode());
    }

    public function testDigestRedirectLikeProbeResponsePassesThrough(): void
    {
        $mock = new MockHandler([
            new Response(302, ['Location' => 'http://example.com/next']),
        ]);
        $client = new Client(['handler' => self::handlerWithAuth($mock)]);

        $response = $client->post('http://example.com', [
            'auth' => ['a', 'b', 'digest'],
            'body' => 'payload',
        ]);

        self::assertSame(302, $response->getStatusCode());
    }

    public function testDigestProbeOmitsBodyThroughDefaultStack(): void
    {
        $requests = [];
        $bodies = [];
        $mock = new MockHandler([
            static function (RequestInterface $request) use (&$requests, &$bodies): ResponseInterface {
                $requests[] = $request;
                $bodies[] = $request->getBody()->getContents();

                return new Response(401, ['WWW-Authenticate' => 'Digest realm="test", nonce="abc", qop="auth"']);
            },
            static function (RequestInterface $request) use (&$requests, &$bodies): ResponseInterface {
                $requests[] = $request;
                $bodies[] = $request->getBody()->getContents();

                return new Response(200);
            },
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);

        $response = $client->post('http://example.com', [
            'auth' => ['a', 'b', 'digest'],
            'body' => 'payload',
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['', 'payload'], $bodies);
        self::assertSame('0', $requests[0]->getHeaderLine('Content-Length'));
        self::assertSame('7', $requests[1]->getHeaderLine('Content-Length'));
    }

    public function testDigestProbeDoesNotRegainExpectWithUnknownSizeProbeStream(): void
    {
        $requests = [];
        $mock = new MockHandler([
            static function (RequestInterface $request) use (&$requests): ResponseInterface {
                $requests[] = $request;

                return new Response(401, ['WWW-Authenticate' => 'Digest realm="test", nonce="abc", qop="auth"']);
            },
            static function (RequestInterface $request) use (&$requests): ResponseInterface {
                $requests[] = $request;

                return new Response(200);
            },
        ]);
        $streamFactory = new class implements StreamFactoryInterface {
            public function createStream(string $content = ''): StreamInterface
            {
                return Psr7\FnStream::decorate(Psr7\Utils::streamFor($content), [
                    'getSize' => static function (): ?int {
                        return null;
                    },
                ]);
            }

            public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
            {
                return new Psr7\LazyOpenStream($filename, $mode);
            }

            public function createStreamFromResource($resource): StreamInterface
            {
                return Psr7\Utils::streamFor($resource);
            }
        };
        $client = new Client(['handler' => HandlerStack::create($mock)]);

        $response = $client->post('http://example.com', [
            'auth' => ['a', 'b', 'digest'],
            'body' => 'payload',
            'stream_factory' => $streamFactory,
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, $requests);
        self::assertSame('0', $requests[0]->getHeaderLine('Content-Length'));
        self::assertFalse($requests[0]->hasHeader('Expect'));
        self::assertFalse($requests[0]->hasHeader('Transfer-Encoding'));
        self::assertTrue($requests[1]->hasHeader('Expect'));
    }

    public function testDigestHandshakeRepeatsAfterBodyPreservingRedirect(): void
    {
        $bodies = [];
        $challenge = static function (RequestInterface $request) use (&$bodies): ResponseInterface {
            $bodies[] = $request->getBody()->getContents();

            return new Response(401, ['WWW-Authenticate' => 'Digest realm="test", nonce="abc", qop="auth"']);
        };
        $mock = new MockHandler([
            $challenge,
            static function (RequestInterface $request) use (&$bodies): ResponseInterface {
                $bodies[] = $request->getBody()->getContents();

                return new Response(307, ['Location' => 'http://example.com/next']);
            },
            $challenge,
            static function (RequestInterface $request) use (&$bodies): ResponseInterface {
                $bodies[] = $request->getBody()->getContents();

                return new Response(200);
            },
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);

        $response = $client->post('http://example.com', [
            'auth' => ['a', 'b', 'digest'],
            'body' => 'payload',
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['', 'payload', '', 'payload'], $bodies);
    }

    /**
     * @dataProvider bodyPreservingRedirectStatusProvider
     */
    public function testDigestHandshakeRunsAfterBodyWithheldProbeRedirect(int $statusCode): void
    {
        $paths = [];
        $bodies = [];
        $authorizations = [];
        $record = static function (ResponseInterface $response) use (&$paths, &$bodies, &$authorizations): callable {
            return static function (RequestInterface $request) use (&$paths, &$bodies, &$authorizations, $response): ResponseInterface {
                $paths[] = $request->getUri()->getPath();
                $bodies[] = $request->getBody()->getContents();
                $authorizations[] = $request->getHeaderLine('Authorization');

                return $response;
            };
        };
        $mock = new MockHandler([
            $record(new Response($statusCode, ['Location' => 'http://example.com/next'])),
            $record(new Response(401, ['WWW-Authenticate' => 'Digest realm="test", nonce="abc", qop="auth"'])),
            $record(new Response(200)),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);

        $response = $client->post('http://example.com/start', [
            'auth' => ['a', 'b', 'digest'],
            'body' => 'payload',
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['/start', '/next', '/next'], $paths);
        self::assertSame(['', '', 'payload'], $bodies);
        self::assertSame('', $authorizations[0]);
        self::assertSame('', $authorizations[1]);
        self::assertStringStartsWith('Digest ', $authorizations[2]);
    }

    public static function bodyPreservingRedirectStatusProvider(): iterable
    {
        yield '307' => [307];
        yield '308' => [308];
    }

    /**
     * @dataProvider methodRewritingRedirectStatusProvider
     */
    public function testDigestHandshakeRunsAfterMethodRewritingProbeRedirect(int $statusCode): void
    {
        $methods = [];
        $paths = [];
        $bodies = [];
        $authorizations = [];
        $record = static function (ResponseInterface $response) use (&$methods, &$paths, &$bodies, &$authorizations): callable {
            return static function (RequestInterface $request) use (&$methods, &$paths, &$bodies, &$authorizations, $response): ResponseInterface {
                $methods[] = $request->getMethod();
                $paths[] = $request->getUri()->getPath();
                $bodies[] = $request->getBody()->getContents();
                $authorizations[] = $request->getHeaderLine('Authorization');

                return $response;
            };
        };
        $mock = new MockHandler([
            $record(new Response($statusCode, ['Location' => 'http://example.com/next'])),
            $record(new Response(401, ['WWW-Authenticate' => 'Digest realm="test", nonce="abc", qop="auth"'])),
            $record(new Response(200)),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);

        $response = $client->post('http://example.com/start', [
            'auth' => ['a', 'b', 'digest'],
            'body' => 'payload',
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['POST', 'GET', 'GET'], $methods);
        self::assertSame(['/start', '/next', '/next'], $paths);
        self::assertSame(['', '', ''], $bodies);
        self::assertSame('', $authorizations[0]);
        self::assertSame('', $authorizations[1]);
        self::assertStringStartsWith('Digest ', $authorizations[2]);
    }

    public static function methodRewritingRedirectStatusProvider(): iterable
    {
        yield '301' => [301];
        yield '302' => [302];
        yield '303' => [303];
    }

    public function testDigestGetWithBodyProbesBodyless(): void
    {
        $bodies = [];
        $mock = new MockHandler([
            static function (RequestInterface $request) use (&$bodies): ResponseInterface {
                $bodies[] = $request->getBody()->getContents();

                return new Response(401, ['WWW-Authenticate' => 'Digest realm="test", nonce="abc", qop="auth"']);
            },
            static function (RequestInterface $request) use (&$bodies): ResponseInterface {
                $bodies[] = $request->getBody()->getContents();

                return new Response(200);
            },
        ]);
        $client = new Client(['handler' => self::handlerWithAuth($mock)]);

        $response = $client->send(new Request('GET', 'http://example.com', [], Psr7\Utils::streamFor('payload')), [
            'auth' => ['a', 'b', 'digest'],
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['', 'payload'], $bodies);
    }

    public function testDigestZeroSizeNonTellableRequestBodySucceeds(): void
    {
        $requests = [];
        $body = Psr7\FnStream::decorate(Psr7\Utils::streamFor(''), [
            'tell' => static function (): int {
                throw new \RuntimeException('cannot tell');
            },
        ]);

        $mock = new MockHandler([
            static function (RequestInterface $request) use (&$requests): ResponseInterface {
                $requests[] = $request;

                return new Response(401, ['WWW-Authenticate' => 'Digest realm="test", nonce="abc", qop="auth"']);
            },
            static function (RequestInterface $request) use (&$requests): ResponseInterface {
                $requests[] = $request;

                return new Response(200);
            },
        ]);
        $client = new Client(['handler' => self::handlerWithAuth($mock)]);

        $response = $client->send(new Request('POST', 'http://example.com', [], $body), [
            'auth' => ['a', 'b', 'digest'],
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, $requests);
        self::assertStringStartsWith('Digest ', $requests[1]->getHeaderLine('Authorization'));
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

    public function testDigestBodyRewindFailureRestoresOriginalSink(): void
    {
        $sink = \tempnam(\sys_get_temp_dir(), 'guzzle-auth-sink');
        self::assertIsString($sink);

        try {
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
                new Response(401, ['WWW-Authenticate' => 'Digest realm="test", nonce="abc", qop="auth"'], 'challenge'),
            ]);
            $client = new Client(['handler' => self::handlerWithAuth($mock)]);

            try {
                $client->send(new Request('POST', 'http://example.com', [], $body), [
                    'auth' => ['a', 'b', 'digest'],
                    'sink' => $sink,
                ]);

                self::fail('Expected ResponseException.');
            } catch (ResponseException $e) {
                self::assertSame('Digest authentication failed because the request body could not be rewound', $e->getMessage());
                self::assertSame($previous, $e->getPrevious());
                self::assertSame(401, $e->getResponse()->getStatusCode());
                self::assertSame('challenge', \file_get_contents($sink));
                self::assertSame('challenge', (string) $e->getResponse()->getBody());
            }
        } finally {
            if (\file_exists($sink)) {
                \unlink($sink);
            }
        }
    }

    public function testDigestBodyRewindErrorPropagates(): void
    {
        $sink = \tempnam(\sys_get_temp_dir(), 'guzzle-auth-sink');
        self::assertIsString($sink);

        try {
            $previous = new \Error('cannot rewind');
            $body = Psr7\FnStream::decorate(Psr7\Utils::streamFor('data'), [
                'tell' => static function (): int {
                    return 4;
                },
                'rewind' => static function () use ($previous): void {
                    throw $previous;
                },
            ]);
            $mock = new MockHandler([
                new Response(401, ['WWW-Authenticate' => 'Digest realm="test", nonce="abc", qop="auth"'], 'challenge'),
            ]);
            $client = new Client(['handler' => self::handlerWithAuth($mock)]);

            try {
                $client->send(new Request('POST', 'http://example.com', [], $body), [
                    'auth' => ['a', 'b', 'digest'],
                    'sink' => $sink,
                ]);

                self::fail('Expected Error.');
            } catch (\Error $e) {
                self::assertSame($previous, $e);
            }
        } finally {
            if (\file_exists($sink)) {
                \unlink($sink);
            }
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

    public function testDigestTemporarySinkUsesConfiguredStreamFactory(): void
    {
        $sink = \tempnam(\sys_get_temp_dir(), 'guzzle-auth-sink');
        self::assertIsString($sink);

        try {
            $factory = new Psr17SpyFactory();
            $mock = new MockHandler([
                new Response(401, ['WWW-Authenticate' => 'Digest realm="test", nonce="abc", qop="auth"'], 'challenge'),
                new Response(200, [], 'ok'),
            ]);
            $client = new Client(['handler' => self::handlerWithAuth($mock)]);

            $response = $client->get('http://example.com', [
                'auth' => ['a', 'b', 'digest'],
                'sink' => $sink,
                'stream_factory' => $factory,
            ]);

            self::assertSame(200, $response->getStatusCode());
            self::assertSame(2, $factory->createStreamFromResourceCalls);
            self::assertSame('ok', \file_get_contents($sink));
        } finally {
            if (\file_exists($sink)) {
                \unlink($sink);
            }
        }
    }

    public function testDigestRestoredResourceSinkDetachesOnClose(): void
    {
        $sink = Psr7\Utils::tryFopen('php://temp', 'w+');

        try {
            $mock = new MockHandler([
                new Response(401, ['WWW-Authenticate' => 'Digest realm="test", nonce="abc", qop="auth"'], 'challenge'),
                new Response(200, [], 'ok'),
            ]);
            $client = new Client(['handler' => self::handlerWithAuth($mock)]);

            $response = $client->get('http://example.com', [
                'auth' => ['a', 'b', 'digest'],
                'sink' => $sink,
            ]);

            self::assertSame('ok', (string) $response->getBody());

            $response->getBody()->close();

            self::assertIsResource($sink);
        } finally {
            if (\is_resource($sink)) {
                \fclose($sink);
            }
        }
    }

    public function testDigestSinkRestoreFailureRejectsWithResponseException(): void
    {
        $sink = \sys_get_temp_dir().'/guzzle-auth-missing-'.\bin2hex(\random_bytes(4)).'/error.txt';
        $mock = new MockHandler([
            new Response(401, ['WWW-Authenticate' => 'Digest realm="test", nonce="abc", qop="auth"']),
            new Response(200, [], 'ok'),
        ]);
        $client = new Client(['handler' => self::handlerWithAuth($mock)]);

        try {
            $client->get('http://example.com', [
                'auth' => ['a', 'b', 'digest'],
                'sink' => $sink,
            ]);

            self::fail('Expected ResponseException.');
        } catch (ResponseException $e) {
            self::assertSame(200, $e->getResponse()->getStatusCode());
        }
    }

    public function testDigestSinkRestoreErrorPropagates(): void
    {
        $previous = new \Error('sink bug');
        $sink = Psr7\FnStream::decorate(Psr7\Utils::streamFor(), [
            'write' => static function (string $data) use ($previous): int {
                throw $previous;
            },
        ]);
        $mock = new MockHandler([
            new Response(200, [], 'ok'),
        ]);
        $client = new Client(['handler' => self::handlerWithAuth($mock)]);

        try {
            $client->get('http://example.com', [
                'auth' => ['a', 'b', 'digest'],
                'sink' => $sink,
            ]);

            self::fail('Expected Error.');
        } catch (\Error $e) {
            self::assertSame($previous, $e);
        }
    }

    public function testDigestRestoresOriginalSinkOnResponseException(): void
    {
        $sink = \tempnam(\sys_get_temp_dir(), 'guzzle-auth-sink');
        self::assertIsString($sink);

        try {
            $mock = new MockHandler([
                static function (RequestInterface $request): ResponseException {
                    return new ResponseException(
                        'response failed',
                        $request,
                        new Response(500, [], 'failed')
                    );
                },
            ]);
            $client = new Client(['handler' => self::handlerWithAuth($mock)]);

            try {
                $client->get('http://example.com', [
                    'auth' => ['a', 'b', 'digest'],
                    'sink' => $sink,
                ]);

                self::fail('Expected ResponseException.');
            } catch (ResponseException $e) {
                self::assertSame('response failed', $e->getMessage());
                self::assertSame(500, $e->getResponse()->getStatusCode());
                self::assertSame('failed', \file_get_contents($sink));
                self::assertSame('failed', (string) $e->getResponse()->getBody());
            }
        } finally {
            if (\file_exists($sink)) {
                \unlink($sink);
            }
        }
    }

    public function testDigestRestoresOriginalSinkOnResponseTimeoutException(): void
    {
        $sink = \tempnam(\sys_get_temp_dir(), 'guzzle-auth-sink');
        self::assertIsString($sink);

        try {
            $previous = new \RuntimeException('timeout cause');
            $mock = new MockHandler([
                static function (RequestInterface $request) use ($previous): ResponseTimeoutException {
                    return new ResponseTimeoutException(
                        'response timed out',
                        $request,
                        new Response(200, [], 'partial'),
                        $previous
                    );
                },
            ]);
            $client = new Client(['handler' => self::handlerWithAuth($mock)]);

            try {
                $client->get('http://example.com', [
                    'auth' => ['a', 'b', 'digest'],
                    'sink' => $sink,
                ]);

                self::fail('Expected ResponseTimeoutException.');
            } catch (ResponseTimeoutException $e) {
                self::assertSame('response timed out', $e->getMessage());
                self::assertSame(200, $e->getResponse()->getStatusCode());
                self::assertSame($previous, $e->getPrevious());
                self::assertSame('partial', \file_get_contents($sink));
                self::assertSame('partial', (string) $e->getResponse()->getBody());
            }
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
