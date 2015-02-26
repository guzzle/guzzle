<?php
namespace GuzzleHttp\Tests;

use GuzzleHttp\HandlerBuilder;

class HandlerBuilderTest extends \PHPUnit_Framework_TestCase
{
    public function testSetsHandlerAndMiddlewareInCtor()
    {
        $f = function () {};
        $m1 = function () {};
        $h = new HandlerBuilder($f, [$m1]);
        $this->assertTrue($h->hasHandler());
        $this->assertCount(1, $this->readAttribute($h, 'stack')[0]);
    }

    public function testCanSetDifferentHandlerAfterConstruction()
    {
        $f = function () {};
        $h = new HandlerBuilder();
        $h->setHandler($f);
        $h->resolve();
    }

    /**
     * @expectedException \LogicException
     */
    public function testEnsuresHandlerIsSet()
    {
        $h = new HandlerBuilder();
        $h->resolve();
    }

    public function testAppendsInOrder()
    {
        $meths = $this->getFunctions();
        $builder = new HandlerBuilder();
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
        $builder = new HandlerBuilder();
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

    public function testPrependsInOrderWithSticky()
    {
        $meths = $this->getFunctions();
        $builder = new HandlerBuilder();
        $builder->setHandler($meths[1]);
        $builder->prepend($meths[2]);
        $builder->prepend($meths[3], true);
        $builder->prepend($meths[4]);
        $composed = $builder->resolve();
        $this->assertEquals('Hello - test312', $composed('test'));
        $this->assertEquals(
            [['c', 'test'], ['a', 'test3'], ['b', 'test31']],
            $meths[0]
        );
    }

    public function testAppendsInOrderWithSticky()
    {
        $meths = $this->getFunctions();
        $builder = new HandlerBuilder();
        $builder->setHandler($meths[1]);
        $builder->prepend($meths[2]);
        $builder->prepend($meths[3], true);
        $builder->prepend($meths[4]);
        $composed = $builder->resolve();
        $this->assertEquals('Hello - test312', $composed('test'));
        $this->assertEquals(
            [['c', 'test'], ['a', 'test3'], ['b', 'test31']],
            $meths[0]
        );
    }

    public function testCanRemoveMiddlewareByInstance()
    {
        $meths = $this->getFunctions();
        $builder = new HandlerBuilder();
        $builder->setHandler($meths[1]);
        $builder->prepend($meths[2], true);
        $builder->prepend($meths[3], true);
        $builder->append($meths[4], true);
        $builder->remove($meths[2]);
        $builder->remove($meths[3]);
        $builder->remove($meths[4]);
        $composed = $builder->resolve();
        $this->assertEquals('Hello - test', $composed('test'));
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
}
