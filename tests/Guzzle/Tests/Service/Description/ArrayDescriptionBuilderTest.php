<?php

namespace Guzzle\Tests\Service\Description;

use Guzzle\Service\Inspector;
use Guzzle\Service\Description\ServiceDescription;
use Guzzle\Service\Description\ArrayDescriptionBuilder;

/**
 * @covers Guzzle\Service\Description\ArrayDescriptionBuilder
 */
class ArrayDescriptionBuilderTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Description\ServiceDescription::factory
     * @covers Guzzle\Service\Description\ArrayDescriptionBuilder::build
     */
    public function testAllowsDeepNestedInheritance()
    {
        $d = ServiceDescription::factory(array(
            'commands' => array(
                'abstract' => array(
                    'method' => 'GET',
                    'params' => array(
                        'test' => array(
                            'type' => 'string',
                            'required' => true
                        )
                    )
                ),
                'abstract2' => array(
                    'uri'     => '/test',
                    'extends' => 'abstract'
                ),
                'concrete' => array(
                    'extends' => 'abstract2'
                )
            )
        ));

        $c = $d->getCommand('concrete');
        $this->assertEquals('/test', $c->getUri());
        $this->assertEquals('GET', $c->getMethod());
        $params = $c->getParams();
        $param = $params['test'];
        $this->assertEquals('string', $param->getType());
        $this->assertTrue($param->getRequired());
    }

    /**
     * @covers Guzzle\Service\Description\ServiceDescription::factory
     * @covers Guzzle\Service\Description\ArrayDescriptionBuilder::build
     * @expectedException RuntimeException
     */
    public function testThrowsExceptionWhenExtendingMissingCommand()
    {
        ServiceDescription::factory(array(
            'commands' => array(
                'concrete' => array(
                    'extends' => 'missing'
                )
            )
        ));
    }

    public function testRegistersCustomTypes()
    {
        ServiceDescription::factory(array(
            'types' => array(
                'foo' => array(
                    'class' => 'Guzzle\\Common\\Validation\\Regex',
                    'pattern' => '/[0-9]+/'
                )
            )
        ));

        $valid = Inspector::getInstance()->validateConstraint('foo', 'abc');
        $this->assertEquals('abc does not match the regular expression', $valid);
    }

    /**
     * @expectedException RuntimeException
     * @expectedExceptionMessage Custom types require a class attribute
     */
    public function testCustomTypesRequireClassAttribute()
    {
        ServiceDescription::factory(array(
            'types' => array(
                'slug' => array()
            )
        ));
    }

    public function testAllowsMultipleInheritance()
    {
        $description = ServiceDescription::factory(array(
            'commands' => array(
                'a' => array(
                    'method' => 'GET',
                    'params' => array(
                        'a1' => array(
                            'default'  => 'foo',
                            'required' => true,
                            'prepend'  => 'hi'
                        )
                    )
                ),
                'b' => array(
                    'extends' => 'a',
                    'params' => array(
                        'b2' => array()
                    )
                ),
                'c' => array(
                    'params' => array(
                        'a1' => array(
                            'default'  => 'bar',
                            'required' => true,
                            'doc'      => 'test'
                        ),
                        'c3' => array()
                    )
                ),
                'd' => array(
                    'method'  => 'DELETE',
                    'extends' => array('b', 'c'),
                    'params'  => array(
                        'test' => array()
                    )
                )
            )
        ));

        $command = $description->getCommand('d');
        $this->assertEquals('DELETE', $command->getMethod());
        $this->assertContains('a1', $command->getParamNames());
        $this->assertContains('b2', $command->getParamNames());
        $this->assertContains('c3', $command->getParamNames());
        $this->assertContains('test', $command->getParamNames());

        $this->assertTrue($command->getParam('a1')->getRequired());
        $this->assertEquals('bar', $command->getParam('a1')->getDefault());
        $this->assertEquals('test', $command->getParam('a1')->getDoc());
        $this->assertNull($command->getParam('a1')->getPrepend());
    }
}
