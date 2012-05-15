<?php

namespace Guzzle\Tests\Service\Builder;

use Guzzle\Service\Builder\ServiceBuilderAbstractFactory;

/**
 * @covers Guzzle\Service\Builder\ServiceBuilderAbstractFactory
 */
class ServiceBuilderAbstractFactoryTest extends \Guzzle\Tests\GuzzleTestCase
{
    protected $jsonFile;
    protected $xmlFile;

    public function setup()
    {
        $this->xmlFile = __DIR__ . '/../../TestData/services/new_style.xml';
        $this->jsonFile = __DIR__ . '/../../TestData/services/json1.json';
    }

    public function testFactoryDelegatesToConcreteFactories()
    {
        $factory = new ServiceBuilderAbstractFactory();
        $this->assertInstanceOf('Guzzle\Service\Builder\ServiceBuilder', $factory->build($this->xmlFile));
        $this->assertInstanceOf('Guzzle\Service\Builder\ServiceBuilder', $factory->build($this->jsonFile));

        $xml = new \SimpleXMLElement(file_get_contents($this->xmlFile));
        $xml->includes = null;
        $this->assertInstanceOf('Guzzle\Service\Builder\ServiceBuilder', $factory->build($xml));
    }

    /**
     * @expectedException Guzzle\Service\Exception\ServiceBuilderException
     * @expectedExceptionMessage Unable to build service builder
     */
    public function testFactoryEnsuresItCanHandleTheTypeOfFileOrArray()
    {
        $factory = new ServiceBuilderAbstractFactory();
        $factory->build('jarJarBinks');
    }

    /**
     * @expectedException Guzzle\Service\Exception\ServiceBuilderException
     * @expectedExceptionMessage Must pass a file name, array, or SimpleXMLElement
     */
    public function testThrowsExceptionWhenUnknownTypeIsPassed()
    {
        $factory = new ServiceBuilderAbstractFactory();
        $factory->build(new \stdClass());
    }
}
