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
        $param = new Parameter(array(
            'location' => 'xml',
            'name'     => 'foo',
            'rename'   => 'Bar'
        ));
        $value = array('foo' => 'test');
        $visitor->visit($this->command, $this->response, $param, $value);
        $this->assertArrayHasKey('Bar', $value);
        $this->assertEquals('test', $value['Bar']);
    }

    public function testEnsuresRepeatedArraysAreInCorrectLocations()
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
                    'Baz' => array('type' => 'string'),
                    'Bam' => array('type' => 'string')
                )
            )
        ));

        $xml = new \SimpleXMLElement('<Test><foo><Bar>1</Bar><Baz>2</Baz></foo></Test>');
        $value = json_decode(json_encode($xml), true);
        // Set a null value to ensure it is ignored
        //$value['foo'][0]['Bam'] = null;
        $visitor->visit($this->command, $this->response, $param, $value);
        $this->assertEquals(array(
            'Foo' => array(
                array (
                    'Bar' => '1',
                    'Baz' => '2'
                )
            )
        ), $value);
    }

    public function testEnsuresFlatArraysAreFlat()
    {
        $visitor = new Visitor();
        $param = new Parameter(array(
            'location' => 'xml',
            'name'     => 'foo',
            'type'     => 'array',
            'items'    => array('type' => 'string')
        ));

        $value = array('foo' => array('bar', 'baz'));
        $visitor->visit($this->command, $this->response, $param, $value);
        $this->assertEquals(array('foo' => array('bar', 'baz')), $value);

        $value = array('foo' => 'bar');
        $visitor->visit($this->command, $this->response, $param, $value);
        $this->assertEquals(array('foo' => array('bar')), $value);
    }

    public function xmlDataProvider()
    {
        $param = new Parameter(array(
            'location' => 'xml',
            'name'     => 'Items',
            'type'     => 'array',
            'items'    => array(
                'type' => 'object',
                'name' => 'Item',
                'properties' => array(
                    'Bar' => array('type' => 'string'),
                    'Baz' => array('type' => 'string')
                )
            )
        ));

        return array(
            array($param, '<Test><Items><Item><Bar>1</Bar></Item><Item><Bar>2</Bar></Item></Items></Test>', array(
                'Items' => array(
                    array('Bar' => 1),
                    array('Bar' => 2)
                )
            )),
            array($param, '<Test><Items><Item><Bar>1</Bar></Item></Items></Test>', array(
                'Items' => array(
                    array('Bar' => 1)
                )
            )),
            array($param, '<Test><Items /></Test>', array(
                'Items' => array()
            ))
        );
    }

    /**
     * @dataProvider xmlDataProvider
     */
    public function testEnsuresWrappedArraysAreInCorrectLocations($param, $xml, $result)
    {
        $visitor = new Visitor();
        $xml = new \SimpleXMLElement($xml);
        $value = json_decode(json_encode($xml), true);
        $visitor->visit($this->command, $this->response, $param, $value);
        $this->assertEquals($result, $value);
    }

    public function testCanRenameValues()
    {
        $visitor = new Visitor();
        $param = new Parameter(array(
            'name' => 'instancesSet',
            'type' => 'array',
            'location' => 'xml',
            'rename' => 'TerminatingInstances',
            'items' => array(
                'name' => 'item',
                'type' => 'object',
                'rename' => 'item',
                'properties' => array(
                    'instanceId' => array(
                        'type' => 'string',
                        'rename' => 'InstanceId',
                    ),
                    'currentState' => array(
                        'type' => 'object',
                        'rename' => 'CurrentState',
                        'properties' => array(
                            'code' => array(
                                'type' => 'numeric',
                                'rename' => 'Code',
                            ),
                            'name' => array(
                                'type' => 'string',
                                'rename' => 'Name',
                            ),
                        ),
                    ),
                    'previousState' => array(
                        'type' => 'object',
                        'rename' => 'PreviousState',
                        'properties' => array(
                            'code' => array(
                                'type' => 'numeric',
                                'rename' => 'Code',
                            ),
                            'name' => array(
                                'type' => 'string',
                                'rename' => 'Name',
                            ),
                        ),
                    ),
                ),
            )
        ));

        $value = array(
            'instancesSet' => array (
                'item' => array (
                    'instanceId' => 'i-3ea74257',
                    'currentState' => array(
                        'code' => '32',
                        'name' => 'shutting-down',
                    ),
                    'previousState' => array(
                        'code' => '16',
                        'name' => 'running',
                    ),
                ),
            )
        );

        $visitor->visit($this->command, $this->response, $param, $value);

        $this->assertEquals(array(
            'TerminatingInstances' => array(
                array(
                    'InstanceId' => 'i-3ea74257',
                    'CurrentState' => array(
                        'Code' => '32',
                        'Name' => 'shutting-down',
                    ),
                    'PreviousState' => array(
                        'Code' => '16',
                        'Name' => 'running',
                    )
                )
            )
        ), $value);
    }
}
