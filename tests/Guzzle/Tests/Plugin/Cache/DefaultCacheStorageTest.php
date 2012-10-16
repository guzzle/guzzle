<?php

namespace Guzzle\Tests\Plugin\Cache;

use Guzzle\Cache\DoctrineCacheAdapter;
use Guzzle\Http\Message\Response;
use Guzzle\Plugin\Cache\DefaultCacheStorage;
use Doctrine\Common\Cache\ArrayCache;

/**
 * @covers Guzzle\Plugin\Cache\DefaultCacheStorage
 */
class DefaultCacheStorageTest extends \Guzzle\Tests\GuzzleTestCase
{
    public function testWrapsMethods()
    {
        $a = new ArrayCache();
        $a->save('foo', '123');
        $c = new DoctrineCacheAdapter($a);
        $s = new DefaultCacheStorage($c, 100);
        $this->assertEquals('123', $s->fetch('foo'));
        $s->delete('foo');
        $this->assertNotEquals('123', $s->fetch('foo'));
    }

    public function testStoresResponsesWithDefaultTtl()
    {
        $c = $this->getMockBuilder('Guzzle\Cache\CacheAdapterInterface')
            ->setMethods(array('save'))
            ->getMockForAbstractClass();

        $c->expects($this->once())
            ->method('save')
            ->with('foo', array(200, array(
                'foo' => array('bar'),
                'X-Guzzle-Cache' => array('key=foo, ttl=100'),
                'Date' => array('test')
            ), 'baz'), 100);

        $s = new DefaultCacheStorage($c, 100);
        $response = new Response(200, array('foo' => 'bar', 'Date' => 'test'), 'baz');
        $s->cache('foo', $response);
    }

    public function testStoresResponsesWithCustomTtl()
    {
        $c = $this->getMockBuilder('Guzzle\Cache\CacheAdapterInterface')
            ->setMethods(array('save'))
            ->getMockForAbstractClass();

        $that = $this;
        $c->expects($this->once())
            ->method('save')
            ->will($this->returnCallback(function ($a, $b, $c) use ($that) {
                $that->assertArrayHasKey('Date', $b[1]);
                $that->assertFalse(array_key_exists('Connection', $b[1]));
                $that->assertEquals(50, $c);
            }));

        $s = new DefaultCacheStorage($c, 100);
        $response = new Response(200, array('foo' => 'bar', 'Connection' => 'close'), 'baz');
        $s->cache('foo', $response, 50);
    }
}
