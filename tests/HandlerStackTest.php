<?php
namespace GuzzleHttp\Tests;

use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class HandlerStackTest extends TestCase
{
    public function testSetsHandlerInCtor()
    {
        $f = function () {
        };
        $m1 = function () {
        };
        $h = new HandlerStack($f, [$m1]);
        self::assertTrue($h->hasHandler());
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testCanSetDifferentHandlerAfterConstruction()
    {
        $f = function () {
        };
        $h = new HandlerStack();
        $h->setHandler($f);
        $h->resolve();
    }

    /**
     * @expectedException \LogicException
     */
    public function testEnsuresHandlerIsSet()
    {
        $h = new HandlerStack();
        $h->resolve();
    }

    public function testPushInOrder()
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

    public function testUnshiftsInReverseOrder()
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

    public function testCanRemoveMiddlewareByInstance()
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

    public function testCanPrintMiddleware()
    {
        $meths = $this->getFunctions();
        $builder = new HandlerStack();
        $builder->setHandler($meths[1]);
        $builder->push($meths[2], 'a');
        $builder->push([__CLASS__, 'foo']);
        $builder->push([$this, 'bar']);
        $builder->push(__CLASS__ . '::' . 'foo');
        $lines = explode("\n", (string) $builder);
        self::assertContains("> 4) Name: 'a', Function: callable(", $lines[0]);
        self::assertContains("> 3) Name: '', Function: callable(GuzzleHttp\\Tests\\HandlerStackTest::foo)", $lines[1]);
        self::assertContains("> 2) Name: '', Function: callable(['GuzzleHttp\\Tests\\HandlerStackTest', 'bar'])", $lines[2]);
        self::assertContains("> 1) Name: '', Function: callable(GuzzleHttp\\Tests\\HandlerStackTest::foo)", $lines[3]);
        self::assertContains("< 0) Handler: callable(", $lines[4]);
        self::assertContains("< 1) Name: '', Function: callable(GuzzleHttp\\Tests\\HandlerStackTest::foo)", $lines[5]);
        self::assertContains("< 2) Name: '', Function: callable(['GuzzleHttp\\Tests\\HandlerStackTest', 'bar'])", $lines[6]);
        self::assertContains("< 3) Name: '', Function: callable(GuzzleHttp\\Tests\\HandlerStackTest::foo)", $lines[7]);
        self::assertContains("< 4) Name: 'a', Function: callable(", $lines[8]);
    }

    public function testCanAddBeforeByName()
    {
        $meths = $this->getFunctions();
        $builder = new HandlerStack();
        $builder->setHandler($meths[1]);
        $builder->push($meths[2], 'foo');
        $builder->before('foo', $meths[3], 'baz');
        $builder->before('baz', $meths[4], 'bar');
        $builder->before('baz', $meths[4], 'qux');
        $lines = explode("\n", (string) $builder);
        self::assertContains('> 4) Name: \'bar\'', $lines[0]);
        self::assertContains('> 3) Name: \'qux\'', $lines[1]);
        self::assertContains('> 2) Name: \'baz\'', $lines[2]);
        self::assertContains('> 1) Name: \'foo\'', $lines[3]);
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testEnsuresHandlerExistsByName()
    {
        $builder = new HandlerStack();
        $builder->before('foo', function () {
        });
    }

    public function testCanAddAfterByName()
    {
        $meths = $this->getFunctions();
        $builder = new HandlerStack();
        $builder->setHandler($meths[1]);
        $builder->push($meths[2], 'a');
        $builder->push($meths[3], 'b');
        $builder->after('a', $meths[4], 'c');
        $builder->after('b', $meths[4], 'd');
        $lines = explode("\n", (string) $builder);
        self::assertContains('4) Name: \'a\'', $lines[0]);
        self::assertContains('3) Name: \'c\'', $lines[1]);
        self::assertContains('2) Name: \'b\'', $lines[2]);
        self::assertContains('1) Name: \'d\'', $lines[3]);
    }

    public function testPicksUpCookiesFromRedirects()
    {
        $mock = new MockHandler([
            new Response(301, [
                'Location'   => 'http://foo.com/baz',
                'Set-Cookie' => 'foo=bar; Domain=foo.com'
            ]),
            new Response(200)
        ]);
        $handler = HandlerStack::create($mock);
        $request = new Request('GET', 'http://foo.com/bar');
        $jar = new CookieJar();
        $response = $handler($request, [
            'allow_redirects' => true,
            'cookies' => $jar
        ])->wait();
        self::assertSame(200, $response->getStatusCode());
        $lastRequest = $mock->getLastRequest();
        self::assertSame('http://foo.com/baz', (string) $lastRequest->getUri());
        self::assertSame('foo=bar', $lastRequest->getHeaderLine('Cookie'));
    }

    private function getFunctions()
    {
        $calls = [];

        $a = function (callable $next) use (&$calls) {
            return function ($v) use ($next, &$calls) {
                $calls[] = ['a', $v];
                return $next($v . '1');
            };
        };

        $b = function (callable $next) use (&$calls) {
            return function ($v) use ($next, &$calls) {
                $calls[] = ['b', $v];
                return $next($v . '2');
            };
        };

        $c = function (callable $next) use (&$calls) {
            return function ($v) use ($next, &$calls) {
                $calls[] = ['c', $v];
                return $next($v . '3');
            };
        };

        $handler = function ($v) {
            return 'Hello - ' . $v;
        };

        return [&$calls, $handler, $a, $b, $c];
    }

    public static function foo()
    {
    }
    public function bar()
    {
    }
}
