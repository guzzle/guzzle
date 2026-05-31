<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\Exception\TooManyRedirectsException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\RedirectMiddleware;
use GuzzleHttp\RequestOptions;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\UriInterface;

/**
 * @covers \GuzzleHttp\RedirectMiddleware
 */
class RedirectMiddlewareTest extends TestCase
{
    public function testIgnoresNonRedirects(): void
    {
        $response = new Response(200);
        $stack = new HandlerStack(new MockHandler([$response]));
        $stack->push(Middleware::redirect());
        $handler = $stack->resolve();
        $request = new Request('GET', 'http://example.com');
        $promise = $handler($request, []);
        $response = $promise->wait();
        self::assertSame(200, $response->getStatusCode());
    }

    public function testIgnoresWhenNoLocation(): void
    {
        $response = new Response(304);
        $stack = new HandlerStack(new MockHandler([$response]));
        $stack->push(Middleware::redirect());
        $handler = $stack->resolve();
        $request = new Request('GET', 'http://example.com');
        $promise = $handler($request, []);
        $response = $promise->wait();
        self::assertSame(304, $response->getStatusCode());
    }

    public function testRedirectsWithAbsoluteUri(): void
    {
        $mock = new MockHandler([
            new Response(302, ['Location' => 'http://test.com']),
            new Response(200),
        ]);
        $stack = new HandlerStack($mock);
        $stack->push(Middleware::redirect());
        $handler = $stack->resolve();
        $request = new Request('GET', 'http://example.com?a=b');
        $promise = $handler($request, [
            'allow_redirects' => ['max' => 2],
        ]);
        $response = $promise->wait();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('http://test.com', (string) $mock->getLastRequest()->getUri());
    }

