<?php

namespace GuzzleHttp\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RetryMiddleware;
use PHPUnit\Framework\TestCase;

class RetryMiddlewareTest extends TestCase
{
    public function testRetriesWhenDeciderReturnsTrue()
    {
        $delayCalls = 0;
        $calls = [];
        $decider = static function (...$args) use (&$calls) {
            $calls[] = $args;

            return \count($calls) < 3;
        };
        $delay = static function ($retries, $response, $request) use (&$delayCalls) {
            ++$delayCalls;
            self::assertSame($retries, $delayCalls);
            self::assertInstanceOf(Response::class, $response);
            self::assertInstanceOf(Request::class, $request);

            return 1;
        };
        $m = Middleware::retry($decider, $delay);
        $h = new MockHandler([new Response(200), new Response(201), new Response(202)]);
        $f = $m($h);
        $c = new Client(['handler' => $f]);
        $p = $c->sendAsync(new Request('GET', 'http://test.com'), []);
        $p->wait();
        self::assertCount(3, $calls);
        self::assertSame(2, $delayCalls);
        self::assertSame(202, $p->wait()->getStatusCode());
    }

    public function testDoesNotRetryWhenDeciderReturnsFalse()
    {
        $decider = static function () {
            return false;
        };
        $m = Middleware::retry($decider);
        $h = new MockHandler([new Response(200)]);
        $c = new Client(['handler' => $m($h)]);
        $p = $c->sendAsync(new Request('GET', 'http://test.com'), []);
        self::assertSame(200, $p->wait()->getStatusCode());
    }

    public function testCanRetryExceptions()
    {
        $calls = [];
        $decider = static function (...$args) use (&$calls) {
            $calls[] = $args;

            return $args[3] instanceof \Exception;
        };
        $m = Middleware::retry($decider);
        $h = new MockHandler([new \Exception(), new Response(201)]);
        $c = new Client(['handler' => $m($h)]);
        $p = $c->sendAsync(new Request('GET', 'http://test.com'), []);
        self::assertSame(201, $p->wait()->getStatusCode());
        self::assertCount(2, $calls);
        self::assertSame(0, $calls[0][0]);
        self::assertNull($calls[0][2]);
        self::assertInstanceOf('Exception', $calls[0][3]);
        self::assertSame(1, $calls[1][0]);
        self::assertInstanceOf(Response::class, $calls[1][2]);
        self::assertNull($calls[1][3]);
    }

    public function testBackoffCalculateDelay()
    {
        self::assertSame(0, RetryMiddleware::exponentialDelay(0));
        self::assertSame(1000, RetryMiddleware::exponentialDelay(1));
        self::assertSame(2000, RetryMiddleware::exponentialDelay(2));
        self::assertSame(4000, RetryMiddleware::exponentialDelay(3));
        self::assertSame(8000, RetryMiddleware::exponentialDelay(4));
    }
}
