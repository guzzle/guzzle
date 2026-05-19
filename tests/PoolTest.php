<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Pool;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Server\Server;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class PoolTest extends TestCase
{
    public function testValidatesIterable()
    {
        $p = new Pool(new Client(), 'foo');

        $this->expectException(\InvalidArgumentException::class);
        $p->promise()->wait();
    }

    public function testValidatesEachElement()
    {
        $c = new Client();
        $requests = ['foo'];
        $p = new Pool($c, new \ArrayIterator($requests));

        $this->expectException(\InvalidArgumentException::class);
        $p->promise()->wait();
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testSendsAndRealizesFuture()
    {
        $c = $this->getClient();
        $p = new Pool($c, [new Request('GET', 'http://example.com')]);
        $p->promise()->wait();
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testExecutesPendingWhenWaiting()
    {
        $r1 = new Promise(static function () use (&$r1) {
            $r1->resolve(new Response());
        });
        $r2 = new Promise(static function () use (&$r2) {
            $r2->resolve(new Response());
        });
        $r3 = new Promise(static function () use (&$r3) {
            $r3->resolve(new Response());
        });
        $handler = new MockHandler([$r1, $r2, $r3]);
        $c = new Client(['handler' => $handler]);
        $p = new Pool($c, [
            new Request('GET', 'http://example.com'),
            new Request('GET', 'http://example.com'),
            new Request('GET', 'http://example.com'),
        ], ['pool_size' => 2]);
        $p->promise()->wait();
    }

    public function testUsesRequestOptions()
    {
        $h = [];
        $handler = new MockHandler([
            static function (RequestInterface $request) use (&$h) {
                $h[] = $request;

                return new Response();
            },
        ]);
        $c = new Client(['handler' => $handler]);
        $opts = ['options' => ['headers' => ['x-foo' => 'bar']]];
        $p = new Pool($c, [new Request('GET', 'http://example.com')], $opts);
        $p->promise()->wait();
        self::assertCount(1, $h);
        self::assertTrue($h[0]->hasHeader('x-foo'));
    }

    public function testOnHeadersOptionReceivesCurrentPoolRequest()
    {
        $requests = [
            new Request('GET', 'http://example.com/one'),
            new Request('GET', 'http://example.com/two'),
        ];
        $handler = new MockHandler([
            new Response(200, ['X-Request' => 'one']),
            new Response(200, ['X-Request' => 'two']),
        ]);
        $client = new Client(['handler' => $handler]);
        $seen = [];

        $pool = new Pool($client, $requests, [
            'concurrency' => 1,
            'options' => [
                'on_headers' => static function (
                    ResponseInterface $response,
                    RequestInterface $request,
                ) use (&$seen): void {
                    $seen[] = [
                        (string) $request->getUri(),
                        $response->getHeaderLine('X-Request'),
                    ];
                },
            ],
        ]);

        $pool->promise()->wait();

        self::assertSame([
            ['http://example.com/one', 'one'],
            ['http://example.com/two', 'two'],
        ], $seen);
    }

    public function testCanProvideCallablesThatReturnResponses()
    {
        $h = [];
        $handler = new MockHandler([
            static function (RequestInterface $request) use (&$h) {
                $h[] = $request;

                return new Response();
            },
        ]);
        $c = new Client(['handler' => $handler]);
        $optHistory = [];
        $fn = static function (array $opts) use (&$optHistory, $c) {
            $optHistory = $opts;

            return $c->request('GET', 'http://example.com', $opts);
        };
        $opts = ['options' => ['headers' => ['x-foo' => 'bar']]];
        $p = new Pool($c, [$fn], $opts);
        $p->promise()->wait();
        self::assertCount(1, $h);
        self::assertTrue($h[0]->hasHeader('x-foo'));
    }

    public function testBatchesResults()
    {
        $requests = [
            new Request('GET', 'http://foo.com/200'),
            new Request('GET', 'http://foo.com/201'),
            new Request('GET', 'http://foo.com/202'),
            new Request('GET', 'http://foo.com/404'),
        ];
        $fn = static function (RequestInterface $request) {
            return new Response((int) \substr($request->getUri()->getPath(), 1));
        };
        $mock = new MockHandler([$fn, $fn, $fn, $fn]);
        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);
        $results = Pool::batch($client, $requests);
        self::assertCount(4, $results);
        self::assertSame([0, 1, 2, 3], \array_keys($results));
        self::assertSame(200, $results[0]->getStatusCode());
        self::assertSame(201, $results[1]->getStatusCode());
        self::assertSame(202, $results[2]->getStatusCode());
        self::assertInstanceOf(ClientException::class, $results[3]);
    }

    public function testBatchesResultsWithCallbacks()
    {
        $requests = [
            new Request('GET', 'http://foo.com/200'),
            new Request('GET', 'http://foo.com/201'),
        ];
        $mock = new MockHandler([
            static function (RequestInterface $request) {
                return new Response((int) \substr($request->getUri()->getPath(), 1));
            },
        ]);
        $client = new Client(['handler' => $mock]);
        $results = Pool::batch($client, $requests, [
            'fulfilled' => static function ($value) use (&$called) {
                $called = true;
            },
        ]);
        self::assertCount(2, $results);
        self::assertTrue($called);
    }

    public function testUsesYieldedKeyInFulfilledCallback()
    {
        $r1 = new Promise(static function () use (&$r1) {
            $r1->resolve(new Response());
        });
        $r2 = new Promise(static function () use (&$r2) {
            $r2->resolve(new Response());
        });
        $r3 = new Promise(static function () use (&$r3) {
            $r3->resolve(new Response());
        });
        $handler = new MockHandler([$r1, $r2, $r3]);
        $c = new Client(['handler' => $handler]);
        $keys = [];
        $requests = [
            'request_1' => new Request('GET', 'http://example.com'),
            'request_2' => new Request('GET', 'http://example.com'),
            'request_3' => new Request('GET', 'http://example.com'),
        ];
        $p = new Pool($c, $requests, [
            'pool_size' => 2,
            'fulfilled' => static function ($res, $index) use (&$keys) {
                $keys[] = $index;
            },
        ]);
        $p->promise()->wait();
        self::assertCount(3, $keys);
        self::assertSame($keys, \array_keys($requests));
    }

    public function testPoolHandlesInvalidResponseStatusAsResponseLessRejection()
    {
        $client = new Client([
            'handler' => HandlerStack::create(new CurlMultiHandler()),
        ]);
        $requests = [
            new Request('GET', Server::$url.'guzzle-server/bad-status'),
            new Request('GET', Server::$url.'guzzle-server/bad-status'),
        ];
        $fulfilled = 0;
        $rejected = [];

        $pool = new Pool($client, $requests, [
            'concurrency' => 2,
            'fulfilled' => static function () use (&$fulfilled): void {
                ++$fulfilled;
            },
            'rejected' => static function ($reason) use (&$rejected): void {
                $rejected[] = $reason;
            },
        ]);

        $pool->promise()->wait();

        self::assertSame(0, $fulfilled);
        self::assertCount(2, $rejected);

        foreach ($rejected as $reason) {
            self::assertInstanceOf(RequestException::class, $reason);
            self::assertFalse($reason->hasResponse());
            self::assertNull($reason->getResponse());
            self::assertArrayNotHasKey('http_code', $reason->getHandlerContext());
            self::assertArrayNotHasKey('header_size', $reason->getHandlerContext());
            self::assertArrayNotHasKey('content_type', $reason->getHandlerContext());
        }
    }

    private function getClient($total = 1)
    {
        $queue = [];
        for ($i = 0; $i < $total; ++$i) {
            $queue[] = new Response();
        }
        $handler = new MockHandler($queue);

        return new Client(['handler' => $handler]);
    }
}
