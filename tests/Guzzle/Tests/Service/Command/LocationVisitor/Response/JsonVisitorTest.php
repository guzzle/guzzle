<?php

namespace Guzzle\Tests\Service\Command\LocationVisitor\Response;

use Guzzle\Service\Description\Parameter;
use Guzzle\Http\Message\Response;
use Guzzle\Service\Command\LocationVisitor\Response\JsonVisitor as Visitor;

/**
 * @covers Guzzle\Service\Command\LocationVisitor\Response\JsonVisitor
 */
class JsonVisitorTest extends AbstractResponseVisitorTest
{
    public function testBeforeMethodParsesXml()
    {
        $visitor = new Visitor();
        $command = $this->getMockBuilder('Guzzle\Service\Command\AbstractCommand')
            ->setMethods(array('getResponse'))
            ->getMockForAbstractClass();
        $command->expects($this->once())
            ->method('getResponse')
            ->will($this->returnValue(new Response(200, null, '{"foo":"bar"}')));
        $result = array();
        $visitor->before($command, $result);
        $this->assertEquals(array('foo' => 'bar'), $result);
    }

    public function testVisitsLocation()
    {
        $visitor = new Visitor();
        $param = new Parameter(array(
            'name' => 'foo',
            'type' => 'array',
            'items' => array(
                'filters' => 'strtoupper',
                'type'    => 'string'
            )
        ));
        $this->value = array('foo' => array('a', 'b', 'c'));
        $visitor->visit($this->command, $this->response, $param, $this->value);
        $this->assertEquals(array('A', 'B', 'C'), $this->value['foo']);
    }

    public function testRenamesTopLevelValues()
    {
        $visitor = new Visitor();
        $param = new Parameter(array(
            'name'   => 'foo',
            'sentAs' => 'Baz',
            'type'   => 'string',
        ));
        $this->value = array('Baz' => 'test');
        $visitor->visit($this->command, $this->response, $param, $this->value);
        $this->assertEquals(array('foo' => 'test'), $this->value);
    }

    public function testRenamesDoesNotFailForNonExistentKey()
    {
        $visitor = new Visitor();
        $param = new Parameter(array(
            'name'          => 'foo',
            'type'          => 'object',
            'properties'    => array(
                'bar' => array(
                    'name'      => 'bar',
                    'sentAs'    => 'baz',
                ),
            ),
        ));
        $this->value = array('foo' => array('unknown' => 'Unknown'));
        $visitor->visit($this->command, $this->response, $param, $this->value);
        $this->assertEquals(array('foo' => array('unknown' => 'Unknown')), $this->value);
    }

    public function testTraversesObjectsAndAppliesFilters()
    {
        $visitor = new Visitor();
        $param = new Parameter(array(
            'name' => 'foo',
            'type' => 'object',
            'properties' => array(
                'foo' => array('filters' => 'strtoupper'),
                'bar' => array('filters' => 'strtolower')
            )
        ));
        $this->value = array('foo' => array('foo' => 'hello', 'bar' => 'THERE'));
        $visitor->visit($this->command, $this->response, $param, $this->value);
        $this->assertEquals(array('foo' => 'HELLO', 'bar' => 'there'), $this->value['foo']);
    }
}
