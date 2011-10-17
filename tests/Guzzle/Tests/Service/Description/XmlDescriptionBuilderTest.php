<?php

namespace Guzzle\Tests\Service\Description;

use Guzzle\Common\Inspector;
use Guzzle\Service\Description\ServiceDescription;
use Guzzle\Service\Description\XmlDescriptionBuilder;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class XmlDescriptionBuilderTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Description\XmlDescriptionBuilder
     * @expectedException InvalidArgumentException
     */
    public function testXmlBuilderThrowsExceptionWhenFileIsNotFound()
    {
        $builder = new XmlDescriptionBuilder('file_not_found');
    }

    /**
     * @covers Guzzle\Service\Description\XmlDescriptionBuilder
     * @covers Guzzle\Service\Description\ServiceDescription
     */
    public function testBuildsServiceUsingFile()
    {
        $builder = new XmlDescriptionBuilder(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'TestData' . DIRECTORY_SEPARATOR . 'test_service.xml');
        $service = $builder->build();
        $this->assertTrue($service->hasCommand('search'));
        $this->assertTrue($service->hasCommand('test'));
        $this->assertTrue($service->hasCommand('trends.location'));
        $this->assertTrue($service->hasCommand('geo.id'));
        $this->assertInstanceOf('Guzzle\\Service\\Description\\ApiCommand', $service->getCommand('search'));
        $this->assertInternalType('array', $service->getCommands());
        $this->assertEquals(5, count($service->getCommands()));
        $this->assertInstanceOf('Guzzle\\Common\\NullObject', $service->getCommand('missing'));

        $command = $service->getCommand('test');
        $this->assertInstanceOf('Guzzle\\Service\\Description\\ApiCommand', $command);
        $this->assertEquals('test', $command->getName());
        $this->assertFalse($command->canBatch());
        $this->assertInternalType('array', $command->getArgs());

        $this->assertEquals(array(
            'name' => 'bucket',
            'required' => true,
            'location' => 'path',
            'doc' => 'Bucket location'
        ), $command->getArg('bucket')->getAll());

        $this->assertEquals('DELETE', $command->getMethod());
        $this->assertEquals('2', $command->getMinArgs());
        $this->assertEquals('{{ bucket }}/{{ key }}{{ format }}', $command->getPath());
        $this->assertEquals('Documentation', $command->getDoc());

        $this->assertArrayHasKey('custom_filter', Inspector::getInstance()->getRegisteredFilters());
    }

    /**
     * @covers Guzzle\Service\Description\XmlDescriptionBuilder
     * @covers Guzzle\Service\Description\ServiceDescription
     */
    public function testBuildsServiceUsingXml()
    {
        $xml = <<<EOT
<?xml version="1.0" encoding="UTF-8"?>
<client>
    <types>
        <type name="slug" class="Guzzle.Common.InspectorFilter.Regex" default_args="/[0-9a-zA-z_\-]+/" />
    </types>
    <commands>
        <command name="abstract" method="GET" path="/path/{{def}}" min_args="2">
            <param name="st" static="static" />
            <param name="def" default="123" location="path" />
        </command>
        <command name="test1" extends="abstract">
            <param name="hd" type="string" required="true" location="header:X-Hd" />
        </command>
        <command name="test2" extends="abstract" method="DELETE" />
        <command name="test3" class="Guzzle.Service.Command.ClosureCommand">
            <param name="a" type="string" required="true" />
        </command>
    </commands>
</client>
EOT;
        
        $builder = new XmlDescriptionBuilder($xml);
        $service = $builder->build();
        $this->arrayHasKey('slug', Inspector::getInstance()->getRegisteredFilters());
        $this->assertTrue($service->hasCommand('abstract'));
        $this->assertTrue($service->hasCommand('test1'));
        $this->assertTrue($service->hasCommand('test1'));
        $this->assertTrue($service->hasCommand('test2'));
        $this->assertTrue($service->hasCommand('test3'));
        
        $test1 = $service->getCommand('test1');
        $test2 = $service->getCommand('test2');
        $test3 = $service->getCommand('test3');

        $this->assertEquals('GET', $test1->getMethod());
        $this->assertEquals('/path/{{def}}', $test1->getPath());
        $this->assertEquals('2', $test1->getMinArgs());
        $this->assertEquals('static', $test1->getArg('st')->get('static'));
        $this->assertEquals('123', $test1->getArg('def')->get('default'));

        $this->assertEquals('DELETE', $test2->getMethod());
        $this->assertEquals('/path/{{def}}', $test1->getPath());
        $this->assertEquals('2', $test1->getMinArgs());
        $this->assertEquals('static', $test1->getArg('st')->get('static'));
        $this->assertEquals('123', $test1->getArg('def')->get('default'));
        $this->assertEquals('header:X-Hd', $test1->getArg('hd')->get('location'));

        $this->assertEquals('', $test3->getMethod());
        $this->assertEquals('Guzzle\Service\Command\ClosureCommand', $test3->getConcreteClass());
    }

    /**
     * @covers Guzzle\Service\Description\XmlDescriptionBuilder
     * @expectedException RuntimeException
     */
    public function testvalidatesXmlExtensions()
    {
        $xml = <<<EOT
<?xml version="1.0" encoding="UTF-8"?>
<client><commands><command name="invalid" extends="abstract" method="DELETE" /></commands></client>
EOT;
        $builder = new XmlDescriptionBuilder($xml);
        $service = $builder->build();
    }
}