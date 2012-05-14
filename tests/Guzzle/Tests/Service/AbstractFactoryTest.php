<?php

namespace Guzzle\Tests\Service;

use Guzzle\Common\Cache\DoctrineCacheAdapter;
use Guzzle\Service\AbstractFactory;
use Guzzle\Service\Exception\ServiceBuilderException;
use Doctrine\Common\Cache\ArrayCache;

/**
 * @covers Guzzle\Service\AbstractFactory
 */
class AbstractFactoryTest extends \Guzzle\Tests\GuzzleTestCase
{
    protected function getFactory()
    {
        return $this->getMockBuilder('Guzzle\Service\AbstractFactory')
            ->setMethods(array('getCacheTtlKey', 'throwException', 'getClassName'))
            ->getMockForAbstractClass();
    }

    public function testCachesArtifacts()
    {
        $jsonFile = __DIR__ . '/../TestData/test_service.json';

        $adapter = new DoctrineCacheAdapter(new ArrayCache());
        $factory = $this->getFactory();

        $factory->expects($this->once())
            ->method('getClassName')
            ->will($this->returnValue('Guzzle\Service\Builder\JsonServiceBuilderFactory'));

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
            ->method('getClassName')
            ->will($this->returnValue(false));

        // Exceptions are mocked and disabled, so nothing happens here
        $service = $factory->build('foo');

        // Throw an exception when it's supposed to
        $factory->expects($this->any())
            ->method('throwException')
            ->will($this->throwException(new ServiceBuilderException()));

        $service = $factory->build('foo');
    }
}
