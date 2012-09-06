<?php

namespace Guzzle\Tests\Cache;

use Guzzle\Cache\ClosureCacheAdapter;

/**
 * @covers Guzzle\Cache\ClosureCacheAdapter
 */
class ClosureCacheAdapterTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @var ClosureCacheAdapter
     */
    private $adapter;

    /**
     * Array of callables to use for testing
     */
    private $callables;

    /**
     * Cache data for testing
     */
    public $data = array();

    /**
     * Prepares the environment before running a test.
     */
    protected function setUp()
    {
        parent::setUp();

        $that = $this;
        $this->callables = array(
            'contains' => function($id, $options = array()) use ($that) {
                return array_key_exists($id, $that->data);
            },
            'delete' => function($id, $options = array()) use ($that) {
                unset($that->data[$id]);
                return true;
            },
            'fetch' => function($id, $options = array()) use ($that) {
                return array_key_exists($id, $that->data) ? $that->data[$id] : null;
            },
            'save' => function($id, $data, $lifeTime, $options = array()) use ($that) {
                $that->data[$id] = $data;
                return true;
            }
        );

        $this->adapter = new ClosureCacheAdapter($this->callables);
    }

    /**
     * Cleans up the environment after running a test.
     */
    protected function tearDown()
    {
        $this->cache = null;
        $this->callables = null;
        parent::tearDown();
    }

    /**
     * @covers Guzzle\Cache\ClosureCacheAdapter::__construct
     * @expectedException InvalidArgumentException
     */
    public function testEnsuresCallablesArePresent()
    {
        $callables = $this->callables;
        unset($callables['delete']);
        $cache = new ClosureCacheAdapter($callables);
    }

    /**
     * @covers Guzzle\Cache\ClosureCacheAdapter::__construct
     */
    public function testAllCallablesMustBePresent()
    {
        $cache = new ClosureCacheAdapter($this->callables);
    }

    /**
     * @covers Guzzle\Cache\ClosureCacheAdapter::save
     * @covers Guzzle\Cache\ClosureCacheAdapter::fetch
     */
    public function testCachesDataUsingCallables()
    {
        $this->assertTrue($this->adapter->save('test', 'data', 1000));
        $this->assertEquals('data', $this->adapter->fetch('test'));
    }

    /**
     * @covers Guzzle\Cache\ClosureCacheAdapter::contains
     */
    public function testChecksIfCacheContainsKeys()
    {
        $this->adapter->save('test', 'data', 1000);
        $this->assertTrue($this->adapter->contains('test'));
        $this->assertFalse($this->adapter->contains('foo'));
    }

    /**
     * @covers Guzzle\Cache\ClosureCacheAdapter::delete
     */
    public function testDeletesFromCacheByKey()
    {
        $this->adapter->save('test', 'data', 1000);
        $this->assertTrue($this->adapter->contains('test'));
        $this->adapter->delete('test');
        $this->assertFalse($this->adapter->contains('test'));
    }
}
