<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\TooManyRedirectsException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\RedirectMiddleware;
use GuzzleHttp\RequestOptions;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
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

        self::assertSame(['/foo'], $factory->uriCalls());
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
