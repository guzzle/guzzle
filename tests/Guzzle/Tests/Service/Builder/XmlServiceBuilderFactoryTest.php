<?php

namespace Guzzle\Tests\Service\Builder;

use Guzzle\Service\Builder\XmlServiceBuilderFactory;

/**
 * @covers Guzzle\Service\Builder\XmlServiceBuilderFactory
 * @covers Guzzle\Service\Builder\ArrayServiceBuilderFactory
 */
class XmlServiceBuilderFactoryTest extends \Guzzle\Tests\GuzzleTestCase
{
    public function testBuildsServiceBuilders()
    {
        $xmlFactory = new XmlServiceBuilderFactory();
        $file = __DIR__ . '/../../TestData/services/new_style.xml';

        $builder = $xmlFactory->build($file);

        // Ensure that services were parsed
        $this->assertTrue(isset($builder['mock']));
        $this->assertTrue(isset($builder['abstract']));
        $this->assertTrue(isset($builder['foo']));
        $this->assertFalse(isset($builder['jimmy']));
    }

    public function testBuildsServiceBuildersUsingSimpleXmlElement()
    {
        $xmlFactory = new XmlServiceBuilderFactory();
        $file = __DIR__ . '/../../TestData/services/new_style.xml';
        $xml = new \SimpleXMLElement(file_get_contents($file));
        $xml->includes = null;
        $this->assertInstanceOf('Guzzle\Service\Builder\ServiceBuilder', $xmlFactory->build($xml));
    }

    /**
     * @expectedException Guzzle\Service\Exception\ServiceBuilderException
     */
    public function testCannotExtendWhenUsingSimpleXMLElement()
    {
        $xmlFactory = new XmlServiceBuilderFactory();
        $file = __DIR__ . '/../../TestData/services/new_style.xml';
        $xml = new \SimpleXMLElement(file_get_contents($file));
        $xmlFactory->build($xml);
    }
}
