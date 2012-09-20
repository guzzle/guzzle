<?php

namespace Guzzle\Tests\Service\Command\LocationVisitor\Response;

use Guzzle\Service\Description\Parameter;
use Guzzle\Http\Message\Response;
use Guzzle\Service\Command\LocationVisitor\Response\XmlVisitor as Visitor;

/**
 * @covers Guzzle\Service\Command\LocationVisitor\Response\XmlVisitor
 */
class XmlVisitorTest extends AbstractResponseVisitorTest
{
    public function testCanExtractAndRenameTopLevelXmlValues()
    {
        $visitor = new Visitor();
        $param = new Parameter(array('location' => 'xml', 'name' => 'foo', 'rename' => 'Bar'));
        $value = array('Bar' => 'test');
        $visitor->visit($this->command, $this->response, $param, $value);
        $this->assertArrayHasKey('foo', $value);
        $this->assertEquals('test', $value['foo']);
    }

    public function testEnsuresArraysAreInCorrectLocations()
    {
        $visitor = new Visitor();
        $param = new Parameter(array(
            'location' => 'xml',
            'name'     => 'foo',
            'rename'   => 'Foo',
            'type'     => 'array',
            'items'    => array(
                'type' => 'object',
                'properties' => array(
                    'Bar' => array('type' => 'string'),
                    'Baz' => array('type' => 'string')
                )
            )
        ));

        $xml = new \SimpleXMLElement('<Test><Foo><Bar>1</Bar><Baz>2</Baz></Foo></Test>');
        $value = json_decode(json_encode($xml), true);
        $visitor->visit($this->command, $this->response, $param, $value);
        $this->assertEquals(array(
            'foo' => array(
                array (
                    'Bar' => '1',
                    'Baz' => '2'
                )
            )
        ), $value);
    }
}
