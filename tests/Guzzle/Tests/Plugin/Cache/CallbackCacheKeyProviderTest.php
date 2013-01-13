<?php

namespace Guzzle\Tests\Plugin\Cache;

use Guzzle\Http\Message\Request;
use Guzzle\Plugin\Cache\CallbackCacheKeyProvider;

/**
 * @covers Guzzle\Plugin\Cache\CallbackCacheKeyProvider
 */
class CallbackCacheKeyProviderTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @expectedException Guzzle\Common\Exception\InvalidArgumentException
     */
    public function testConstructorEnsuresCallbackIsCallable()
    {
        $p = new CallbackCacheKeyProvider(new \stdClass());
    }

    public function testUsesCallback()
    {
        $p = new CallbackCacheKeyProvider(function ($request) { return 'foo'; });
        $this->assertEquals('foo', $p->getCacheKey(new Request('GET', 'http://www.foo.com')));
    }
}
