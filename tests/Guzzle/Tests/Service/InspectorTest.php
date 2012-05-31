<?php

namespace Guzzle\Tests\Common;

use Guzzle\Common\Collection;
use Guzzle\Service\Inspector;
use Guzzle\Service\Description\ApiParam;
use Guzzle\Service\Description\ApiCommand;
use Guzzle\Service\Exception\ValidationException;

/**
 * @covers Guzzle\Service\Inspector
 */
class InspectorTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Inspector::setTypeValidation
     * @covers Guzzle\Service\Inspector::getTypeValidation
     */
    public function testTypeValidationCanBeToggled()
    {
        $i = new Inspector();
        $this->assertTrue($i->getTypeValidation());
        $i->setTypeValidation(false);
        $this->assertFalse($i->getTypeValidation());
    }

    /**
     * @cover Guzzle\Service\Inspector::__constructor
     */
    public function testRegistersDefaultFilters()
    {
        $inspector = new Inspector();
        $this->assertNotEmpty($inspector->getRegisteredConstraints());
    }

    /**
     * @covers Guzzle\Service\Inspector
     * @expectedException InvalidArgumentException
     */
    public function testChecksFilterValidity()
    {
        Inspector::getInstance()->getConstraint('foooo');
    }

    /**
     * @covers Guzzle\Service\Inspector::prepareConfig
     */
    public function testPreparesConfig()
    {
        $c = Inspector::prepareConfig(array(
            'a' => '123',
            'base_url' => 'http://www.test.com/'
        ), array(
            'a' => 'xyz',
            'b' => 'lol'
        ), array('a'));

        $this->assertInstanceOf('Guzzle\Common\Collection', $c);
        $this->assertEquals(array(
            'a' => '123',
            'b' => 'lol',
            'base_url' => 'http://www.test.com/'
        ), $c->getAll());

        try {
            $c = Inspector::prepareConfig(null, null, array('a'));
            $this->fail('Exception not throw when missing config');
        } catch (ValidationException $e) {
        }
    }

    /**
     * @covers Guzzle\Service\Inspector::registerConstraint
     * @covers Guzzle\Service\Inspector::getConstraint
     * @covers Guzzle\Service\Inspector::getRegisteredConstraints
     */
    public function testRegistersCustomConstraints()
    {
        $constraintClass = 'Guzzle\\Common\\Validation\\Ip';

        Inspector::getInstance()->registerConstraint('mock', $constraintClass);
        Inspector::getInstance()->registerConstraint('mock_2', $constraintClass, array(
           'version' => '4'
        ));

        $this->assertArrayHasKey('mock', Inspector::getInstance()->getRegisteredConstraints());
        $this->assertArrayHasKey('mock_2', Inspector::getInstance()->getRegisteredConstraints());

        $this->assertInstanceOf($constraintClass, Inspector::getInstance()->getConstraint('mock'));
        $this->assertInstanceOf($constraintClass, Inspector::getInstance()->getConstraint('mock_2'));

        $this->assertTrue(Inspector::getInstance()->validateConstraint('mock', '192.168.16.121'));
        $this->assertTrue(Inspector::getInstance()->validateConstraint('mock_2', '10.1.1.0'));
    }
}
