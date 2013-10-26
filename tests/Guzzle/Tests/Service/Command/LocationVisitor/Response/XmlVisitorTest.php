<?php

namespace Guzzle\Tests\Service\Command\LocationVisitor\Response;

use Guzzle\Service\Description\Parameter;
use Guzzle\Http\Message\Response;
use Guzzle\Tests\Service\Mock\Response\XmlVisitor as Visitor;
use SimpleXMLElement;

/**
 * @covers Guzzle\Service\Command\LocationVisitor\Response\XmlVisitor
 */
class XmlVisitorTest extends AbstractResponseVisitorTest
{
    public function testBeforeMethodParsesXml()
    {
        $visitor = new Visitor();
        $command = $this->getMockBuilder('Guzzle\Service\Command\AbstractCommand')
            ->setMethods(array('getResponse'))
            ->getMockForAbstractClass();
        $command->expects($this->once())
            ->method('getResponse')
            ->will($this->returnValue(new Response(200, null, '<foo><Bar>test</Bar></foo>')));
        $result = array();
        $visitor->before($command, $result);
        $this->assertInstanceOf('SimpleXMLElement', $visitor->getXml());
        $this->assertEquals('test', $visitor->getXml()->Bar);
    }

    public function testBeforeMethodParsesXmlWithNamespace()
    {
        $visitor = new Visitor();
        $command = $this->getMockBuilder('Guzzle\Service\Command\AbstractCommand')
            ->setMethods(array('getResponse'))
            ->getMockForAbstractClass();
        $command->expects($this->once())
            ->method('getResponse')
            ->will($this->returnValue(new Response(200, null, '<foo xmlns="urn:foo"><bar:Bar xmlns:bar="urn:bar">test</bar:Bar></foo>')));
        $result = array();
        $visitor->before($command, $result);
        $this->assertInstanceOf('SimpleXMLElement', $visitor->getXml());
        $this->assertEquals('test', $visitor->getXml()->children('bar', true)->Bar);
    }

    public function testCanExtractAndRenameTopLevelXmlValues()
    {
        $value = array();
        $visitor = new Visitor();
        $param = new Parameter(array(
            'location' => 'xml',
            'name'     => 'foo',
            'sentAs'   => 'Bar'
        ));
        $visitor->setXml(new SimpleXMLElement('<xml><Bar>test</Bar></xml>'));
        $visitor->visit($this->command, $this->response, $param, $value);
        $this->assertArrayHasKey('foo', $value);
        $this->assertEquals('test', $value['foo']);
    }

