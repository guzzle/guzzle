<?php

namespace Guzzle\Tests\Service\Description;

use Guzzle\Service\Description\Parameter;
use Guzzle\Service\Description\DefaultProcessor;

/**
 * @covers Guzzle\Service\Description\DefaultProcessor
 */
class DefaultProcessorTest extends \Guzzle\Tests\GuzzleTestCase
{
    public function testValidatesArrayListsAreNumericallyIndexed()
    {
        $value = array(array(1));
        $this->assertEquals(
            array('[Foo][0] must be an associative array of properties. Got a numerically indexed array.'),
            $this->getComplexParam()->process($value)
        );
    }

    public function testValidatesArrayListsContainProperItems()
    {
        $value = array(true);
        $this->assertEquals(
            array('[Foo][0] must be of type object'),
            $this->getComplexParam()->process($value)
        );
    }

    public function testAddsDefaultValuesInLists()
    {
        $value = array(array());
        $this->assertTrue($this->getComplexParam()->process($value));
        $this->assertEquals(array(array('Bar' => true)), $value);
    }

    public function testMergesDefaultValuesInLists()
    {
        $value = array(
            array('Baz' => 'hello!'),
            array('Bar' => false)
        );
        $this->assertTrue($this->getComplexParam()->process($value));
        $this->assertEquals(array(
            array(
                'Baz' => 'hello!',
                'Bar' => true
            ),
            array('Bar' => false)
        ), $value);
    }

    public function testCorrectlyConvertsParametersToArrayWhenArraysArePresent()
    {
        $param = $this->getComplexParam();
        $result = $param->toArray();
        $this->assertInternalType('array', $result['items']);
        $this->assertEquals('array', $result['type']);
        $this->assertInstanceOf('Guzzle\Service\Description\Parameter', $param->getItems());
    }

    public function testAllowsInstanceOf()
    {
        $p = new Parameter(array(
            'name'       => 'foo',
            'type'       => 'object',
            'instanceOf' => get_class($this)
        ));
        $this->assertTrue($p->process($this));
        $this->assertEquals(array('[foo] must be an instance of ' . __CLASS__), $p->process($p));
    }

    public function testModifiesArrayAccessObjects()
    {
        $p = new Parameter(array(
            'name'       => 'foo',
            'type'       => 'object',
            'properties' => array('bar' => array('default' => 'test'))
        ));
        $a = new \ArrayObject();
        $this->assertTrue($p->process($a));
        $this->assertEquals('test', $a['bar']);
    }

    public function testModifiesNestedArrayAccessObjects()
    {
        $p = new Parameter(array(
            'name'       => 'foo',
            'type'       => 'object',
            'properties' => array(
                'bar' => array('default' => 'test'),
                'baz' => array('type' => 'object', 'additionalProperties' => array('type' => 'string'))
            )
        ));
        $b = new \ArrayObject(array('string!'));
        $a = new \ArrayObject(array(
            'baz' => $b
        ));
        $this->assertTrue($p->process($a));
        $this->assertEquals('test', $a['bar']);
        $this->assertEquals($b, $a['baz']);
    }

    public function testMergesValidationErrorsInPropertiesWithParent()
    {
        $p = new Parameter(array(
            'name'       => 'foo',
            'type'       => 'object',
            'properties' => array(
                'bar'   => array('type' => 'string', 'required' => true, 'description' => 'This is what it does'),
                'test'  => array('type' => 'string', 'min' => 2, 'max' => 5),
                'test2' => array('type' => 'string', 'min' => 2, 'max' => 2),
                'test3' => array('type' => 'integer', 'min' => 100),
                'test4' => array('type' => 'integer', 'max' => 10),
                'test5' => array('type' => 'array', 'max' => 2),
                'test6' => array('type' => 'string', 'enum' => array('a', 'bc')),
                'test7' => array('type' => 'string', 'pattern' => '/[0-9]+/'),
                'baz' => array(
                    'type'     => 'array',
                    'min'      => 2,
                    'required' => true,
                    "items"    => array("type" => "string")
                )
            )
        ));

        $value = array(
            'test' => 'a',
            'test2' => 'abc',
            'baz' => array(false),
            'test3' => 10,
            'test4' => 100,
            'test5' => array(1, 3, 4),
            'test6' => 'Foo',
            'test7' => 'abc'
        );

        $this->assertEquals(array (
            '[foo][bar] is a required string: This is what it does',
            '[foo][baz] must contain 2 or more elements',
            '[foo][baz][0] must be of type string',
            '[foo][test2] length must be greater than or equal to 2',
            '[foo][test3] must be greater than or equal to 100',
            '[foo][test4] must be less than or equal to 10',
            '[foo][test5] must contain 2 or fewer elements',
            '[foo][test6] must be one of "a" or "bc"',
            '[foo][test7] must match the following regular expression: /[0-9]+/',
            '[foo][test] length must be greater than or equal to 2',
        ), $p->process($value));
    }

