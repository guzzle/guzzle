<?php

namespace Guzzle\Tests\Common\Cache;

use Guzzle\Common\Cache\Zf1CacheAdapter;

/**
 * @covers Guzzle\Common\Cache\Zf1CacheAdapter
 */
class Zf1CacheAdapterTest extends \Guzzle\Tests\GuzzleTestCase
{
    public function testAll()
    {
        $cache = new \Zend_Cache_Backend_Test();
        $adapter = new Zf1CacheAdapter($cache);
        $this->assertTrue($adapter->save('id', 'data'));
        $this->assertTrue($adapter->delete('id'));
        $this->assertEquals('foo', $adapter->fetch('id'));
        $this->assertEquals('123456', $adapter->contains('id'));
    }
}
