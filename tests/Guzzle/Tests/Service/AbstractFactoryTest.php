<?php

namespace Guzzle\Tests\Service;

use Guzzle\Common\Cache\DoctrineCacheAdapter;
use Guzzle\Service\AbstractFactory;
use Guzzle\Service\Builder\JsonServiceBuilderFactory;
use Guzzle\Service\Exception\ServiceBuilderException;
use Guzzle\Service\Builder\ArrayServiceBuilderFactory;
use Doctrine\Common\Cache\ArrayCache;

/**
 * @covers Guzzle\Service\AbstractFactory
 */
class AbstractFactoryTest extends \Guzzle\Tests\GuzzleTestCase
{
    protected function getFactory()
    {
        return $this->getMockBuilder('Guzzle\Service\AbstractFactory')
            ->setMethods(array('getCacheTtlKey', 'throwException', 'getFactory'))
            ->getMockForAbstractClass();
    }

    public function testCachesArtifacts()
    {
        $jsonFile = __DIR__ . '/../TestData/test_service.json';

        $adapter = new DoctrineCacheAdapter(new ArrayCache());
        $factory = $this->getFactory();

        $factory->expects($this->once())
            ->method('getFactory')
            ->will($this->returnValue(new JsonServiceBuilderFactory(new ArrayServiceBuilderFactory())));

        // Create a service and add it to the cache
        $service = $factory->build($jsonFile, array(
            'cache.adapter' => $adapter
        ));

        // Ensure the cache key was set
        $this->assertTrue($adapter->contains('guzzle' . crc32($jsonFile)));

        // Grab the service from the cache
        $this->assertEquals($service, $factory->build($jsonFile, array(
            'cache.adapter' => $adapter
        )));
    }

    /**
     * @expectedException Guzzle\Service\Exception\ServiceBuilderException
     */
    public function testThrowsExceptionsWhenNoFactoryResolves()
    {
        $factory = $this->getFactory();
        $factory->expects($this->any())
            ->method('getFactory')
            ->will($this->returnValue(false));
        $factory->expects($this->any())
            ->method('throwException')
            ->will($this->throwException(new ServiceBuilderException()));

        $service = $factory->build('foo');
    }
}
