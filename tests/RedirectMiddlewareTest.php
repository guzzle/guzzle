<?php

namespace GuzzleHttp\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\TooManyRedirectsException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RedirectMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * @covers \GuzzleHttp\RedirectMiddleware
 */
class RedirectMiddlewareTest extends TestCase
{
    public function testIgnoresNonRedirects()
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

    public function testIgnoresWhenNoLocation()
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

    public function testRedirectsWithAbsoluteUri()
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

    public function testRedirectsWithRelativeUri()
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

    public function testLimitsToMaxRedirects()
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

    public function testTooManyRedirectsExceptionHasResponse()
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
        } catch (\GuzzleHttp\Exception\TooManyRedirectsException $e) {
            self::assertSame(302, $e->getResponse()->getStatusCode());
        }
    }

    public function testEnsuresProtocolIsValid()
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

    public function testAddsRefererHeader()
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

    public function testAddsRefererHeaderButClearsUserInfo()
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

    public function testAddsGuzzleRedirectHeader()
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

    public function testAddsGuzzleRedirectStatusHeader()
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

    public function testDoesNotAddRefererWhenGoingFromHttpsToHttp()
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

    public function testInvokesOnRedirectForRedirects()
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
                'on_redirect' => static function ($request, $response, $uri) use (&$call) {
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
    public function testRemoveCurlAuthorizationOptionsOnRedirectCrossHost($auth)
    {
        if (!defined('\CURLOPT_HTTPAUTH')) {
            self::markTestSkipped('ext-curl is required for this test');
        }

        $mock = new MockHandler([
            new Response(302, ['Location' => 'http://test.com']),
            static function (RequestInterface $request, $options) {
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
    public function testRemoveCurlAuthorizationOptionsOnRedirectCrossPort($auth)
    {
        if (!defined('\CURLOPT_HTTPAUTH')) {
            self::markTestSkipped('ext-curl is required for this test');
        }

        $mock = new MockHandler([
            new Response(302, ['Location' => 'http://example.com:81/']),
            static function (RequestInterface $request, $options) {
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
    public function testRemoveCurlAuthorizationOptionsOnRedirectCrossScheme($auth)
    {
        if (!defined('\CURLOPT_HTTPAUTH')) {
            self::markTestSkipped('ext-curl is required for this test');
        }

        $mock = new MockHandler([
            new Response(302, ['Location' => 'http://example.com?a=b']),
            static function (RequestInterface $request, $options) {
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
    public function testRemoveCurlAuthorizationOptionsOnRedirectCrossSchemeSamePort($auth)
    {
        if (!defined('\CURLOPT_HTTPAUTH')) {
            self::markTestSkipped('ext-curl is required for this test');
        }

        $mock = new MockHandler([
            new Response(302, ['Location' => 'http://example.com:80?a=b']),
            static function (RequestInterface $request, $options) {
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
    public function testNotRemoveCurlAuthorizationOptionsOnRedirect($auth)
    {
        if (!defined('\CURLOPT_HTTPAUTH') || !defined('\CURLOPT_USERPWD')) {
            self::markTestSkipped('ext-curl is required for this test');
        }

        $mock = new MockHandler([
            new Response(302, ['Location' => 'http://example.com/2']),
            static function (RequestInterface $request, $options) {
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

    public function crossOriginRedirectProvider()
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
    public function testHeadersTreatmentOnRedirect($originalUri, $targetUri, $isCrossOrigin)
    {
        $mock = new MockHandler([
            new Response(302, ['Location' => $targetUri]),
            static function (RequestInterface $request) use ($isCrossOrigin) {
                self::assertSame(!$isCrossOrigin, $request->hasHeader('Authorization'));
                self::assertSame(!$isCrossOrigin, $request->hasHeader('Cookie'));

                return new Response(200);
            },
        ]);
        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);
        $client->get($originalUri, ['auth' => ['testuser', 'testpass'], 'headers' => ['Cookie' => 'foo=bar']]);
    }

    public function testNotRemoveAuthorizationHeaderOnRedirect()
    {
        $mock = new MockHandler([
            new Response(302, ['Location' => 'http://example.com/2']),
            static function (RequestInterface $request) {
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
     *
     * @param string $expectedFollowRequestMethod
     */
    public function testModifyRequestFollowRequestMethodAndBody(
        RequestInterface $request,
        $expectedFollowRequestMethod
    ) {
        $redirectMiddleware = new RedirectMiddleware(static function () {
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

    /**
     * @return array
     */
    public function modifyRequestFollowRequyestMethodAndBodyProvider()
    {
        return [
            'DELETE' => [
                'request' => new Request('DELETE', 'http://example.com/'),
                'expectedFollowRequestMethod' => 'GET',
            ],
            'GET' => [
                'request' => new Request('GET', 'http://example.com/'),
                'expectedFollowRequestMethod' => 'GET',
            ],
            'HEAD' => [
                'request' => new Request('HEAD', 'http://example.com/'),
                'expectedFollowRequestMethod' => 'HEAD',
            ],
            'OPTIONS' => [
                'request' => new Request('OPTIONS', 'http://example.com/'),
                'expectedFollowRequestMethod' => 'OPTIONS',
            ],
            'PATCH' => [
                'request' => new Request('PATCH', 'http://example.com/'),
                'expectedFollowRequestMethod' => 'GET',
            ],
            'POST' => [
                'request' => new Request('POST', 'http://example.com/'),
                'expectedFollowRequestMethod' => 'GET',
            ],
            'PUT' => [
                'request' => new Request('PUT', 'http://example.com/'),
                'expectedFollowRequestMethod' => 'GET',
            ],
        ];
    }
}