    public function testEnsuresRepeatedArraysAreInCorrectLocations()
    {
        $visitor = new Visitor();
        $param = new Parameter(array(
            'location' => 'xml',
            'name'     => 'foo',
            'sentAs'   => 'Foo',
            'type'     => 'array',
            'items'    => array(
                'type'       => 'object',
                'properties' => array(
                    'Bar' => array('type' => 'string'),
                    'Baz' => array('type' => 'string'),
                    'Bam' => array('type' => 'string')
                )
            )
        ));

        $value = array();
        $xml = new SimpleXMLElement('<Test><Foo><Bar>1</Bar><Baz>2</Baz></Foo></Test>');
        $visitor->setXml($xml);
        $visitor->visit($this->command, $this->response, $param, $value);
        $this->assertEquals(array(
            'foo' => array(
                array(
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

        $value = array();
        $visitor->setXml(new SimpleXMLElement('<xml><foo>bar</foo><foo>baz</foo></xml>'));
        $visitor->visit($this->command, $this->response, $param, $value);
        $this->assertEquals(array('foo' => array('bar', 'baz')), $value);

        $value = array();
        $visitor->setXml(new SimpleXMLElement('<xml><foo>bar</foo></xml>'));
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
                'type'       => 'object',
                'name'       => 'Item',
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
        $xml = new SimpleXMLElement($xml);
//        $value = json_decode(json_encode($xml), true);
        $value = array();
        $visitor->setXml($xml);
        $visitor->visit($this->command, $this->response, $param, $value);
        $this->assertEquals($result, $value);
    }

    public function testCanRenameValues()
    {
        $visitor = new Visitor();
        $param = new Parameter(array(
            'name'     => 'TerminatingInstances',
            'type'     => 'array',
            'location' => 'xml',
            'sentAs'   => 'instancesSet',
            'items'    => array(
                'name'       => 'item',
                'type'       => 'object',
                'sentAs'     => 'item',
                'properties' => array(
                    'InstanceId'    => array(
                        'type'   => 'string',
                        'sentAs' => 'instanceId',
                    ),
                    'CurrentState'  => array(
                        'type'       => 'object',
                        'sentAs'     => 'currentState',
                        'properties' => array(
                            'Code' => array(
                                'type'   => 'numeric',
                                'sentAs' => 'code',
                            ),
                            'Name' => array(
                                'type'   => 'string',
                                'sentAs' => 'name',
                            ),
                        ),
                    ),
                    'PreviousState' => array(
                        'type'       => 'object',
                        'sentAs'     => 'previousState',
                        'properties' => array(
                            'Code' => array(
                                'type'   => 'numeric',
                                'sentAs' => 'code',
                            ),
                            'Name' => array(
                                'type'   => 'string',
                                'sentAs' => 'name',
                            ),
                        ),
                    ),
                ),
            )
        ));

        $value = array();
        $xml = new SimpleXMLElement('
            <xml>
                <instancesSet>
                    <item>
                        <instanceId>i-3ea74257</instanceId>
                        <currentState>
                            <code>32</code>
                            <name>shutting-down</name>
                        </currentState>
                        <previousState>
                            <code>16</code>
                            <name>running</name>
                        </previousState>
                    </item>
                </instancesSet>
            </xml>
        ');
        $visitor->setXml($xml);
        $visitor->visit($this->command, $this->response, $param, $value);

        $this->assertEquals(array(
            'TerminatingInstances' => array(
                array(
                    'InstanceId'    => 'i-3ea74257',
                    'CurrentState'  => array(
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

    public function testCanRenameAttributes()
    {
        $visitor = new Visitor();
        $param = new Parameter(array(
            'name'     => 'RunningQueues',
            'type'     => 'array',
            'location' => 'xml',
            'items'    => array(
                'type'       => 'object',
                'sentAs'     => 'item',
                'properties' => array(
                    'QueueId'       => array(
                        'type'   => 'string',
                        'sentAs' => 'queue_id',
                        'data'   => array(
                            'xmlAttribute' => true,
                        ),
                    ),
                    'CurrentState'  => array(
                        'type'       => 'object',
                        'properties' => array(
                            'Code' => array(
                                'type'   => 'numeric',
                                'sentAs' => 'code',
                                'data'   => array(
                                    'xmlAttribute' => true,
                                ),
                            ),
                            'Name' => array(
                                'sentAs' => 'name',
                                'data'   => array(
                                    'xmlAttribute' => true,
                                ),
                            ),
                        ),
                    ),
                    'PreviousState' => array(
                        'type'       => 'object',
                        'properties' => array(
                            'Code' => array(
                                'type'   => 'numeric',
                                'sentAs' => 'code',
                                'data'   => array(
                                    'xmlAttribute' => true,
                                ),
                            ),
                            'Name' => array(
                                'sentAs' => 'name',
                                'data'   => array(
                                    'xmlAttribute' => true,
                                ),
                            ),
                        ),
                    ),
                ),
            )
        ));

        $xml = new SimpleXMLElement('
            <wrap>
                <RunningQueues>
                    <item queue_id="q-3ea74257">
                        <CurrentState code="32" name="processing" />
                        <PreviousState code="16" name="wait" />
                    </item>
                </RunningQueues>
            </wrap>
        ');
        $value = array();
        $visitor->setXml($xml);
        $visitor->visit($this->command, $this->response, $param, $value);

        $this->assertEquals(array(
            'RunningQueues' => array(
                array(
                    'QueueId'       => 'q-3ea74257',
                    'CurrentState'  => array(
                        'Code' => '32',
                        'Name' => 'processing',
                    ),
                    'PreviousState' => array(
                        'Code' => '16',
                        'Name' => 'wait',
                    ),
                ),
            )
        ), $value);
    }

    public function testAddsEmptyArraysWhenValueIsMissing()
    {
        $visitor = new Visitor();
        $param = new Parameter(array(
            'name'     => 'Foo',
            'type'     => 'array',
            'location' => 'xml',
            'items'    => array(
                'type'       => 'object',
                'properties' => array(
                    'Baz' => array('type' => 'array'),
                    'Bar' => array(
                        'type'       => 'object',
                        'properties' => array(
                            'Baz' => array('type' => 'array'),
                        )
                    )
                )
            )
        ));

        $visitor->setXml(new SimpleXMLElement('<xml><Foo><Bar></Bar></Foo></xml>'));
        $visitor->visit($this->command, $this->response, $param, $value);
        $this->assertEquals(array(
            'Foo' => array(
                array(
                    'Bar' => array()
                )
            )
        ), $value);
    }

    /**
     * @group issue-399
     * @link  https://github.com/guzzle/guzzle/issues/399
     */
    public function testDiscardingUnknownProperties()
    {
        $visitor = new Visitor();
        $param = new Parameter(array(
            'name'                 => 'foo',
            'type'                 => 'object',
            'additionalProperties' => false,
            'properties'           => array(
                'bar' => array(
                    'type' => 'string',
                    'name' => 'bar',
                ),
            ),
        ));
        $value = array();
        $visitor->setXml(new SimpleXMLElement('
            <xml>
                <foo>
                    <bar>15</bar>
                    <unknown>discard me</unknown>
                </foo>
            </xml>
        '));
        $visitor->visit($this->command, $this->response, $param, $value);
        $this->assertEquals(array(
            'foo' => array(
                'bar' => 15
            )
        ), $value);
    }

    /**
     * @group issue-399
     * @link  https://github.com/guzzle/guzzle/issues/399
     */
    public function testDiscardingUnknownPropertiesWithAliasing()
    {
        $visitor = new Visitor();
        $param = new Parameter(array(
            'name'                 => 'foo',
            'type'                 => 'object',
            'additionalProperties' => false,
            'properties'           => array(
                'bar' => array(
                    'name'   => 'bar',
                    'sentAs' => 'baz',
                ),
            ),
        ));
        $value = array();
        $visitor->setXml(new SimpleXMLElement('
            <xml>
                <foo>
                    <baz>15</baz>
                    <unknown>discard me</unknown>
                </foo>
            </xml>
        '));
        $visitor->visit($this->command, $this->response, $param, $value);
        $this->assertEquals(array(
            'foo' => array(
                'bar' => 15
            )
        ), $value);
    }

    public function testProcessingOfNestedAdditionalProperties()
    {
        $visitor = new Visitor();
        $param = new Parameter(array(
            'name'                 => 'foo',
            'type'                 => 'object',
            'additionalProperties' => true,
            'properties'           => array(
                'bar'                        => array(
                    'name'   => 'bar',
                    'sentAs' => 'baz',
                ),
                'nestedNoAdditional'         => array(
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'properties'           => array(
                        'id' => array(
                            'type' => 'integer'
                        )
                    )
                ),
                'nestedWithAdditional'       => array(
                    'type'                 => 'object',
                    'additionalProperties' => true,
                ),
                'nestedWithAdditionalSchema' => array(
                    'type'                 => 'object',
                    'additionalProperties' => array(
                        'type'  => 'array',
                        'items' => array(
                            'type' => 'string'
                        )
                    ),
                ),
            ),
        ));
        $value = array();
        $visitor->setXml(new SimpleXMLElement('
            <xml>
                <foo>
                    <baz>15</baz>
                    <additional>include me</additional>
                    <nestedNoAdditional>
                        <id>15</id>
                        <unknown>discard me</unknown>
                    </nestedNoAdditional>
                    <nestedWithAdditional>
                        <id>15</id>
                        <additional>include me</additional>
                    </nestedWithAdditional>
                    <nestedWithAdditionalSchema>
                        <arrayA>
                            <item>1</item>
                            <item>2</item>
                            <item>3</item>
                        </arrayA>
                        <arrayB>
                            <item>A</item>
                            <item>B</item>
                            <item>C</item>
                        </arrayB>
                    </nestedWithAdditionalSchema>
                </foo>
            </xml>
        '));
        $visitor->visit($this->command, $this->response, $param, $value);
        $this->assertEquals(array(
            'foo' => array(
                'bar'                        => '15',
                'additional'                 => 'include me',
                'nestedNoAdditional'         => array(
                    'id' => '15'
                ),
                'nestedWithAdditional'       => array(
                    'id'         => '15',
                    'additional' => 'include me'
                ),
                'nestedWithAdditionalSchema' => array(
                    'arrayA' => array('1', '2', '3'),
                    'arrayB' => array('A', 'B', 'C'),
                )

            )
        ), $value);
    }

    public function testUnderstandsNamespaces()
    {
        $visitor = new Visitor();
        $param = new Parameter(array(
            'name'     => 'nstest',
            'type'     => 'array',
            'location' => 'xml',
            'items'    => array(
                'name'       => 'item',
                'type'       => 'object',
                'sentAs'     => 'item',
                'properties' => array(
                    'id'           => array(
                        'type' => 'string',
                    ),
                    'isbn:number'  => array(
                        'type' => 'string',
                    ),
                    'meta'         => array(
                        'type'       => 'object',
                        'sentAs'     => 'abstract:meta',
                        'properties' => array(
                            'foo' => array(
                                'type' => 'numeric',
                            ),
                            'bar' => array(
                                'type'       => 'object',
                                'properties' => array(
                                    'attribute' => array(
                                        'type' => 'string',
                                        'data' => array(
                                            'xmlAttribute' => true,
                                            'xmlNs'        => 'abstract'
                                        ),
                                    )
                                )
                            ),
                        ),
                    ),
                    'gamma'        => array(
                        'type'                 => 'object',
                        'data'                 => array(
                            'xmlNs' => 'abstract'
                        ),
                        'additionalProperties' => true
                    ),
                    'nonExistent'  => array(
                        'type'                 => 'object',
                        'data'                 => array(
                            'xmlNs' => 'abstract'
                        ),
                        'additionalProperties' => true
                    ),
                    'nonExistent2' => array(
                        'type'                 => 'object',
                        'additionalProperties' => true
                    ),
                ),
            )
        ));

        $value = array();
        $xml = new SimpleXMLElement('
            <xml>
                <nstest xmlns:isbn="urn:ISBN:0-395-36341-6" xmlns:abstract="urn:my.org:abstract">
                    <item>
                        <id>101</id>
                        <isbn:number>1568491379</isbn:number>
                        <abstract:meta>
                            <foo>10</foo>
                            <bar abstract:attribute="foo"></bar>
                        </abstract:meta>
                        <abstract:gamma>
                            <foo>bar</foo>
                        </abstract:gamma>
                    </item>
                    <item>
                        <id>102</id>
                        <isbn:number>1568491999</isbn:number>
                        <abstract:meta>
                            <foo>20</foo>
                            <bar abstract:attribute="bar"></bar>
                        </abstract:meta>
                        <abstract:gamma>
                            <foo>baz</foo>
                        </abstract:gamma>
                    </item>
                </nstest>
            </xml>
        ');
        $visitor->setXml($xml);
        $visitor->visit($this->command, $this->response, $param, $value);

        $this->assertEquals(array(
            'nstest' => array(
                array(
                    'id'          => '101',
                    'isbn:number' => 1568491379,
                    'meta'        => array(
                        'foo' => 10,
                        'bar' => array(
                            'attribute' => 'foo'
                        ),
                    ),
                    'gamma'       => array(
                        'foo' => 'bar'
                    )
                ),
                array(
                    'id'          => '102',
                    'isbn:number' => 1568491999,
                    'meta'        => array(
                        'foo' => 20,
                        'bar' => array(
                            'attribute' => 'bar'
                        ),
                    ),
                    'gamma'       => array(
                        'foo' => 'baz'
                    )
                ),
            )
        ), $value);
    }

    public function testCanWalkUndefinedPropertiesWithNamespace()
    {
        $visitor = new Visitor();
        $param = new Parameter(array(
            'name'     => 'nstest',
            'type'     => 'array',
            'location' => 'xml',
            'items'    => array(
                'name'                 => 'item',
                'type'                 => 'object',
                'sentAs'               => 'item',
                'additionalProperties' => array(
                    'type' => 'object',
                    'data' => array(
                        'xmlNs' => 'abstract'
                    ),
                ),
                'properties'           => array(
                    'id'          => array(
                        'type' => 'string',
                    ),
                    'isbn:number' => array(
                        'type' => 'string',
                    )
                )
            )
        ));

        $value = array();
        $xml = new SimpleXMLElement('
            <xml>
                <nstest xmlns:isbn="urn:ISBN:0-395-36341-6" xmlns:abstract="urn:my.org:abstract">
                    <item>
                        <id>101</id>
                        <isbn:number>1568491379</isbn:number>
                        <abstract:meta>
                            <foo>10</foo>
                            <bar>baz</bar>
                        </abstract:meta>
                    </item>
                    <item>
                        <id>102</id>
                        <isbn:number>1568491999</isbn:number>
                        <abstract:meta>
                            <foo>20</foo>
                            <bar>foo</bar>
                        </abstract:meta>
                    </item>
                </nstest>
            </xml>
        ');
        $visitor->setXml($xml);
        $visitor->visit($this->command, $this->response, $param, $value);

        $this->assertEquals(array(
            'nstest' => array(
                array(
                    'id'          => '101',
                    'isbn:number' => 1568491379,
                    'meta'        => array(
                        'foo' => 10,
                        'bar' => 'baz'
                    )
                ),
                array(
                    'id'          => '102',
                    'isbn:number' => 1568491999,
                    'meta'        => array(
                        'foo' => 20,
                        'bar' => 'foo'
                    )
                ),
            )
        ), $value);
    }

    public function testCanWalkSimpleArrayWithNamespace()
    {
        $visitor = new Visitor();
        $param = new Parameter(array(
            'name'     => 'nstest',
            'type'     => 'array',
            'location' => 'xml',
            'items'    => array(
                'type'   => 'string',
                'sentAs' => 'number',
                'data'   => array(
                    'xmlNs' => 'isbn'
                )
            )
        ));

        $value = array();
        $xml = new SimpleXMLElement('
            <xml>
                <nstest xmlns:isbn="urn:ISBN:0-395-36341-6">
                    <isbn:number>1568491379</isbn:number>
                    <isbn:number>1568491999</isbn:number>
                    <isbn:number>1568492999</isbn:number>
                </nstest>
            </xml>
        ');
        $visitor->setXml($xml);
        $visitor->visit($this->command, $this->response, $param, $value);

        $this->assertEquals(array(
            'nstest' => array(
                1568491379,
                1568491999,
                1568492999,
            )
        ), $value);
    }

    public function testCanWalkSimpleArrayWithNamespace2()
    {
        $visitor = new Visitor();
        $param = new Parameter(array(
            'name'     => 'nstest',
            'type'     => 'array',
            'location' => 'xml',
            'items'    => array(
                'type'   => 'string',
                'sentAs' => 'isbn:number',
            )
        ));

        $value = array();
        $xml = new SimpleXMLElement('
            <xml>
                <nstest xmlns:isbn="urn:ISBN:0-395-36341-6">
                    <isbn:number>1568491379</isbn:number>
                    <isbn:number>1568491999</isbn:number>
                    <isbn:number>1568492999</isbn:number>
                </nstest>
            </xml>
        ');
        $visitor->setXml($xml);
        $visitor->visit($this->command, $this->response, $param, $value);

        $this->assertEquals(array(
            'nstest' => array(
                1568491379,
                1568491999,
                1568492999,
            )
        ), $value);
    }

}
