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
     * @expectedExceptionMessage provider is a required CacheAdapterFactory option
     */
    public function testEnsuresRequiredProviderOption()
    {
        CacheAdapterFactory::factory(array(
            'adapter' => $this->adapter
        ));
    }

    /**
     * @covers Guzzle\Common\Cache\CacheAdapterFactory::factory
     * @expectedException InvalidArgumentException
     * @expectedExceptionMessage adapter is a required CacheAdapterFactory option
     */
    public function testEnsuresRequiredAdapterOption()
    {
        CacheAdapterFactory::factory(array(
            'provider' => $this->cache
        ));
    }

    /**
     * @covers Guzzle\Common\Cache\CacheAdapterFactory::factory
     * @expectedException InvalidArgumentException
     * @expectedExceptionMessage foo is not a valid class for adapter
     */
    public function testEnsuresClassesExist()
    {
        CacheAdapterFactory::factory(array(
            'provider' => 'abc',
            'adapter'  => 'foo'
        ));
    }

    /**
     * @covers Guzzle\Common\Cache\CacheAdapterFactory::factory
     * @covers Guzzle\Common\Cache\CacheAdapterFactory::createObject
     */
    public function testCreatesProviderFromConfig()
    {
        $cache = CacheAdapterFactory::factory(array(
            'provider' => 'Doctrine\Common\Cache\ApcCache',
            'adapter'  => 'Guzzle\Common\Cache\DoctrineCacheAdapter'
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
            'provider'      => 'Doctrine\Common\Cache\ApcCache',
            'provider.args' => array(),
            'adapter'       => 'Guzzle\Common\Cache\DoctrineCacheAdapter',
            'adapter.args'  => array()
        ));

        $this->assertInstanceOf('Guzzle\Common\Cache\DoctrineCacheAdapter', $cache);
        $this->assertInstanceOf('Doctrine\Common\Cache\ApcCache', $cache->getCacheObject());
    }
}