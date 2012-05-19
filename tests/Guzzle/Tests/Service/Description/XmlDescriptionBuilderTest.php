<?php

namespace Guzzle\Tests\Service\Description;

use Guzzle\Service\Inspector;
use Guzzle\Service\Description\ServiceDescription;
use Guzzle\Service\Description\XmlDescriptionBuilder;

class XmlDescriptionBuilderTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Description\XmlDescriptionBuilder
     * @expectedException Guzzle\Service\Exception\DescriptionBuilderException
     */
    public function testXmlBuilderThrowsExceptionWhenFileIsNotFound()
    {
        $x = new XmlDescriptionBuilder();
        $data = $x->build('file_not_found');
    }

    /**
     * @covers Guzzle\Service\Description\XmlDescriptionBuilder
     * @covers Guzzle\Service\Description\ServiceDescription
     */
    public function testBuildsServiceUsingFile()
    {
        $x = new XmlDescriptionBuilder();
        $service = $x->build(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'TestData' . DIRECTORY_SEPARATOR . 'test_service.xml');
        $this->assertTrue($service->hasCommand('search'));
        $this->assertTrue($service->hasCommand('test'));
        $this->assertTrue($service->hasCommand('trends.location'));
        $this->assertTrue($service->hasCommand('geo.id'));
        $this->assertInstanceOf('Guzzle\\Service\\Description\\ApiCommand', $service->getCommand('search'));
        $this->assertInternalType('array', $service->getCommands());
        $this->assertEquals(7, count($service->getCommands()));
        $this->assertNull($service->getCommand('missing'));

        $command = $service->getCommand('test');
        $this->assertInstanceOf('Guzzle\\Service\\Description\\ApiCommand', $command);
        $this->assertEquals('test', $command->getName());
        $this->assertInternalType('array', $command->getParams());

        $this->assertEquals(array(
            'name' => 'bucket',
            'required' => true,
            'doc' => 'Bucket location'
        ), array_filter($command->getParam('bucket')->toArray()));

        $this->assertEquals('DELETE', $command->getMethod());
        $this->assertEquals('{ bucket }/{ key }{ format }', $command->getUri());
        $this->assertEquals('Documentation', $command->getDoc());

        $this->assertArrayHasKey('custom_filter', Inspector::getInstance()->getRegisteredConstraints());
    }

    /**
     * @covers Guzzle\Service\Description\XmlDescriptionBuilder
     */
    public function testCanExtendOtherFiles()
    {
        $x = new XmlDescriptionBuilder();
        $service = $x->build(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'TestData' . DIRECTORY_SEPARATOR . 'test_service.xml');
        $command = $service->getCommand('concrete');
        $this->assertEquals('/test', $command->getUri());
    }
}
