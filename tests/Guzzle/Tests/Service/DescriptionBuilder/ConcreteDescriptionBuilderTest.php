<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\DescriptionBuilder;

use Guzzle\Service\ServiceDescription;
use Guzzle\Service\DescriptionBuilder\ConcreteDescriptionBuilder;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class ConcreteDescriptionBuilderTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\DescriptionBuilder\ConcreteDescriptionBuilder
     * @expectedException ReflectionException
     */
    public function testConcreteBuilder()
    {
        $builder = new ConcreteDescriptionBuilder('Does\\Not\\Exist\\Client');
    }

    /**
     * @covers Guzzle\Service\DescriptionBuilder\ConcreteDescriptionBuilder
     */
    public function testBuildsServiceUsingClasses()
    {
        $builder = new ConcreteDescriptionBuilder('Guzzle\\Tests\\Service\\Mock\\MockClient');
        $desc = $builder->build();
        $this->assertEquals('Mock', $desc->getName());
        $this->assertEquals('Mock Guzzle Service', $desc->getDescription());

        // We don't care about the base url
        $this->assertEquals('', $desc->getBaseUrl());

        // Make sure that one of the known commands was created correctly
        $this->assertTrue($desc->hasCommand('sub.sub'));

        // Ensure that multiple commands were added to the service
        $this->assertTrue(count($desc->getCommands()) > 1);

        // Grab a command by name from the description
        $this->assertInstanceOf('Guzzle\\Service\\ApiCommand', $desc->getCommand('mock_command'));

        // Make sure that private values are not arguments
        $this->assertArrayNotHasKey('_internal', $desc->getCommand('mock_command')->getArgs());
    }
}