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
}