    public function testHandlesNullValuesInArraysWithDefaults()
    {
        $p = new Parameter(array(
            'name'       => 'foo',
            'type'       => 'object',
            'required'   => true,
            'properties' => array(
                'bar' => array(
                    'type' => 'object',
                    'required' => true,
                    'properties' => array(
                        'foo' => array('default' => 'hi')
                    )
                )
            )
        ));
        $value = array();
        $this->assertTrue($p->process($value));
        $this->assertEquals(array('bar' => array('foo' => 'hi')), $value);
    }

    public function testFailsWhenNullValuesInArraysWithNoDefaults()
    {
        $p = new Parameter(array(
            'name'       => 'foo',
            'type'       => 'object',
            'required'   => true,
            'properties' => array(
                'bar' => array(
                    'type' => 'object',
                    'required' => true,
                    'properties' => array('foo' => array('type' => 'string'))
                )
            )
        ));
        $value = array();
        $this->assertEquals(array('[foo][bar] is a required object'), $p->process($value));
    }

    public function testChecksTypes()
    {
        $p = new DefaultProcessor();
        $r = new \ReflectionMethod($p, 'checkType');
        $r->setAccessible(true);
        $this->assertTrue($r->invoke($p, '', 'hello'));
        $this->assertTrue($r->invoke($p, 'any', 'hello'));
        $this->assertTrue($r->invoke($p, 'string', 'hello'));
        $this->assertTrue($r->invoke($p, 'integer', 1));
        $this->assertTrue($r->invoke($p, 'numeric', 1));
        $this->assertTrue($r->invoke($p, 'numeric', '1'));
        $this->assertTrue($r->invoke($p, 'boolean', true));
        $this->assertTrue($r->invoke($p, 'boolean', false));
        $this->assertFalse($r->invoke($p, 'boolean', 'false'));
        $this->assertTrue($r->invoke($p, 'null', null));
        $this->assertTrue($r->invoke($p, 'foo', 'foo'));
    }

    public function testValidatesFalseAdditionalProperties()
    {
        $param = new Parameter(array(
            'name'      => 'foo',
            'type'      => 'object',
            'properties' => array('bar' => array('type' => 'string')),
            'additionalProperties' => false
        ));
        $value = array('test' => '123');
        $this->assertEquals(array('[foo][test] is not an allowed property'), $param->process($value));
        $value = array('bar' => '123');
        $this->assertTrue($param->process($value));
    }

    public function testAllowsUndefinedAdditionalProperties()
    {
        $param = new Parameter(array(
            'name'      => 'foo',
            'type'      => 'object',
            'properties' => array('bar' => array('type' => 'string'))
        ));
        $value = array('test' => '123');
        $this->assertTrue($param->process($value));
    }

    public function testValidatesAdditionalProperties()
    {
        $param = new Parameter(array(
            'name'      => 'foo',
            'type'      => 'object',
            'properties' => array('bar' => array('type' => 'string')),
            'additionalProperties' => array('type' => 'integer')
        ));
        $value = array('test' => 'foo');
        $this->assertEquals(array('[foo][test] must be of type integer'), $param->process($value));
    }

    public function testValidatesAdditionalPropertiesThatArrayArrays()
    {
        $param = new Parameter(array(
            'name' => 'foo',
            'type' => 'object',
            'additionalProperties' => array(
                'type'  => 'array',
                'items' => array('type' => 'string')
            )
        ));
        $value = array('test' => array(true));
        $this->assertEquals(array('[foo][test][0] must be of type string'), $param->process($value));
    }

    protected function getComplexParam()
    {
        return new Parameter(array(
            'name'     => 'Foo',
            'type'     => 'array',
            'required' => true,
            'min'      => 1,
            'items'    => array(
                'type'       => 'object',
                'properties' => array(
                    'Baz' => array(
                        'type'    => 'string',
                    ),
                    'Bar' => array(
                        'required' => true,
                        'type'     => 'boolean',
                        'default'  => true
                    )
                )
            )
        ));
    }
}