    public function testRedirectRejectsInvalidIdnConversionOption(): void
    {
        $mock = new MockHandler([
            new Response(302, ['Location' => 'http://www.tést.com/whatever']),
            new Response(200),
        ]);
        $stack = new HandlerStack($mock);
        $stack->push(Middleware::redirect());

        $this->expectException(\GuzzleHttp\Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('idn_conversion must be true, false, null, or an integer IDNA_* bitmask');

        $stack->resolve()(new Request('GET', 'http://example.com'), [
            'allow_redirects' => ['max' => 2],
            'idn_conversion' => '0',
        ])->wait();
    }

    public function testRedirectsWithRelativeUri(): void
    {
        $mock = new MockHandler([
            new Response(302, ['Location' => '/foo']),
            new Response(200),
        ]);
        $stack = new HandlerStack($mock);
        $stack->push(Middleware::redirect());
        $handler = $stack->resolve();
        $request = new Request('GET', 'http://example.com?a=b');
        $promise = $handler($request, [
            'allow_redirects' => ['max' => 2],
        ]);
        $response = $promise->wait();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('http://example.com/foo', (string) $mock->getLastRequest()->getUri());
    }

    public function testAbsoluteRedirectUsesConfiguredUriFactory(): void
    {
        $mock = new MockHandler([
            new Response(302, ['Location' => 'http://test.com/foo']),
            new Response(200),
        ]);
        $stack = new HandlerStack($mock);
        $stack->push(Middleware::redirect());
        $handler = $stack->resolve();
        $factory = new RedirectTestUriFactory();
        $request = new Request('GET', 'http://example.com');

        $handler($request, [
            'allow_redirects' => ['max' => 2],
            RequestOptions::URI_FACTORY => $factory,
        ])->wait();

        self::assertSame(['http://test.com/foo'], $factory->uriCalls());
        self::assertInstanceOf(RedirectTestUri::class, $mock->getLastRequest()->getUri());
        self::assertSame('http://test.com/foo', (string) $mock->getLastRequest()->getUri());
    }

    public function testRelativeRedirectUsesConfiguredUriFactory(): void
    {
        $mock = new MockHandler([
            new Response(302, ['Location' => '/foo']),
            new Response(200),
        ]);
        $stack = new HandlerStack($mock);
        $stack->push(Middleware::redirect());
        $handler = $stack->resolve();
        $factory = new RedirectTestUriFactory();
        $request = new Request('GET', 'http://example.com?a=b');

        $handler($request, [
            'allow_redirects' => ['max' => 2],
            RequestOptions::URI_FACTORY => $factory,
        ])->wait();

        self::assertSame(['/foo', 'http://example.com/foo'], $factory->uriCalls());
        self::assertInstanceOf(RedirectTestUri::class, $mock->getLastRequest()->getUri());
        self::assertSame('http://example.com/foo', (string) $mock->getLastRequest()->getUri());
    }

    public function testSendUsesClientUriFactoryForRedirects(): void
    {
        $mock = new MockHandler([
            new Response(302, ['Location' => 'http://test.com/foo']),
            new Response(200),
        ]);
        $factory = new RedirectTestUriFactory();
        $client = new Client([
            'handler' => HandlerStack::create($mock),
            RequestOptions::URI_FACTORY => $factory,
        ]);

        $client->send(new Request('GET', 'http://example.com'));

        self::assertSame(['http://test.com/foo'], $factory->uriCalls());
        self::assertInstanceOf(RedirectTestUri::class, $mock->getLastRequest()->getUri());
        self::assertSame('http://test.com/foo', (string) $mock->getLastRequest()->getUri());
    }

    public function testPerRequestUriFactoryOverridesClientUriFactoryForRedirects(): void
    {
        $mock = new MockHandler([
            new Response(302, ['Location' => 'http://test.com/foo']),
            new Response(200),
        ]);
        $clientFactory = new RedirectTestUriFactory();
        $requestFactory = new RedirectTestUriFactory();
        $client = new Client([
            'handler' => HandlerStack::create($mock),
            RequestOptions::URI_FACTORY => $clientFactory,
        ]);

        $client->send(new Request('GET', 'http://example.com'), [
            RequestOptions::URI_FACTORY => $requestFactory,
        ]);

        self::assertSame([], $clientFactory->uriCalls());
        self::assertSame(['http://test.com/foo'], $requestFactory->uriCalls());
        self::assertInstanceOf(RedirectTestUri::class, $mock->getLastRequest()->getUri());
        self::assertSame('http://test.com/foo', (string) $mock->getLastRequest()->getUri());
    }

    public function testNullPerRequestUriFactoryFallsBackForRedirects(): void
    {
        $mock = new MockHandler([
            new Response(302, ['Location' => 'http://test.com/foo']),
            new Response(200),
        ]);
        $clientFactory = new RedirectTestUriFactory();
        $client = new Client([
            'handler' => HandlerStack::create($mock),
            RequestOptions::URI_FACTORY => $clientFactory,
        ]);

        $client->send(new Request('GET', 'http://example.com'), [
            RequestOptions::URI_FACTORY => null,
        ]);

        self::assertSame([], $clientFactory->uriCalls());
        self::assertNotInstanceOf(RedirectTestUri::class, $mock->getLastRequest()->getUri());
        self::assertSame('http://test.com/foo', (string) $mock->getLastRequest()->getUri());
    }

    public function testOnRedirectReceivesUriFromConfiguredFactory(): void
    {
        $mock = new MockHandler([
            new Response(302, ['Location' => 'http://test.com/foo']),
            new Response(200),
        ]);
        $factory = new RedirectTestUriFactory();
        $client = new Client([
            'handler' => HandlerStack::create($mock),
            RequestOptions::URI_FACTORY => $factory,
        ]);
        $called = false;

        $client->send(new Request('GET', 'http://example.com'), [
            'allow_redirects' => [
                'on_redirect' => static function (RequestInterface $request, ResponseInterface $response, UriInterface $uri) use (&$called): void {
                    self::assertSame(302, $response->getStatusCode());
                    self::assertSame('GET', $request->getMethod());
                    self::assertInstanceOf(RedirectTestUri::class, $uri);
                    self::assertSame('http://test.com/foo', (string) $uri);
                    $called = true;
                },
            ],
        ]);

        self::assertTrue($called);
    }

    public function testRedirectBodyResetUsesConfiguredStreamFactory(): void
    {
        $redirectMiddleware = new RedirectMiddleware(static function (): void {
        });
        $factory = new RedirectTestStreamFactory();
        $request = new Request('POST', 'http://example.com/', [], 'payload');

        $modifiedRequest = $redirectMiddleware->modifyRequest($request, [
            'allow_redirects' => [
                'protocols' => ['http', 'https'],
                'strict' => false,
                'referer' => false,
            ],
            RequestOptions::STREAM_FACTORY => $factory,
        ], new Response(302, ['Location' => 'http://example.com/redirected']));

        self::assertSame('GET', $modifiedRequest->getMethod());
        self::assertInstanceOf(RedirectTestStream::class, $modifiedRequest->getBody());
        self::assertSame('', (string) $modifiedRequest->getBody());
        self::assertSame([''], $factory->streamCalls());
    }

    public function testInvalidStreamFactoryOptionForRedirectBodyResetIsRejected(): void
    {
        $redirectMiddleware = new RedirectMiddleware(static function (): void {
        });
        $request = new Request('POST', 'http://example.com/', [], 'payload');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('stream_factory must be an instance of Psr\\Http\\Message\\StreamFactoryInterface');

        $redirectMiddleware->modifyRequest($request, [
            'allow_redirects' => [
                'protocols' => ['http', 'https'],
                'strict' => false,
                'referer' => false,
            ],
            RequestOptions::STREAM_FACTORY => new \stdClass(),
        ], new Response(302, ['Location' => 'http://example.com/redirected']));
    }

    public function testRedirectProtocolMatchingIsStrict(): void
    {
        $redirectMiddleware = new RedirectMiddleware(static function (): void {
        });
        $request = new Request('GET', 'http://example.com/');

        $this->expectException(BadResponseException::class);
        $this->expectExceptionMessage('does not use one of the allowed redirect protocols');

        $redirectMiddleware->modifyRequest($request, [
            'allow_redirects' => [
                'protocols' => [true],
                'strict' => false,
                'referer' => false,
            ],
        ], new Response(302, ['Location' => 'http://example.com/redirected']));
    }

    public function testRelativeRedirectPreservesCustomRequestAndUriImplementations(): void
    {
        $mock = new MockHandler([
            new Response(302, ['Location' => '/foo']),
            new Response(200),
        ]);
        $stack = new HandlerStack($mock);
        $stack->push(Middleware::redirect());
        $handler = $stack->resolve();
        $request = new RedirectTestRequest('GET', new RedirectTestUri('http://example.com?a=b'));
        $response = $handler($request, [
            'allow_redirects' => ['max' => 2],
        ])->wait();

        $lastRequest = $mock->getLastRequest();
        self::assertSame(200, $response->getStatusCode());
        self::assertInstanceOf(RedirectTestRequest::class, $lastRequest);
        self::assertInstanceOf(RedirectTestUri::class, $lastRequest->getUri());
        self::assertSame('http://example.com/foo', (string) $lastRequest->getUri());
    }

    public function testRedirectRequestBodyRewindFailureThrowsResponseException(): void
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
            new Response(307, ['Location' => 'http://example.com/redirected']),
        ]);
        $stack = new HandlerStack($mock);
        $stack->push(Middleware::redirect());
        $handler = $stack->resolve();
        $request = new Request('POST', 'http://example.com', [], $body);

        try {
            $handler($request, ['allow_redirects' => ['max' => 2]])->wait();

            self::fail('Expected ResponseException');
        } catch (ResponseException $e) {
            self::assertNotInstanceOf(BadResponseException::class, $e);
            self::assertSame($request, $e->getRequest());
            self::assertSame(307, $e->getResponse()->getStatusCode());
            self::assertSame(
                'Redirect failed because the request body could not be rewound',
                $e->getMessage()
            );
            self::assertSame($previous, $e->getPrevious());
        }
    }

    public function testSendPreservesCustomUriImplementationForRelativeRedirectsWithDefaultUriFactory(): void
    {
        $mock = new MockHandler([
            new Response(302, ['Location' => '/foo']),
            new Response(200),
        ]);
        $client = new Client([
            'handler' => HandlerStack::create($mock),
        ]);

        $client->send(new RedirectTestRequest('GET', new RedirectTestUri('http://example.com?a=b')));

        $lastRequest = $mock->getLastRequest();
        self::assertInstanceOf(RedirectTestRequest::class, $lastRequest);
        self::assertInstanceOf(RedirectTestUri::class, $lastRequest->getUri());
        self::assertSame('http://example.com/foo', (string) $lastRequest->getUri());
    }

    public function testLimitsToMaxRedirects(): void
    {
        $mock = new MockHandler([
            new Response(301, ['Location' => 'http://test.com']),
            new Response(302, ['Location' => 'http://test.com']),
            new Response(303, ['Location' => 'http://test.com']),
            new Response(304, ['Location' => 'http://test.com']),
        ]);
        $stack = new HandlerStack($mock);
        $stack->push(Middleware::redirect());
        $handler = $stack->resolve();
        $request = new Request('GET', 'http://example.com');
        $promise = $handler($request, ['allow_redirects' => ['max' => 3]]);

        $this->expectException(TooManyRedirectsException::class);
        $this->expectExceptionMessage('Will not follow more than 3 redirects');
        $promise->wait();
    }

    public function testTooManyRedirectsExceptionHasResponse(): void
    {
        $mock = new MockHandler([
            new Response(301, ['Location' => 'http://test.com']),
            new Response(302, ['Location' => 'http://test.com']),
        ]);
        $stack = new HandlerStack($mock);
        $stack->push(Middleware::redirect());
        $handler = $stack->resolve();
        $request = new Request('GET', 'http://example.com');
        $promise = $handler($request, ['allow_redirects' => ['max' => 1]]);

        try {
            $promise->wait();
            self::fail();
        } catch (TooManyRedirectsException $e) {
            self::assertSame(302, $e->getResponse()->getStatusCode());
        }
    }

    public function testEnsuresProtocolIsValid(): void
    {
        $mock = new MockHandler([
            new Response(301, ['Location' => 'ftp://test.com']),
        ]);
        $stack = new HandlerStack($mock);
        $stack->push(Middleware::redirect());
        $handler = $stack->resolve();
        $request = new Request('GET', 'http://example.com');

        $this->expectException(BadResponseException::class);
        $this->expectExceptionMessage('Redirect URI,');
        $handler($request, ['allow_redirects' => ['max' => 3]])->wait();
    }

    public function testRejectsMalformedRedirectUri(): void
    {
        $mock = new MockHandler([
            new Response(302, ['Location' => 'http://example.com:99999/path']),
        ]);
        $stack = new HandlerStack($mock);
        $stack->push(Middleware::redirect());
        $handler = $stack->resolve();
        $request = new Request('GET', 'http://example.com');

        try {
            $handler($request, ['allow_redirects' => ['max' => 3]])->wait();
            self::fail('Expected BadResponseException.');
        } catch (BadResponseException $e) {
            self::assertSame(302, $e->getResponse()->getStatusCode());
            self::assertInstanceOf(\InvalidArgumentException::class, $e->getPrevious());
            self::assertStringStartsWith('Redirect URI,', $e->getMessage());
        }
    }

    public function testWrapsUriFactoryExceptionsForRedirects(): void
    {
        $mock = new MockHandler([
            new Response(302, ['Location' => 'http://test.com/foo']),
        ]);
        $stack = new HandlerStack($mock);
        $stack->push(Middleware::redirect());
        $handler = $stack->resolve();
        $request = new Request('GET', 'http://example.com');

        try {
            $handler($request, [
                'allow_redirects' => ['max' => 2],
                RequestOptions::URI_FACTORY => new RedirectTestFailingUriFactory(),
            ])->wait();
            self::fail('Expected BadResponseException.');
        } catch (BadResponseException $e) {
            self::assertSame(302, $e->getResponse()->getStatusCode());
            self::assertInstanceOf(\InvalidArgumentException::class, $e->getPrevious());
            self::assertSame('Factory could not create URI.', $e->getPrevious()->getMessage());
            self::assertStringStartsWith('Redirect URI,', $e->getMessage());
        }
    }

    public function testRejectsInvalidUriFactoryOptionForRedirects(): void
    {
        $mock = new MockHandler([
            new Response(302, ['Location' => 'http://test.com/foo']),
        ]);
        $stack = new HandlerStack($mock);
        $stack->push(Middleware::redirect());
        $handler = $stack->resolve();
        $request = new Request('GET', 'http://example.com');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('uri_factory must be an instance of Psr\\Http\\Message\\UriFactoryInterface');

        $handler($request, [
            'allow_redirects' => ['max' => 2],
            RequestOptions::URI_FACTORY => new \stdClass(),
        ])->wait();
    }

    public function testAddsRefererHeader(): void
    {
        $mock = new MockHandler([
            new Response(302, ['Location' => 'http://test.com']),
            new Response(200),
        ]);
        $stack = new HandlerStack($mock);
        $stack->push(Middleware::redirect());
        $handler = $stack->resolve();
        $request = new Request('GET', 'http://example.com?a=b');
        $promise = $handler($request, [
            'allow_redirects' => ['max' => 2, 'referer' => true],
        ]);
        $promise->wait();
        self::assertSame(
            'http://example.com?a=b',
            $mock->getLastRequest()->getHeaderLine('Referer')
        );
    }

    public function testAddsRefererHeaderButClearsUserInfo(): void
    {
        $mock = new MockHandler([
            new Response(302, ['Location' => 'http://test.com']),
            new Response(200),
        ]);
        $stack = new HandlerStack($mock);
        $stack->push(Middleware::redirect());
        $handler = $stack->resolve();
        $request = new Request('GET', 'http://foo:bar@example.com?a=b');
        $promise = $handler($request, [
            'allow_redirects' => ['max' => 2, 'referer' => true],
        ]);
        $promise->wait();
        self::assertSame(
            'http://example.com?a=b',
            $mock->getLastRequest()->getHeaderLine('Referer')
        );
    }

    public function testAddsGuzzleRedirectHeader(): void
    {
        $mock = new MockHandler([
            new Response(302, ['Location' => 'http://example.com']),
            new Response(302, ['Location' => 'http://example.com/foo']),
            new Response(302, ['Location' => 'http://example.com/bar']),
            new Response(200),
        ]);

        $stack = new HandlerStack($mock);
        $stack->push(Middleware::redirect());
        $handler = $stack->resolve();
        $request = new Request('GET', 'http://example.com?a=b');
        $promise = $handler($request, [
            'allow_redirects' => ['track_redirects' => true],
        ]);
        $response = $promise->wait(true);
        self::assertSame(
            [
                'http://example.com',
                'http://example.com/foo',
                'http://example.com/bar',
            ],
            $response->getHeader(RedirectMiddleware::HISTORY_HEADER)
        );
    }

    public function testAddsGuzzleRedirectStatusHeader(): void
    {
        $mock = new MockHandler([
            new Response(301, ['Location' => 'http://example.com']),
            new Response(302, ['Location' => 'http://example.com/foo']),
            new Response(301, ['Location' => 'http://example.com/bar']),
            new Response(302, ['Location' => 'http://example.com/baz']),
            new Response(200),
        ]);

        $stack = new HandlerStack($mock);
        $stack->push(Middleware::redirect());
        $handler = $stack->resolve();
        $request = new Request('GET', 'http://example.com?a=b');
        $promise = $handler($request, [
            'allow_redirects' => ['track_redirects' => true],
        ]);
        $response = $promise->wait(true);
        self::assertSame(
            [
                '301',
                '302',
                '301',
                '302',
            ],
            $response->getHeader(RedirectMiddleware::STATUS_HISTORY_HEADER)
        );
    }

    public function testDoesNotAddRefererWhenGoingFromHttpsToHttp(): void
    {
        $mock = new MockHandler([
            new Response(302, ['Location' => 'http://test.com']),
            new Response(200),
        ]);
        $stack = new HandlerStack($mock);
        $stack->push(Middleware::redirect());
        $handler = $stack->resolve();
        $request = new Request('GET', 'https://example.com?a=b');
        $promise = $handler($request, [
            'allow_redirects' => ['max' => 2, 'referer' => true],
        ]);
        $promise->wait();
        self::assertFalse($mock->getLastRequest()->hasHeader('Referer'));
    }

    public function testInvokesOnRedirectForRedirects(): void
    {
        $mock = new MockHandler([
            new Response(302, ['Location' => 'http://test.com']),
            new Response(200),
        ]);
        $stack = new HandlerStack($mock);
        $stack->push(Middleware::redirect());
        $handler = $stack->resolve();
        $request = new Request('GET', 'http://example.com?a=b');
        $call = false;
        $promise = $handler($request, [
            'allow_redirects' => [
                'max' => 2,
                'on_redirect' => static function (RequestInterface $request, ResponseInterface $response, UriInterface $uri) use (&$call): void {
                    self::assertSame(302, $response->getStatusCode());
                    self::assertSame('GET', $request->getMethod());
                    self::assertSame('http://test.com', (string) $uri);
                    $call = true;
                },
            ],
        ]);
        $promise->wait();
        self::assertTrue($call);
    }

    /**
     * @testWith ["digest"]
     *           ["ntlm"]
     */
    public function testRemoveCurlAuthorizationOptionsOnRedirectCrossHost(string $auth): void
    {
        if (!defined('\CURLOPT_HTTPAUTH')) {
            self::markTestSkipped('ext-curl is required for this test');
        }

        $mock = new MockHandler([
            new Response(302, ['Location' => 'http://test.com']),
            static function (RequestInterface $request, array $options): ResponseInterface {
                self::assertFalse(
                    isset($options['curl'][\CURLOPT_HTTPAUTH]),
                    'curl options still contain CURLOPT_HTTPAUTH entry'
                );
                self::assertFalse(
                    isset($options['curl'][\CURLOPT_USERPWD]),
                    'curl options still contain CURLOPT_USERPWD entry'
                );

                return new Response(200);
            },
        ]);
        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);
        $client->get('http://example.com?a=b', ['auth' => ['testuser', 'testpass', $auth]]);
    }

    /**
     * @testWith ["digest"]
     *           ["ntlm"]
     */
    public function testRemoveCurlAuthorizationOptionsOnRedirectCrossPort(string $auth): void
    {
        if (!defined('\CURLOPT_HTTPAUTH')) {
            self::markTestSkipped('ext-curl is required for this test');
        }

        $mock = new MockHandler([
            new Response(302, ['Location' => 'http://example.com:81/']),
            static function (RequestInterface $request, array $options): ResponseInterface {
                self::assertFalse(
                    isset($options['curl'][\CURLOPT_HTTPAUTH]),
                    'curl options still contain CURLOPT_HTTPAUTH entry'
                );
                self::assertFalse(
                    isset($options['curl'][\CURLOPT_USERPWD]),
                    'curl options still contain CURLOPT_USERPWD entry'
                );

                return new Response(200);
            },
        ]);
        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);
        $client->get('http://example.com?a=b', ['auth' => ['testuser', 'testpass', $auth]]);
    }

    /**
     * @testWith ["digest"]
     *           ["ntlm"]
     */
    public function testRemoveCurlAuthorizationOptionsOnRedirectCrossScheme(string $auth): void
    {
        if (!defined('\CURLOPT_HTTPAUTH')) {
            self::markTestSkipped('ext-curl is required for this test');
        }

        $mock = new MockHandler([
            new Response(302, ['Location' => 'http://example.com?a=b']),
            static function (RequestInterface $request, array $options): ResponseInterface {
                self::assertFalse(
                    isset($options['curl'][\CURLOPT_HTTPAUTH]),
                    'curl options still contain CURLOPT_HTTPAUTH entry'
                );
                self::assertFalse(
                    isset($options['curl'][\CURLOPT_USERPWD]),
                    'curl options still contain CURLOPT_USERPWD entry'
                );

                return new Response(200);
            },
        ]);
        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);
        $client->get('https://example.com?a=b', ['auth' => ['testuser', 'testpass', $auth]]);
    }

    /**
     * @testWith ["digest"]
     *           ["ntlm"]
     */
    public function testRemoveCurlAuthorizationOptionsOnRedirectCrossSchemeSamePort(string $auth): void
    {
        if (!defined('\CURLOPT_HTTPAUTH')) {
            self::markTestSkipped('ext-curl is required for this test');
        }

        $mock = new MockHandler([
            new Response(302, ['Location' => 'http://example.com:80?a=b']),
            static function (RequestInterface $request, array $options): ResponseInterface {
                self::assertFalse(
                    isset($options['curl'][\CURLOPT_HTTPAUTH]),
                    'curl options still contain CURLOPT_HTTPAUTH entry'
                );
                self::assertFalse(
                    isset($options['curl'][\CURLOPT_USERPWD]),
                    'curl options still contain CURLOPT_USERPWD entry'
                );

                return new Response(200);
            },
        ]);
        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);
        $client->get('https://example.com?a=b', ['auth' => ['testuser', 'testpass', $auth]]);
    }

    /**
     * @testWith ["digest"]
     *           ["ntlm"]
     */
    public function testNotRemoveCurlAuthorizationOptionsOnRedirect(string $auth): void
    {
        if (!defined('\CURLOPT_HTTPAUTH') || !defined('\CURLOPT_USERPWD')) {
            self::markTestSkipped('ext-curl is required for this test');
        }

        $mock = new MockHandler([
            new Response(302, ['Location' => 'http://example.com/2']),
            static function (RequestInterface $request, array $options): ResponseInterface {
                self::assertTrue(
                    isset($options['curl'][\CURLOPT_HTTPAUTH]),
                    'curl options does not contain expected CURLOPT_HTTPAUTH entry'
                );
                self::assertTrue(
                    isset($options['curl'][\CURLOPT_USERPWD]),
                    'curl options does not contain expected CURLOPT_USERPWD entry'
                );

                return new Response(200);
            },
        ]);
        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);
        $client->get('http://example.com?a=b', ['auth' => ['testuser', 'testpass', $auth]]);
    }

    /**
     * @dataProvider crossOriginRedirectProvider
     */
    public function testAuthOptionTreatmentOnRedirect(string $originalUri, string $targetUri, bool $isCrossOrigin): void
    {
        $auth = ['testuser', 'testpass'];

        $mock = new MockHandler([
            new Response(302, ['Location' => $targetUri]),
            static function (RequestInterface $request, array $options) use ($auth, $isCrossOrigin): ResponseInterface {
                if ($isCrossOrigin) {
                    self::assertArrayNotHasKey('auth', $options);
                } else {
                    self::assertArrayHasKey('auth', $options);
                    self::assertSame($auth, $options['auth']);
                }

                return new Response(200);
            },
        ]);
        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);
        $client->get($originalUri, ['auth' => $auth]);
    }

    public static function crossOriginRedirectProvider(): array
    {
        return [
            ['http://example.com/123', 'http://example.com/', false],
            ['http://example.com/123', 'http://example.com:80/', false],
            ['http://example.com:80/123', 'http://example.com/', false],
            ['http://example.com:80/123', 'http://example.com:80/', false],
            ['http://example.com/123', 'https://example.com/', true],
            ['http://example.com/123', 'http://www.example.com/', true],
            ['http://example.com/123', 'http://example.com:81/', true],
            ['http://example.com:80/123', 'http://example.com:81/', true],
            ['https://example.com/123', 'https://example.com/', false],
            ['https://example.com/123', 'https://example.com:443/', false],
            ['https://example.com:443/123', 'https://example.com/', false],
            ['https://example.com:443/123', 'https://example.com:443/', false],
            ['https://example.com/123', 'http://example.com/', true],
            ['https://example.com/123', 'https://www.example.com/', true],
            ['https://example.com/123', 'https://example.com:444/', true],
            ['https://example.com:443/123', 'https://example.com:444/', true],
        ];
    }

    /**
     * @dataProvider crossOriginRedirectProvider
     */
    public function testHeadersTreatmentOnRedirect(string $originalUri, string $targetUri, bool $isCrossOrigin): void
    {
        $mock = new MockHandler([
            new Response(302, ['Location' => $targetUri]),
            static function (RequestInterface $request) use ($isCrossOrigin): ResponseInterface {
                self::assertSame(!$isCrossOrigin, $request->hasHeader('Authorization'));
                self::assertSame(!$isCrossOrigin, $request->hasHeader('Cookie'));

                return new Response(200);
            },
        ]);
        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);
        $client->get($originalUri, ['auth' => ['testuser', 'testpass'], 'headers' => ['Cookie' => 'foo=bar']]);
    }

    public function testNotRemoveAuthorizationHeaderOnRedirect(): void
    {
        $mock = new MockHandler([
            new Response(302, ['Location' => 'http://example.com/2']),
            static function (RequestInterface $request): ResponseInterface {
                self::assertTrue($request->hasHeader('Authorization'));

                return new Response(200);
            },
        ]);
        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);
        $client->get('http://example.com?a=b', ['auth' => ['testuser', 'testpass']]);
    }

    /**
     * Verifies how RedirectMiddleware::modifyRequest() modifies the method and body of a request issued when
     * encountering a redirect response.
     *
     * @dataProvider modifyRequestFollowRequyestMethodAndBodyProvider
     */
    public function testModifyRequestFollowRequestMethodAndBody(
        RequestInterface $request,
        string $expectedFollowRequestMethod
    ): void {
        $redirectMiddleware = new RedirectMiddleware(static function (): void {
        });

        $options = [
            'allow_redirects' => [
                'protocols' => ['http', 'https'],
                'strict' => false,
                'referer' => null,
            ],
        ];

        $modifiedRequest = $redirectMiddleware->modifyRequest($request, $options, new Response());

        self::assertEquals($expectedFollowRequestMethod, $modifiedRequest->getMethod());
        self::assertEquals(0, $modifiedRequest->getBody()->getSize());
    }

    public static function modifyRequestFollowRequyestMethodAndBodyProvider(): array
    {
        return [
            'DELETE' => [
                'request' => new RedirectTestMethodRequest('DELETE', 'http://example.com/'),
                'expectedFollowRequestMethod' => 'GET',
            ],
            'GET' => [
                'request' => new RedirectTestMethodRequest('GET', 'http://example.com/'),
                'expectedFollowRequestMethod' => 'GET',
            ],
            'get' => [
                'request' => new RedirectTestMethodRequest('get', 'http://example.com/'),
                'expectedFollowRequestMethod' => 'GET',
            ],
            'gEt' => [
                'request' => new RedirectTestMethodRequest('gEt', 'http://example.com/'),
                'expectedFollowRequestMethod' => 'GET',
            ],
            'HEAD' => [
                'request' => new RedirectTestMethodRequest('HEAD', 'http://example.com/'),
                'expectedFollowRequestMethod' => 'HEAD',
            ],
            'head' => [
                'request' => new RedirectTestMethodRequest('head', 'http://example.com/'),
                'expectedFollowRequestMethod' => 'GET',
            ],
            'Head' => [
                'request' => new RedirectTestMethodRequest('Head', 'http://example.com/'),
                'expectedFollowRequestMethod' => 'GET',
            ],
            'OPTIONS' => [
                'request' => new RedirectTestMethodRequest('OPTIONS', 'http://example.com/'),
                'expectedFollowRequestMethod' => 'OPTIONS',
            ],
            'options' => [
                'request' => new RedirectTestMethodRequest('options', 'http://example.com/'),
                'expectedFollowRequestMethod' => 'GET',
            ],
            'OpTiOnS' => [
                'request' => new RedirectTestMethodRequest('OpTiOnS', 'http://example.com/'),
                'expectedFollowRequestMethod' => 'GET',
            ],
            'PATCH' => [
                'request' => new RedirectTestMethodRequest('PATCH', 'http://example.com/'),
                'expectedFollowRequestMethod' => 'GET',
            ],
            'patch' => [
                'request' => new RedirectTestMethodRequest('patch', 'http://example.com/'),
                'expectedFollowRequestMethod' => 'GET',
            ],
            'POST' => [
                'request' => new RedirectTestMethodRequest('POST', 'http://example.com/'),
                'expectedFollowRequestMethod' => 'GET',
            ],
            'post' => [
                'request' => new RedirectTestMethodRequest('post', 'http://example.com/'),
                'expectedFollowRequestMethod' => 'GET',
            ],
            'PUT' => [
                'request' => new RedirectTestMethodRequest('PUT', 'http://example.com/'),
                'expectedFollowRequestMethod' => 'GET',
            ],
            'put' => [
                'request' => new RedirectTestMethodRequest('put', 'http://example.com/'),
                'expectedFollowRequestMethod' => 'GET',
            ],
        ];
    }
}

