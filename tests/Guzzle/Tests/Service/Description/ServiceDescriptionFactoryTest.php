<?php

namespace Guzzle\Tests\Service\Description;

use Guzzle\Common\Cache\DoctrineCacheAdapter;
use Doctrine\Common\Cache\ArrayCache;
use Guzzle\Service\Description\ServiceDescriptionFactory;

/**
 * @covers Guzzle\Service\Description\ServiceDescriptionFactory
 */
class ServiceDescriptionFactoryTest extends \Guzzle\Tests\GuzzleTestCase
{
    protected $jsonFile;
    protected $xmlFile;

    public function setup()
    {
        $this->xmlFile = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'TestData' . DIRECTORY_SEPARATOR . 'test_service.xml';
        $this->jsonFile = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'TestData' . DIRECTORY_SEPARATOR . 'test_service.json';
    }

    public function testFactoryDelegatesToConcreteFactories()
    {
        $factory = new ServiceDescriptionFactory();
        $this->assertInstanceOf('Guzzle\Service\Description\ServiceDescription', $factory->build($this->xmlFile));
        $this->assertInstanceOf('Guzzle\Service\Description\ServiceDescription', $factory->build($this->jsonFile));
    }

    /**
     * @expectedException Guzzle\Service\Exception\DescriptionBuilderException
     */
    public function testFactoryEnsuresItCanHandleTheTypeOfFileOrArray()
    {
        $factory = new ServiceDescriptionFactory();
        $this->assertInstanceOf('Guzzle\Service\Description\ServiceDescription', $factory->build('jarJarBinks'));
    }

    public function testCachesDescriptions()
    {
        $adapter = new DoctrineCacheAdapter(new ArrayCache());
        $factory = new ServiceDescriptionFactory();

        // Create a service and add it to the cache
        $service = $factory->build($this->jsonFile, array(
            'cache.adapter' => $adapter
        ));

        // Ensure the cache key was set
        $this->assertTrue($adapter->contains('d' . crc32($this->jsonFile)));

        // Grab the service from the cache
        $this->assertEquals($service, $factory->build($this->jsonFile, array(
            'cache.adapter' => $adapter
        )));
    }
}
