<?php

namespace Guzzle\Tests\Service\Description;

use Guzzle\Service\Description\ServiceDescriptionAbstractFactory;

/**
 * @covers Guzzle\Service\Description\ServiceDescriptionAbstractFactory
 */
class ServiceDescriptionAbstractFactoryTest extends \Guzzle\Tests\GuzzleTestCase
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
        $factory = new ServiceDescriptionAbstractFactory();
        $this->assertInstanceOf('Guzzle\Service\Description\ServiceDescription', $factory->build($this->xmlFile));
        $this->assertInstanceOf('Guzzle\Service\Description\ServiceDescription', $factory->build($this->jsonFile));
    }

    /**
     * @expectedException Guzzle\Service\Exception\DescriptionBuilderException
     */
    public function testFactoryEnsuresItCanHandleTheTypeOfFileOrArray()
    {
        $factory = new ServiceDescriptionAbstractFactory();
        $factory->build('jarJarBinks');
    }
}
