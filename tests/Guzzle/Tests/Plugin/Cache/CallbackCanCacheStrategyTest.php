<?php

namespace Guzzle\Tests\Plugin\Cache;

use Guzzle\Http\Message\Request;
use Guzzle\Plugin\Cache\CallbackCanCacheStrategy;

/**
 * @covers Guzzle\Plugin\Cache\CallbackCanCacheStrategy
 * @covers Guzzle\Plugin\Cache\AbstractCallbackStrategy
 */
class CallbackCanCacheStrategyTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @expectedException Guzzle\Common\Exception\InvalidArgumentException
     */
    public function testConstructorEnsuresCallbackIsCallable()
    {
        $p = new CallbackCanCacheStrategy(new \stdClass());
    }

    public function testUsesCallback()
    {
        $c = new CallbackCanCacheStrategy(function ($request) { return true; });
        $this->assertTrue($c->canCache(new Request('DELETE', 'http://www.foo.com')));
    }
}
