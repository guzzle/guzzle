<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\InvalidArgumentException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Pool;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Server\Server;
use GuzzleHttp\TransferStats;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

class PoolTest extends TestCase
{
    public function testValidatesEachElement(): void
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
    public function testSendsAndRealizesFuture(): void
    {
        $c = $this->getClient();
        $p = new Pool($c, [new Request('GET', 'http://example.com')]);
        $p->promise()->wait();
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testExecutesPendingWhenWaiting(): void
    {
        $r1 = new Promise(static function () use (&$r1): void {
            $r1->resolve(new Response());
        });
        $r2 = new Promise(static function () use (&$r2): void {
            $r2->resolve(new Response());
        });
        $r3 = new Promise(static function () use (&$r3): void {
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

    public function testUsesRequestOptions(): void
    {
        $h = [];
        $handler = new MockHandler([
            static function (RequestInterface $request) use (&$h): ResponseInterface {
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

    public function testOnHeadersOptionReceivesCurrentPoolRequest(): void
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
                    RequestInterface $request
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

    public function testOnHeadersOptionReceivesStringPoolKey(): void
    {
        $requests = [
            'key_one' => new Request('GET', 'http://example.com/one'),
            'key_two' => new Request('GET', 'http://example.com/two'),
        ];
        $handler = new MockHandler([
            new Response(200, ['X-Num' => '1']),
            new Response(200, ['X-Num' => '2']),
        ]);
        $client = new Client(['handler' => $handler]);
        $seen = [];

        $pool = new Pool($client, $requests, [
            'concurrency' => 1,
            'options' => [
                'on_headers' => static function (
                    ResponseInterface $response,
                    RequestInterface $request,
                    $key
                ) use (&$seen): void {
                    $seen[$key] = $response->getHeaderLine('X-Num');
                },
            ],
        ]);

        $pool->promise()->wait();

        self::assertSame(['key_one' => '1', 'key_two' => '2'], $seen);
    }

    public function testOnHeadersOptionReceivesIntegerPoolKey(): void
    {
        $requests = [
            new Request('GET', 'http://example.com/one'),
            new Request('GET', 'http://example.com/two'),
        ];
        $handler = new MockHandler([
            new Response(200),
            new Response(200),
        ]);
        $client = new Client(['handler' => $handler]);
        $seen = [];

        $pool = new Pool($client, $requests, [
            'concurrency' => 1,
            'options' => [
                'on_headers' => static function (
                    ResponseInterface $response,
                    RequestInterface $request,
                    $key
                ) use (&$seen): void {
                    $seen[] = $key;
                },
            ],
        ]);

        $pool->promise()->wait();

        self::assertSame([0, 1], $seen);
    }

    public function testOnHeadersOptionKeepsPoolKeyForConcurrentPendingRequests(): void
    {
        $first = new Promise();
        $second = new Promise();
        $handler = new MockHandler([$first, $second]);
        $client = new Client(['handler' => $handler]);
        $seen = [];

        $pool = new Pool($client, [
            'first' => new Request('GET', 'http://example.com/first'),
            'second' => new Request('GET', 'http://example.com/second'),
        ], [
            'concurrency' => 2,
            'options' => [
                'on_headers' => static function (
                    ResponseInterface $response,
                    RequestInterface $request,
                    $key
                ) use (&$seen): void {
                    $seen[] = [$key, $response->getHeaderLine('X-Request')];
                },
            ],
        ]);

        $promise = $pool->promise();

        $first->resolve(new Response(200, ['X-Request' => 'first']));
        $second->resolve(new Response(200, ['X-Request' => 'second']));
        $promise->wait();

        self::assertSame([
            ['first', 'first'],
            ['second', 'second'],
        ], $seen);
    }

    public function testOnHeadersOptionReceivesPoolKeyWithCustomHandler(): void
    {
        $capturedKey = null;
        $customHandler = static function (RequestInterface $request, array $options): ResponseInterface {
            $response = new Response(200);
            if (isset($options['on_headers'])) {
                ($options['on_headers'])($response, $request);
            }

            return $response;
        };
        $client = new Client(['handler' => $customHandler]);

        $requests = ['my_key' => new Request('GET', 'http://example.com')];
        $pool = new Pool($client, $requests, [
            'options' => [
                'on_headers' => static function (
                    ResponseInterface $response,
                    RequestInterface $request,
                    $key
                ) use (&$capturedKey): void {
                    $capturedKey = $key;
                },
            ],
        ]);

        $pool->promise()->wait();

        self::assertSame('my_key', $capturedKey);
    }

    public function testOnHeadersOptionReceivesPoolKeyAcrossRedirects(): void
    {
        $handler = new MockHandler([
            new Response(301, ['Location' => 'http://example.com/next']),
            new Response(200),
        ]);
        $client = new Client(['handler' => HandlerStack::create($handler)]);
        $seen = [];

        $pool = new Pool($client, ['redirect_key' => new Request('GET', 'http://example.com')], [
            'options' => [
                'on_headers' => static function (
                    ResponseInterface $response,
                    RequestInterface $request,
                    $key
                ) use (&$seen): void {
                    $seen[] = $key;
                },
            ],
        ]);

        $pool->promise()->wait();

        // on_headers fires once per handler dispatch: the 301 and the final 200.
        self::assertSame(['redirect_key', 'redirect_key'], $seen);
    }

    public function testOnTrailersOptionReceivesPoolKey(): void
    {
        $seen = [];
        $customHandler = static function (RequestInterface $request, array $options): ResponseInterface {
            $response = new Response(200);
            if (isset($options['on_trailers'])) {
                ($options['on_trailers'])(['x-checksum' => ['abc']], $response, $request);
            }

            return $response;
        };
        $client = new Client(['handler' => $customHandler]);

        $requests = ['trailer_key' => new Request('GET', 'http://example.com')];
        $pool = new Pool($client, $requests, [
            'options' => [
                'on_trailers' => static function (
                    array $trailers,
                    ResponseInterface $response,
                    RequestInterface $request,
                    $key
                ) use (&$seen): void {
                    $seen[$key] = $trailers;
                },
            ],
        ]);

        $pool->promise()->wait();

        self::assertSame(['trailer_key' => ['x-checksum' => ['abc']]], $seen);
    }

    public function testOnStatsOptionReceivesPoolKey(): void
    {
        $handler = new MockHandler([new Response(200)]);
        $client = new Client(['handler' => $handler]);
        $seen = [];

        $pool = new Pool($client, ['stats_key' => new Request('GET', 'http://example.com')], [
            'options' => [
                'on_stats' => static function (TransferStats $stats, $key) use (&$seen): void {
                    $seen[] = $key;
                },
            ],
        ]);

        $pool->promise()->wait();

        self::assertSame(['stats_key'], $seen);
    }

    public function testProgressOptionReceivesPoolKeyAndForwardsReturn(): void
    {
        $seen = [];
        $progressReturn = null;
        $customHandler = static function (RequestInterface $request, array $options) use (&$progressReturn): ResponseInterface {
            if (isset($options['progress'])) {
                $progressReturn = ($options['progress'])(100, 50, 20, 10);
            }

            return new Response(200);
        };
        $client = new Client(['handler' => $customHandler]);

        $pool = new Pool($client, ['progress_key' => new Request('GET', 'http://example.com')], [
            'options' => [
                'progress' => static function (
                    int $downloadTotal,
                    int $downloadedBytes,
                    int $uploadTotal,
                    int $uploadedBytes,
                    $key
                ) use (&$seen) {
                    $seen[] = [$downloadTotal, $downloadedBytes, $uploadTotal, $uploadedBytes, $key];

                    return 0;
                },
            ],
        ]);

        $pool->promise()->wait();

        self::assertSame([[100, 50, 20, 10, 'progress_key']], $seen);
        self::assertSame(0, $progressReturn);
    }

    public function testOnRedirectOptionReceivesPoolKey(): void
    {
        $handler = new MockHandler([
            new Response(301, ['Location' => 'http://example.com/next']),
            new Response(200),
        ]);
        $client = new Client(['handler' => HandlerStack::create($handler)]);
        $seen = [];

        $pool = new Pool($client, ['redirect_key' => new Request('GET', 'http://example.com')], [
            'options' => [
                'allow_redirects' => [
                    'on_redirect' => static function (
                        RequestInterface $request,
                        ResponseInterface $response,
                        UriInterface $uri,
                        $key
                    ) use (&$seen): void {
                        $seen[] = [$key, (string) $uri];
                    },
                ],
            ],
        ]);

        $pool->promise()->wait();

        self::assertSame([['redirect_key', 'http://example.com/next']], $seen);
    }

    public function testOnHeadersOptionReceivesPoolKeyForCallableRequests(): void
    {
        $handler = new MockHandler([new Response(200)]);
        $client = new Client(['handler' => $handler]);
        $seen = [];

        $requests = [
            'lazy' => static function (array $options) use ($client): PromiseInterface {
                return $client->sendAsync(new Request('GET', 'http://example.com'), $options);
            },
        ];

        $pool = new Pool($client, $requests, [
            'options' => [
                'on_headers' => static function (
                    ResponseInterface $response,
                    RequestInterface $request,
                    $key
                ) use (&$seen): void {
                    $seen[] = $key;
                },
            ],
        ]);

        $pool->promise()->wait();

        self::assertSame(['lazy'], $seen);
    }

    public static function nonCallablePoolObserverOptionProvider(): iterable
    {
        yield 'on_headers' => [
            ['on_headers' => 'not-a-callable'],
            'Passing string to request option "on_headers" is invalid; expected callable.',
        ];

        yield 'on_stats' => [
            ['on_stats' => 'not-a-callable'],
            'Passing string to request option "on_stats" is invalid; expected callable.',
        ];

        yield 'on_trailers' => [
            ['on_trailers' => 'not-a-callable'],
            'Passing string to request option "on_trailers" is invalid; expected callable.',
        ];

        yield 'progress' => [
            ['progress' => 'not-a-callable'],
            'Passing string to request option "progress" is invalid; expected callable.',
        ];

        yield 'allow_redirects.on_redirect' => [
            ['allow_redirects' => ['on_redirect' => 'not-a-callable']],
            'Passing string to request option "allow_redirects.on_redirect" is invalid; expected callable.',
        ];
    }

    /**
     * @dataProvider nonCallablePoolObserverOptionProvider
     */
    public function testNonCallableObserverOptionsStillRejectedWhenPooled(array $options, string $message): void
    {
        $handler = new MockHandler([new Response(200)]);
        $client = new Client(['handler' => $handler]);

        $pool = new Pool($client, [new Request('GET', 'http://example.com')], [
            'options' => $options,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $pool->promise()->wait();
    }

    public function testCanProvideCallablesThatReturnResponses(): void
    {
        $h = [];
        $handler = new MockHandler([
            static function (RequestInterface $request) use (&$h): ResponseInterface {
                $h[] = $request;

                return new Response();
            },
        ]);
        $c = new Client(['handler' => $handler]);
        $optHistory = [];
        $fn = static function (array $opts) use (&$optHistory, $c): ResponseInterface {
            $optHistory = $opts;

            return $c->request('GET', 'http://example.com', $opts);
        };
        $opts = ['options' => ['headers' => ['x-foo' => 'bar']]];
        $p = new Pool($c, [$fn], $opts);
        $p->promise()->wait();
        self::assertSame($opts['options'], $optHistory);
        self::assertCount(1, $h);
        self::assertTrue($h[0]->hasHeader('x-foo'));
    }

    public function testCanProvideCallablesThatReturnResponsePromises(): void
    {
        $h = [];
        $handler = new MockHandler([
            static function (RequestInterface $request) use (&$h): ResponseInterface {
                $h[] = $request;

                return new Response();
            },
        ]);
        $c = new Client(['handler' => $handler]);
        $optHistory = [];
        $fn = static function (array $opts) use (&$optHistory, $c): PromiseInterface {
            $optHistory = $opts;

            return $c->requestAsync('GET', 'http://example.com', $opts);
        };
        $opts = ['options' => ['headers' => ['x-foo' => 'bar']]];
        $p = new Pool($c, [$fn], $opts);
        $p->promise()->wait();
        self::assertSame($opts['options'], $optHistory);
        self::assertCount(1, $h);
        self::assertTrue($h[0]->hasHeader('x-foo'));
    }

    public function testConstructorCallbacksCanReceiveAggregatePromise(): void
    {
        $reason = new \RuntimeException('failed');
        $client = new Client(['handler' => new MockHandler([new Response(200), $reason])]);
        $seen = [];

        $pool = new Pool($client, [
            'ok' => new Request('GET', 'http://example.com/ok'),
            'fail' => new Request('GET', 'http://example.com/fail'),
        ], [
            'fulfilled' => static function (ResponseInterface $response, string $index, PromiseInterface $aggregate) use (&$seen): void {
                $seen['fulfilled'] = [$index, $response->getStatusCode(), $aggregate instanceof PromiseInterface];
            },
            'rejected' => static function ($value, string $index, PromiseInterface $aggregate) use (&$seen): void {
                $seen['rejected'] = [$index, $value->getMessage(), $aggregate instanceof PromiseInterface];
            },
        ]);

        $pool->promise()->wait(false);

        self::assertSame(['ok', 200, true], $seen['fulfilled']);
        self::assertSame(['fail', 'failed', true], $seen['rejected']);
    }

    public function testBatchCallbacksCanReceiveResultAndIndex(): void
    {
        $reason = new \RuntimeException('failed');
        $client = new Client(['handler' => new MockHandler([new Response(200), $reason])]);
        $seen = [];

        $results = Pool::batch($client, [
            'ok' => new Request('GET', 'http://example.com/ok'),
            'fail' => new Request('GET', 'http://example.com/fail'),
        ], [
            'fulfilled' => static function (ResponseInterface $response, string $index) use (&$seen): void {
                $seen['fulfilled'] = [$index, $response->getStatusCode()];
            },
            'rejected' => static function ($value, string $index) use (&$seen): void {
                $seen['rejected'] = [$index, $value->getMessage()];
            },
        ]);

        self::assertSame(['ok', 200], $seen['fulfilled']);
        self::assertSame(['fail', 'failed'], $seen['rejected']);
        self::assertInstanceOf(ResponseInterface::class, $results['ok']);
        self::assertSame($reason, $results['fail']);
    }

    public function testBatchesResults(): void
    {
        $requests = [
            new Request('GET', 'http://foo.com/200'),
            new Request('GET', 'http://foo.com/201'),
            new Request('GET', 'http://foo.com/202'),
            new Request('GET', 'http://foo.com/404'),
        ];
        $fn = static function (RequestInterface $request): ResponseInterface {
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

    public function testBatchesResultsWithCallbacks(): void
    {
        $requests = [
            new Request('GET', 'http://foo.com/200'),
            new Request('GET', 'http://foo.com/201'),
        ];
        $mock = new MockHandler([
            static function (RequestInterface $request): ResponseInterface {
                return new Response((int) \substr($request->getUri()->getPath(), 1));
            },
        ]);
        $client = new Client(['handler' => $mock]);
        $results = Pool::batch($client, $requests, [
            'fulfilled' => static function (ResponseInterface $value) use (&$called): void {
                $called = true;
            },
        ]);
        self::assertCount(2, $results);
        self::assertTrue($called);
    }

    public function testUsesYieldedKeyInFulfilledCallback(): void
    {
        $r1 = new Promise(static function () use (&$r1): void {
            $r1->resolve(new Response());
        });
        $r2 = new Promise(static function () use (&$r2): void {
            $r2->resolve(new Response());
        });
        $r3 = new Promise(static function () use (&$r3): void {
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
            'fulfilled' => static function (ResponseInterface $res, string $index) use (&$keys): void {
                $keys[] = $index;
            },
        ]);
        $p->promise()->wait();
        self::assertCount(3, $keys);
        self::assertSame($keys, \array_keys($requests));
    }

    public function testPoolHandlesInvalidResponseStatusAsResponseLessRejection(): void
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
            self::assertNotInstanceOf(ResponseException::class, $reason);
        }
    }

    private function getClient(int $total = 1): Client
    {
        $queue = [];
        for ($i = 0; $i < $total; ++$i) {
            $queue[] = new Response();
        }
        $handler = new MockHandler($queue);

        return new Client(['handler' => $handler]);
    }
}
