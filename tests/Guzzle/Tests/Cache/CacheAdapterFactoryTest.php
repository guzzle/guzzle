<?php

namespace Guzzle\Tests\Cache;

use Guzzle\Cache\CacheAdapterFactory;
use Guzzle\Cache\DoctrineCacheAdapter;
use Doctrine\Common\Cache\ArrayCache;

/**
 * @covers Guzzle\Cache\CacheAdapterFactory
 */
class CacheAdapterFactoryTest extends \Guzzle\Tests\GuzzleTestCase
{
    /** @var ArrayCache */
    private $cache;

    /** @var DoctrineCacheAdapter */
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
     * @expectedException \InvalidArgumentException
     */
    public function testEnsuresConfigIsArray()
    {
        CacheAdapterFactory::factory(new \stdClass());
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage cache.provider is a required CacheAdapterFactory option
     */
    public function testEnsuresRequiredProviderOption()
    {
        CacheAdapterFactory::factory(array(
            'cache.adapter' => $this->adapter
        ));
    }

    /**
     * @expectedException \Guzzle\Common\Exception\InvalidArgumentException
     * @expectedExceptionMessage cache.adapter is a required CacheAdapterFactory option
     */
    public function testEnsuresRequiredAdapterOption()
    {
        CacheAdapterFactory::factory(array(
            'cache.provider' => $this->cache
        ));
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage foo is not a valid class for cache.adapter
     */
    public function testEnsuresClassesExist()
    {
        CacheAdapterFactory::factory(array(
            'cache.provider' => 'abc',
            'cache.adapter'  => 'foo'
        ));
    }

    public function testCreatesProviderFromConfig()
    {
        $cache = CacheAdapterFactory::factory(array(
            'cache.provider' => 'Doctrine\Common\Cache\ApcCache',
            'cache.adapter'  => 'Guzzle\Cache\DoctrineCacheAdapter'
        ));

        $this->assertInstanceOf('Guzzle\Cache\DoctrineCacheAdapter', $cache);
        $this->assertInstanceOf('Doctrine\Common\Cache\ApcCache', $cache->getCacheObject());
    }

    public function testCreatesProviderFromConfigWithArguments()
    {
        $cache = CacheAdapterFactory::factory(array(
            'cache.provider'      => 'Doctrine\Common\Cache\ApcCache',
            'cache.provider.args' => array(),
            'cache.adapter'       => 'Guzzle\Cache\DoctrineCacheAdapter',
            'cache.adapter.args'  => array()
        ));

        $this->assertInstanceOf('Guzzle\Cache\DoctrineCacheAdapter', $cache);
        $this->assertInstanceOf('Doctrine\Common\Cache\ApcCache', $cache->getCacheObject());
    }

    /**
     * @expectedException \Guzzle\Common\Exception\RuntimeException
     */
    public function testWrapsExceptionsOnObjectCreation()
    {
        CacheAdapterFactory::factory(array(
            'cache.provider' => 'Guzzle\Tests\Mock\ExceptionMock',
            'cache.adapter'  => 'Guzzle\Tests\Mock\ExceptionMock'
        ));
    }

    public function testCreatesNullCacheAdapterByDefault()
    {
        $adapter = CacheAdapterFactory::factory(array());
        $this->assertInstanceOf('Guzzle\Cache\NullCacheAdapter', $adapter);
    }
}
