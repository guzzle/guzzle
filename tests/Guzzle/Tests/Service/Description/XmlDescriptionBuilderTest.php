<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

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
        $builder = new XmlDescriptionBuilder(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'test_service.xml');
        $service = $builder->build();
        $this->assertTrue($service->hasCommand('search'));
        $this->assertTrue($service->hasCommand('test'));
        $this->assertTrue($service->hasCommand('trends.location'));
        $this->assertTrue($service->hasCommand('geo.id'));
        $this->assertInstanceOf('Guzzle\\Service\\Description\\ApiCommand', $service->getCommand('search'));
        $this->assertInternalType('array', $service->getCommands());
        $this->assertEquals(4, count($service->getCommands()));
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
        <command name="geo.id" method="GET" auth_required="true" path="/geo/id/:place_id">
            <param name="place_id" type="string" required="true"/>
        </command>
    </commands>
</client>
EOT;
        
        $builder = new XmlDescriptionBuilder($xml);
        $service = $builder->build();
        $this->assertTrue($service->hasCommand('geo.id'));
        $this->arrayHasKey('slug', Inspector::getInstance()->getRegisteredFilters());
    }
}