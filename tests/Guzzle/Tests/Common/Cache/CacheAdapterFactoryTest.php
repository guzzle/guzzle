<?php

namespace Guzzle\Tests\Common\Cache;

use Guzzle\Common\Cache\CacheAdapterFactory;
use Guzzle\Common\Cache\DoctrineCacheAdapter;
use Doctrine\Common\Cache\ArrayCache;

class CacheAdapterFactoryTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @var ArrayCache
     */
    private $cache;

    /**
     * @var DoctrineCacheAdapter
     */
    private $adapter;

    /**
     * Prepares the environment before running a test.
     */
    protected function setup()
    {
        parent::setUp();
        $this->cache = new ArrayCache();
        $this->adapter = new DoctrineCacheAdapter($this->cache);
    }

    /**
     * @covers Guzzle\Common\Cache\CacheAdapterFactory::factory
     * @expectedException InvalidArgumentException
     */
    public function testEnsuresConfigIsArray()
    {
        CacheAdapterFactory::factory(new \stdClass());
    }

    /**
     * @covers Guzzle\Common\Cache\CacheAdapterFactory::factory
     * @expectedException InvalidArgumentException
     * @expectedExceptionMessage cache.provider is a required CacheAdapterFactory option
     */
    public function testEnsuresRequiredProviderOption()
    {
        CacheAdapterFactory::factory(array(
            'cache.adapter' => $this->adapter
        ));
    }

    /**
     * @covers Guzzle\Common\Cache\CacheAdapterFactory::factory
     * @expectedException Guzzle\Common\Exception\InvalidArgumentException
     * @expectedExceptionMessage cache.adapter is a required CacheAdapterFactory option
     */
    public function testEnsuresRequiredAdapterOption()
    {
        CacheAdapterFactory::factory(array(
            'cache.provider' => $this->cache
        ));
    }

    /**
     * @covers Guzzle\Common\Cache\CacheAdapterFactory::factory
     * @expectedException InvalidArgumentException
     * @expectedExceptionMessage foo is not a valid class for cache.adapter
     */
    public function testEnsuresClassesExist()
    {
        CacheAdapterFactory::factory(array(
            'cache.provider' => 'abc',
            'cache.adapter'  => 'foo'
        ));
    }

    /**
     * @covers Guzzle\Common\Cache\CacheAdapterFactory::factory
     * @covers Guzzle\Common\Cache\CacheAdapterFactory::createObject
     */
    public function testCreatesProviderFromConfig()
    {
        $cache = CacheAdapterFactory::factory(array(
            'cache.provider' => 'Doctrine\Common\Cache\ApcCache',
            'cache.adapter'  => 'Guzzle\Common\Cache\DoctrineCacheAdapter'
        ));

        $this->assertInstanceOf('Guzzle\Common\Cache\DoctrineCacheAdapter', $cache);
        $this->assertInstanceOf('Doctrine\Common\Cache\ApcCache', $cache->getCacheObject());
    }

    /**
     * @covers Guzzle\Common\Cache\CacheAdapterFactory::factory
     * @covers Guzzle\Common\Cache\CacheAdapterFactory::createObject
     */
    public function testCreatesProviderFromConfigWithArguments()
    {
        $cache = CacheAdapterFactory::factory(array(
            'cache.provider'      => 'Doctrine\Common\Cache\ApcCache',
            'cache.provider.args' => array(),
            'cache.adapter'       => 'Guzzle\Common\Cache\DoctrineCacheAdapter',
            'cache.adapter.args'  => array()
        ));

        $this->assertInstanceOf('Guzzle\Common\Cache\DoctrineCacheAdapter', $cache);
        $this->assertInstanceOf('Doctrine\Common\Cache\ApcCache', $cache->getCacheObject());
    }

    /**
     * @covers Guzzle\Common\Cache\CacheAdapterFactory
     * @expectedException Guzzle\Common\Exception\RuntimeException
     */
    public function testWrapsExceptionsOnObjectCreation()
    {
        CacheAdapterFactory::factory(array(
            'cache.provider' => 'Guzzle\Tests\Mock\ExceptionMock',
            'cache.adapter'  => 'Guzzle\Tests\Mock\ExceptionMock'
        ));
    }

    /**
     * @covers Guzzle\Common\Cache\CacheAdapterFactory
     */
    public function testCreatesNullCacheAdapterByDefault()
    {
        $adapter = CacheAdapterFactory::factory(array());
        $this->assertInstanceOf('Guzzle\\Common\\Cache\\NullCacheAdapter', $adapter);
    }
}
