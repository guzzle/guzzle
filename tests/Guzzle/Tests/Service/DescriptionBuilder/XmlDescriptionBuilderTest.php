<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\DescriptionBuilder;

use Guzzle\Common\Inspector;
use Guzzle\Service\ServiceDescription;
use Guzzle\Service\DescriptionBuilder\XmlDescriptionBuilder;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class XmlDescriptionBuilderTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\DescriptionBuilder\XmlDescriptionBuilder
     * @expectedException InvalidArgumentException
     */
    public function testXmlBuilderThrowsExceptionWhenFileIsNotFound()
    {
        $builder = new XmlDescriptionBuilder('file_not_found');
    }

    /**
     * @covers Guzzle\Service\DescriptionBuilder\XmlDescriptionBuilder
     * @covers Guzzle\Service\ServiceDescription
     */
    public function testBuildsServiceUsingFile()
    {
        $builder = new XmlDescriptionBuilder(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'test_service.xml');
        $service = $builder->build();
        $this->assertEquals('Test Service', $service->getName());
        $this->assertEquals('Description', $service->getDescription());
        $this->assertEquals('http://www.test.com/', $service->getBaseUrl());
        $this->assertTrue($service->hasCommand('search'));
        $this->assertTrue($service->hasCommand('test'));
        $this->assertTrue($service->hasCommand('trends.location'));
        $this->assertTrue($service->hasCommand('geo.id'));
        $this->assertInstanceOf('Guzzle\\Service\\ApiCommand', $service->getCommand('search'));
        $this->assertInternalType('array', $service->getCommands());
        $this->assertEquals(4, count($service->getCommands()));
        $this->assertInstanceOf('Guzzle\\Common\\NullObject', $service->getCommand('missing'));

        $command = $service->getCommand('test');
        $this->assertInstanceOf('Guzzle\\Service\\ApiCommand', $command);
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
     * @covers Guzzle\Service\DescriptionBuilder\XmlDescriptionBuilder
     * @covers Guzzle\Service\ServiceDescription
     */
    public function testBuildsServiceUsingXml()
    {
        $xml = <<<EOT
<?xml version="1.0" encoding="UTF-8"?>
<service>
    <name>Test Service</name>
    <description>Description</description>
    <base_url>{{ protocol }}://www.test.com/</base_url>
    <client>Guzzle.Service.Client</client>
    <types>
        <type name="slug" class="Guzzle.Common.InspectorFilter.Regex" default_args="/[0-9a-zA-z_\-]+/" />
    </types>
    <commands>
        <command name="geo.id" method="GET" auth_required="true" path="/geo/id/:place_id">
            <param name="place_id" type="string" required="true"/>
        </command>
    </commands>
</service>
EOT;
        
        $builder = new XmlDescriptionBuilder($xml);
        $service = $builder->build();
        $this->assertEquals('Test Service', $service->getName());
        $this->assertEquals('Description', $service->getDescription());
        $this->assertEquals('{{ protocol }}://www.test.com/', $service->getBaseUrl());
        $this->assertTrue($service->hasCommand('geo.id'));
        $this->assertTrue(is_array($service->getClientArgs()));
        $this->arrayHasKey('slug', Inspector::getInstance()->getRegisteredFilters());
    }
}