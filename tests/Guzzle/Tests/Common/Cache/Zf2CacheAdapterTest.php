<?php

namespace Guzzle\Tests\Common\Cache;

use Guzzle\Common\Cache\Zf2CacheAdapter;
use Zend\Cache\StorageFactory;

class Zf2CacheAdapterTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @var StaticBackend
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
        $this->cache = StorageFactory::factory(array(
            'adapter' => 'memory'
        ));
        $this->adapter = new Zf2CacheAdapter($this->cache);
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
     * @covers Guzzle\Common\Cache\Zf2CacheAdapter::__construct
     */
    public function testConstructorAddsDefaultOptions()
    {
        $default = array(
            'contains' => array(
                'namespace' => 'foo'
            )
        );
        $adapter = new Zf2CacheAdapter(StorageFactory::factory(array(
            'adapter' => 'memory'
        )), $default);

        // Access the protected property
        $class = new \ReflectionClass($adapter);
        $property = $class->getProperty('defaultOptions');
        $property->setAccessible(true);
        $defaultOptions = $property->getValue($adapter);

        $this->assertEquals(array_merge($default, array(
            'delete' => array(),
            'fetch'  => array(),
            'save'   => array()
        )), $defaultOptions);
    }

    /**
     * @covers Guzzle\Common\Cache\Zf2CacheAdapter::save
     * @covers Guzzle\Common\Cache\Zf2CacheAdapter::fetch
     */
    public function testCachesDataUsingCallables()
    {
        $this->assertTrue($this->adapter->save('test', 'data', 1000));
        $this->assertEquals('data', $this->adapter->fetch('test'));
    }

    /**
     * @covers Guzzle\Common\Cache\Zf2CacheAdapter::contains
     */
    public function testChecksIfCacheContainsKeys()
    {
        $this->adapter->save('test', 'data', 1000);
        $this->assertTrue($this->adapter->contains('test'));
        $this->assertFalse($this->adapter->contains('foo'));
    }

    /**
     * @covers Guzzle\Common\Cache\Zf2CacheAdapter::delete
     */
    public function testDeletesFromCacheByKey()
    {
        $this->adapter->save('test', 'data', 1000);
        $this->assertTrue($this->adapter->contains('test'));
        $this->adapter->delete('test');
        $this->assertFalse($this->adapter->contains('test'));
    }
}
