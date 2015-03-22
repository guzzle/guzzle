<?php
namespace GuzzleHttp\Tests;

use GuzzleHttp\HandlerStack;

class HandlerStackTest extends \PHPUnit_Framework_TestCase
{
    public function testSetsHandlerInCtor()
    {
        $f = function () {};
        $m1 = function () {};
        $h = new HandlerStack($f, [$m1]);
        $this->assertTrue($h->hasHandler());
    }

    public function testCanSetDifferentHandlerAfterConstruction()
    {
        $f = function () {};
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

    public function testAppendsInOrder()
    {
        $meths = $this->getFunctions();
        $builder = new HandlerStack();
        $builder->setHandler($meths[1]);
        $builder->append($meths[2]);
        $builder->append($meths[3]);
        $builder->append($meths[4]);
        $composed = $builder->resolve();
        $this->assertEquals('Hello - test123', $composed('test'));
        $this->assertEquals(
            [['a', 'test'], ['b', 'test1'], ['c', 'test12']],
            $meths[0]
        );
    }

    public function testPrependsInOrder()
    {
        $meths = $this->getFunctions();
        $builder = new HandlerStack();
        $builder->setHandler($meths[1]);
        $builder->prepend($meths[2]);
        $builder->prepend($meths[3]);
        $builder->prepend($meths[4]);
        $composed = $builder->resolve();
        $this->assertEquals('Hello - test321', $composed('test'));
        $this->assertEquals(
            [['c', 'test'], ['b', 'test3'], ['a', 'test32']],
            $meths[0]
        );
    }

    public function testCanRemoveMiddlewareByInstance()
    {
        $meths = $this->getFunctions();
        $builder = new HandlerStack();
        $builder->setHandler($meths[1]);
        $builder->prepend($meths[2]);
        $builder->prepend($meths[3]);
        $builder->append($meths[4]);
        $builder->remove($meths[2]);
        $builder->remove($meths[3]);
        $builder->remove($meths[4]);
        $composed = $builder->resolve();
        $this->assertEquals('Hello - test', $composed('test'));
    }

    public function testCanPrintMiddleware()
    {
        $meths = $this->getFunctions();
        $builder = new HandlerStack();
        $builder->setHandler($meths[1]);
        $builder->append($meths[2], 'a');
        $builder->append([__CLASS__, 'foo']);
        $builder->append([$this, 'bar']);
        $builder->append(__CLASS__ . '::' . 'foo');
        $lines = explode("\n", (string) $builder);
        $this->assertContains("0) Name: a, Function: callable(", $lines[0]);
        $this->assertContains("1) Function: callable(GuzzleHttp\\Tests\\HandlerStackTest::foo)", $lines[1]);
        $this->assertContains("2) Function: callable(['GuzzleHttp\\Tests\\HandlerStackTest', 'bar'])", $lines[2]);
        $this->assertContains("3) Function: callable(GuzzleHttp\\Tests\\HandlerStackTest::foo)", $lines[3]);
        $this->assertContains("4) Handler: callable(", $lines[4]);
    }

    public function testCanAddBeforeByName()
    {
        $meths = $this->getFunctions();
        $builder = new HandlerStack();
        $builder->setHandler($meths[1]);
        $builder->append($meths[2], 'a');
        $builder->before('a', $meths[3], 'b');
        $builder->before('b', $meths[4], 'c');
        $lines = explode("\n", (string) $builder);
        $this->assertContains('0) Name: c', $lines[0]);
        $this->assertContains('1) Name: b', $lines[1]);
        $this->assertContains('2) Name: a', $lines[2]);
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testEnsuresHandlerExistsByName()
    {
        $builder = new HandlerStack();
        $builder->before('foo', function () {});
    }

    public function testCanAddAfterByName()
    {
        $meths = $this->getFunctions();
        $builder = new HandlerStack();
        $builder->setHandler($meths[1]);
        $builder->append($meths[2], 'a');
        $builder->append($meths[3], 'b');
        $builder->after('a', $meths[4], 'c');
        $lines = explode("\n", (string) $builder);
        $this->assertContains('0) Name: a', $lines[0]);
        $this->assertContains('1) Name: c', $lines[1]);
        $this->assertContains('2) Name: b', $lines[2]);
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

    public static function foo() {}
    public function bar () {}
}
