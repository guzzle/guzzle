<?php

namespace Guzzle\Tests\Common\Cache;

use Guzzle\Common\Cache\ZendCacheAdapter;

/**
 * CacheAdapter test case
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class ZendCacheAdapterTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @var \Doctrine\Common\Cache\ArrayCache
     */
    private $cache;

    /**
     * @var ZendCacheAdapter
     */
    private $adapter;

    /**
     * Prepares the environment before running a test.
     */
    protected function setUp()
    {
        parent::setUp();
        $this->cache = new \Zend_Cache_Backend_Test();
        $this->adapter = new ZendCacheAdapter($this->cache);
    }

    /**
     * Cleans up the environment after running a test.
     */
    protected function tearDown()
    {
        $this->adapter = null;
        $this->cache = null;
        parent::tearDown();
    }

    /**
     * @covers Guzzle\Common\Cache\ZendCacheAdapter
     */
    public function testAll()
    {
        $this->assertTrue($this->adapter->save('id', 'data'));
        $this->assertTrue($this->adapter->delete('id', 'data'));
        $this->assertEquals('foo', $this->adapter->fetch('id'));
        $this->assertEquals('123456', $this->adapter->contains('id'));
    }
}