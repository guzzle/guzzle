<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\InvalidArgumentException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class RetryMiddlewareTest extends TestCase
{
    public function testRetriesWhenDeciderReturnsTrue(): void
    {
        $delayCalls = 0;
        $calls = [];
        $decider = static function (...$args) use (&$calls): bool {
            $calls[] = $args;

            return \count($calls) < 3;
        };
        $delay = static function (int $retries, ?ResponseInterface $response, RequestInterface $request) use (&$delayCalls): int {
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

    public function testRetriesWithOneArgumentDelayCallable(): void
    {
        $delayCalls = [];
        $decider = static function (int $retries): bool {
            return $retries < 1;
        };
        $delay = static function (int $retries) use (&$delayCalls): int {
            $delayCalls[] = $retries;

            return 1;
        };

        $m = Middleware::retry($decider, $delay);
        $h = new MockHandler([new Response(200), new Response(201)]);
        $c = new Client(['handler' => $m($h)]);

        self::assertSame(201, $c->send(new Request('GET', 'http://test.com'))->getStatusCode());
        self::assertSame([1], $delayCalls);
    }

    public function testRetriesWithInternalOneArgumentDelayCallable(): void
    {
        $decider = static function (int $retries): bool {
            return $retries < 1;
        };

        $m = Middleware::retry($decider, 'abs');
        $h = new MockHandler([new Response(200), new Response(201)]);
        $c = new Client(['handler' => $m($h)]);

        self::assertSame(201, $c->send(new Request('GET', 'http://test.com'))->getStatusCode());
    }

    public function testRetriesWithVariadicDelayCallableReceivesContext(): void
    {
        $delayArgs = [];
        $decider = static function (int $retries): bool {
            return $retries < 1;
        };
        $delay = static function (...$args) use (&$delayArgs): int {
            $delayArgs = $args;

            return 1;
        };

        $m = Middleware::retry($decider, $delay);
        $h = new MockHandler([new Response(200), new Response(201)]);
        $c = new Client(['handler' => $m($h)]);

        $c->send(new Request('GET', 'http://test.com'));

        self::assertCount(3, $delayArgs);
        self::assertSame(1, $delayArgs[0]);
        self::assertInstanceOf(Response::class, $delayArgs[1]);
        self::assertInstanceOf(Request::class, $delayArgs[2]);
    }

    public function testDoesNotRetryWhenDeciderReturnsFalse(): void
    {
        $decider = static function (): bool {
            return false;
        };
        $m = Middleware::retry($decider);
        $h = new MockHandler([new Response(200)]);
        $c = new Client(['handler' => $m($h)]);
        $p = $c->sendAsync(new Request('GET', 'http://test.com'), []);
        self::assertSame(200, $p->wait()->getStatusCode());
    }

    public function testRejectsNonIntegerRetriesOption(): void
    {
        $decider = static function (int $retries): bool {
            return false;
        };
        $m = Middleware::retry($decider);
        $h = new MockHandler([new Response(200)]);
        $c = new Client(['handler' => $m($h)]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Passing string to request option "retries" is invalid; expected int.');

        $c->send(new Request('GET', 'http://test.com'), ['retries' => '0']);
    }

    public function testRejectsNonIntegerRetriesOptionDirectly(): void
    {
        $decider = static function (): bool {
            return false;
        };
        $handler = Middleware::retry($decider)(new MockHandler([new Response(200)]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('retries must be an integer');

        $handler(new Request('GET', 'http://test.com'), ['retries' => '0']);
    }

    public function testCanRetryExceptions(): void
    {
        $calls = [];
        $decider = static function (...$args) use (&$calls): bool {
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

    public function testUsesDefaultExponentialDelay(): void
    {
        $responses = [new Response(500), new Response(500), new Response(200)];
        $delays = [];
        $handler = static function (RequestInterface $request, array $options) use (&$responses, &$delays): PromiseInterface {
            if (isset($options['delay'])) {
                $delays[] = $options['delay'];
            }

            return Create::promiseFor(\array_shift($responses));
        };
        $decider = static function (int $retries): bool {
            return $retries < 2;
        };

        $m = Middleware::retry($decider);
        $p = $m($handler)(new Request('GET', 'http://test.com'), []);

        self::assertSame(200, $p->wait()->getStatusCode());
        self::assertSame([1000, 2000], $delays);
    }
}