final class RedirectTestRequest extends Request
{
}

final class RedirectTestUri extends Uri
{
}

final class RedirectTestMethodRequest extends Request
{
    /** @var string */
    private $method;

    /**
     * @param string|UriInterface $uri
     */
    public function __construct(string $method, $uri)
    {
        parent::__construct($method, $uri);

        $this->method = $method;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function withMethod($method): RequestInterface
    {
        $new = clone $this;
        $new->method = $method;

        return $new;
    }
}

final class RedirectTestUriFactory implements UriFactoryInterface
{
    /** @var string[] */
    private $uriCalls = [];

    public function createUri(string $uri = ''): UriInterface
    {
        $this->uriCalls[] = $uri;

        return new RedirectTestUri($uri);
    }

    /**
     * @return string[]
     */
    public function uriCalls(): array
    {
        return $this->uriCalls;
    }
}

final class RedirectTestFailingUriFactory implements UriFactoryInterface
{
    public function createUri(string $uri = ''): UriInterface
    {
        throw new \InvalidArgumentException('Factory could not create URI.');
    }
}

final class RedirectTestStream extends Psr7\Stream
{
}

final class RedirectTestStreamFactory implements StreamFactoryInterface
{
    /** @var string[] */
    private $streamCalls = [];

    public function createStream(string $content = ''): StreamInterface
    {
        $this->streamCalls[] = $content;
        $resource = Psr7\Utils::tryFopen('php://temp', 'r+');
        \fwrite($resource, $content);
        \rewind($resource);

        return new RedirectTestStream($resource);
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        return new RedirectTestStream(Psr7\Utils::tryFopen($filename, $mode));
    }

    public function createStreamFromResource($resource): StreamInterface
    {
        return new RedirectTestStream($resource);
    }

    /**
     * @return string[]
     */
    public function streamCalls(): array
    {
        return $this->streamCalls;
    }
}
