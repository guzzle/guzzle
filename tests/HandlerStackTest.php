<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests;

use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class HandlerStackTest extends TestCase
{
    public function testSetsHandlerInCtor(): void
    {
        $f = static function (): void {
        };
        $m1 = static function (): void {
        };
        $h = new HandlerStack($f, [$m1]);
        self::assertTrue($h->hasHandler());
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testCanSetDifferentHandlerAfterConstruction(): void
    {
        $f = static function (): void {
        };
        $h = new HandlerStack();
        $h->setHandler($f);
        $h->resolve();
    }

    public function testEnsuresHandlerIsSet(): void
    {
        $this->expectException(\LogicException::class);

        $h = new HandlerStack();
        $h->resolve();
    }

    public function testResolveRejectsNonCallableHandler(): void
    {
        $stack = new HandlerStack();
        $handler = new \ReflectionProperty($stack, 'handler');
        $handler->setValue($stack, 'id');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Handler must be callable');

        $stack->resolve();
    }

    public function testResolveRejectsNonCallableMiddleware(): void
    {
        $stack = new HandlerStack(static function (string $value): string {
            return $value;
        });
        $middleware = new \ReflectionProperty($stack, 'stack');
        $middleware->setValue($stack, [[null, 'bad']]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Middleware must be callable');

        $stack->resolve();
    }

    public function testResolveRejectsMiddlewareReturningNonCallable(): void
    {
        $stack = new HandlerStack(static function (string $value): string {
            return $value;
        });
        $stack->push(static function (callable $next): string {
            return 'not callable';
        });

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Middleware must return a callable');

        $stack->resolve();
    }

    public function testPushInOrder(): void
    {
        $meths = $this->getFunctions();
        $builder = new HandlerStack();
        $builder->setHandler($meths[1]);
        $builder->push($meths[2]);
        $builder->push($meths[3]);
        $builder->push($meths[4]);
        $composed = $builder->resolve();
        self::assertSame('Hello - test123', $composed('test'));
        self::assertSame(
            [['a', 'test'], ['b', 'test1'], ['c', 'test12']],
            $meths[0]
        );
    }

    public function testUnshiftsInReverseOrder(): void
    {
        $meths = $this->getFunctions();
        $builder = new HandlerStack();
        $builder->setHandler($meths[1]);
        $builder->unshift($meths[2]);
        $builder->unshift($meths[3]);
        $builder->unshift($meths[4]);
        $composed = $builder->resolve();
        self::assertSame('Hello - test321', $composed('test'));
        self::assertSame(
            [['c', 'test'], ['b', 'test3'], ['a', 'test32']],
            $meths[0]
        );
    }

    public function testCanRemoveMiddlewareByInstance(): void
    {
        $meths = $this->getFunctions();
        $builder = new HandlerStack();
        $builder->setHandler($meths[1]);
        $builder->push($meths[2]);
        $builder->push($meths[2]);
        $builder->push($meths[3]);
        $builder->push($meths[4]);
        $builder->push($meths[2]);
        $builder->remove($meths[3]);
        $composed = $builder->resolve();
        self::assertSame('Hello - test1131', $composed('test'));
    }

    public function testCanRemoveMiddlewareByCallableStringName(): void
    {
        $meths = $this->getFunctions();
        $builder = new HandlerStack();
        $builder->setHandler($meths[1]);
        $builder->push($meths[2], 'strlen');

        $builder->remove('strlen');

        $composed = $builder->resolve();
        self::assertSame('Hello - test', $composed('test'));
        self::assertSame([], $meths[0]);
    }

    public function testCanRemoveMiddlewareByCallableStringInstance(): void
    {
        $builder = new HandlerStack();
        $builder->setHandler(static function (string $value): string {
            return 'Hello - '.$value;
        });
        $builder->push(__CLASS__.'::addSuffixMiddleware');

        $builder->remove(__CLASS__.'::addSuffixMiddleware');

        $composed = $builder->resolve();
        self::assertSame('Hello - test', $composed('test'));
    }

    public function testRemovePrefersNameWhenStringIsAlsoCallable(): void
    {
        $meths = $this->getFunctions();
        $name = __CLASS__.'::addSuffixMiddleware';

        $builder = new HandlerStack();
        $builder->setHandler($meths[1]);
        $builder->push($meths[2], $name);
        $builder->push($name);

        $builder->remove($name);

        $composed = $builder->resolve();
        self::assertSame('Hello - testx', $composed('test'));
        self::assertSame([], $meths[0]);
    }

    public function testCanAddBeforeByName(): void
    {
        $meths = $this->getFunctions();
        $builder = new HandlerStack();
        $builder->setHandler($meths[1]);
        $builder->push($meths[2], 'foo');
        $builder->before('foo', $meths[3], 'baz');
        $builder->before('baz', $meths[4], 'bar');
        $builder->before('baz', $meths[4], 'qux');

        $composed = $builder->resolve();
        self::assertSame('Hello - test3321', $composed('test'));
        self::assertSame(
            [['c', 'test'], ['c', 'test3'], ['b', 'test33'], ['a', 'test332']],
            $meths[0]
        );
    }

    public function testEnsuresHandlerExistsByName(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $builder = new HandlerStack();
        $builder->before('foo', static function (): void {
        });
    }

    public function testCanAddAfterByName(): void
    {
        $meths = $this->getFunctions();
        $builder = new HandlerStack();
        $builder->setHandler($meths[1]);
        $builder->push($meths[2], 'a');
        $builder->push($meths[3], 'b');
        $builder->after('a', $meths[4], 'c');
        $builder->after('b', $meths[4], 'd');

        $composed = $builder->resolve();
        self::assertSame('Hello - test1323', $composed('test'));
        self::assertSame(
            [['a', 'test'], ['c', 'test1'], ['b', 'test13'], ['c', 'test132']],
            $meths[0]
        );
    }

    public function testPicksUpCookiesFromRedirects(): void
    {
        $mock = new MockHandler([
            new Response(301, [
                'Location' => 'http://foo.com/baz',
                'Set-Cookie' => 'foo=bar; Domain=foo.com',
            ]),
            new Response(200),
        ]);
        $handler = HandlerStack::create($mock);
        $request = new Request('GET', 'http://foo.com/bar');
        $jar = new CookieJar();
        $response = $handler($request, [
            'allow_redirects' => true,
            'cookies' => $jar,
        ])->wait();
        self::assertSame(200, $response->getStatusCode());
        $lastRequest = $mock->getLastRequest();
        self::assertSame('http://foo.com/baz', (string) $lastRequest->getUri());
        self::assertSame('foo=bar', $lastRequest->getHeaderLine('Cookie'));
    }

    /**
     * @return array{0: array<int, array{string, string}>, 1: callable, 2: callable, 3: callable, 4: callable}
     */
    private function getFunctions(): array
    {
        $calls = [];

        $a = static function (callable $next) use (&$calls): callable {
            return static function (string $v) use ($next, &$calls): string {
                $calls[] = ['a', $v];

                return $next($v.'1');
            };
        };

        $b = static function (callable $next) use (&$calls): callable {
            return static function (string $v) use ($next, &$calls): string {
                $calls[] = ['b', $v];

                return $next($v.'2');
            };
        };

        $c = static function (callable $next) use (&$calls): callable {
            return static function (string $v) use ($next, &$calls): string {
                $calls[] = ['c', $v];

                return $next($v.'3');
            };
        };

        $handler = static function (string $v): string {
            return 'Hello - '.$v;
        };

        return [&$calls, $handler, $a, $b, $c];
    }

    public static function foo(): void
    {
    }

    public static function addSuffixMiddleware(callable $handler): callable
    {
        return static function (string $value) use ($handler): string {
            return $handler($value.'x');
        };
    }

    public function bar(): void
    {
    }
}
