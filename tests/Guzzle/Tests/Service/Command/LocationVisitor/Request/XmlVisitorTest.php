<?php

namespace Guzzle\Tests\Service\Command\LocationVisitor\Request;

use Guzzle\Service\Command\LocationVisitor\Request\XmlVisitor;
use Guzzle\Service\Client;
use Guzzle\Service\Description\Parameter;
use Guzzle\Service\Description\Operation;
use Guzzle\Http\Message\EntityEnclosingRequest;

/**
 * @covers Guzzle\Service\Command\LocationVisitor\Request\XmlVisitor
 */
class XmlVisitorTest extends AbstractVisitorTestCase
{
    public function xmlProvider()
    {
        return array(
            array(
                array(
                    'data' => array('ns' => 'http://foo.com', 'root' => 'test'),
                    'parameters' => array(
                        'Foo' => array('location' => 'xml', 'type' => 'string'),
                        'Baz' => array('location' => 'xml', 'type' => 'string')
                    )
                ),
                array('Foo' => 'test', 'Baz' => 'bar'),
                '<test xmlns="http://foo.com"><Foo>test</Foo><Baz>bar</Baz></test>'
            ),
            // Ensure that the content-type is not added
            array(array('parameters' => array('Foo' => array('location' => 'xml', 'type' => 'string'))), array(), ''),
            // Test with adding attributes and no namespace
            array(
                array(
                    'data' => array('root' => 'test'),
                    'parameters' => array(
                        'Foo' => array('location' => 'xml', 'type' => 'string', 'data' => array('attribute' => true))
                    )
                ),
                array('Foo' => 'test', 'Baz' => 'bar'),
                '<test Foo="test"/>'
            ),
            // Test adding with an array
            array(
                array(
                    'parameters' => array(
                        'Foo' => array('location' => 'xml', 'type' => 'string'),
                        'Baz' => array(
                            'type'     => 'array',
                            'location' => 'xml',
                            'items' => array(
                                'type'   => 'numeric',
                                'rename' => 'Bar'
                            )
                        )
                    )
                ),
                array('Foo' => 'test', 'Baz' => array(1, 2)),
                '<Request><Foo>test</Foo><Baz><Bar>1</Bar><Bar>2</Bar></Baz></Request>'
            ),
            // Test adding an object
            array(
                array(
                    'parameters' => array(
                        'Foo' => array('location' => 'xml', 'type' => 'string'),
                        'Baz' => array(
                            'type'     => 'object',
                            'location' => 'xml',
                            'properties' => array(
                                'Bar' => array('type' => 'string'),
                                'Bam' => array()
                            )
                        )
                    )
                ),
                array('Foo' => 'test', 'Baz' => array('Bar' => 'abc', 'Bam' => 'foo')),
                '<Request><Foo>test</Foo><Baz><Bar>abc</Bar><Bam>foo</Bam></Baz></Request>'
            ),
            // Add an array that contains an object
            array(
                array(
                    'parameters' => array(
                        'Baz' => array(
                            'type'     => 'array',
                            'location' => 'xml',
                            'items' => array(
                                'type'       => 'object',
                                'rename'     => 'Bar',
                                'properties' => array('A' => array(), 'B' => array())
                            )
                        )
                    )
                ),
                array('Baz' => array(
                    array('A' => '1', 'B' => '2'),
                    array('A' => '3', 'B' => '4')
                )),
                '<Request><Baz><Bar><A>1</A><B>2</B></Bar><Bar><A>3</A><B>4</B></Bar></Baz></Request>'
            ),
            // Add an object of attributes
            array(
                array(
                    'parameters' => array(
                        'Foo' => array('location' => 'xml', 'type' => 'string'),
                        'Baz' => array(
                            'type'     => 'object',
                            'location' => 'xml',
                            'properties' => array(
                                'Bar' => array('type' => 'string', 'data' => array('attribute' => true)),
                                'Bam' => array()
                            )
                        )
                    )
                ),
                array('Foo' => 'test', 'Baz' => array('Bar' => 'abc', 'Bam' => 'foo')),
                '<Request><Foo>test</Foo><Baz Bar="abc"><Bam>foo</Bam></Baz></Request>'
            ),
            // Add values with custom namespaces
            array(
                array(
                    'parameters' => array(
                        'Foo' => array('location' => 'xml', 'type' => 'string', 'data' => array('namespace' => 'foo'))
                    )
                ),
                array('Foo' => 'test'),
                '<Request><Foo xmlns="foo">test</Foo></Request>'
            ),
        );
    }

    /**
     * @dataProvider xmlProvider
     */
    public function testSerializesXml(array $operation, array $input, $xml)
    {
        $operation = new Operation($operation);
        $command = $this->getMockBuilder('Guzzle\Service\Command\OperationCommand')
            ->setConstructorArgs(array($input, $operation))
            ->getMockForAbstractClass();
        $command->setClient(new Client());
        $request = $command->prepare();
        if (!empty($input)) {
            $this->assertEquals('application/xml', (string) $request->getHeader('Content-Type'));
        } else {
            $this->assertNull($request->getHeader('Content-Type'));
        }
        $body = str_replace(array("\n", "<?xml version=\"1.0\"?>"), '', (string) $request->getBody());
        $this->assertEquals($xml, $body);
    }

    public function testAddsContentTypeAndTopLevelValues()
    {
        $operation = new Operation(array(
            'data' => array(
                'ns'   => 'http://foo.com',
                'root' => 'test'
            ),
            'parameters' => array(
                'Foo' => array('location' => 'xml', 'type' => 'string'),
                'Baz' => array('location' => 'xml', 'type' => 'string')
            )
        ));

        $command = $this->getMockBuilder('Guzzle\Service\Command\OperationCommand')
            ->setConstructorArgs(array(array(
                'Foo' => 'test',
                'Baz' => 'bar'
            ), $operation))
            ->getMockForAbstractClass();

        $command->setClient(new Client());
        $request = $command->prepare();
        $this->assertEquals('application/xml', (string) $request->getHeader('Content-Type'));
        $this->assertEquals(
            '<?xml version="1.0"?>' . "\n"
                . '<test xmlns="http://foo.com"><Foo>test</Foo><Baz>bar</Baz></test>' . "\n",
            (string) $request->getBody()
        );
    }

    /**
     * @expectedException Guzzle\Common\Exception\RuntimeException
     */
    public function testEnsuresParameterHasParent()
    {
        $param = new Parameter(array('Foo' => array('location' => 'xml', 'type' => 'string')));
        $value = array();
        $request = new EntityEnclosingRequest('POST', 'http://foo.com');
        $this->assertTrue($param->process($value));
        $visitor = new XmlVisitor();
        $visitor->visit($this->command, $request, $param, $value);
    }

    public function testCanChangeContentType()
    {
        $visitor = new XmlVisitor();
        $visitor->setContentTypeHeader('application/foo');
        $this->assertEquals('application/foo', $this->readAttribute($visitor, 'contentType'));
    }

    public function testCanAddArrayOfSimpleTypes()
    {
        $request = new EntityEnclosingRequest('POST', 'http://foo.com');
        $visitor = new XmlVisitor();
        $param = new Parameter(array(
            'type'     => 'object',
            'location' => 'xml',
            'name'     => 'Out',
            'properties' => array(
                'Nodes' => array(
                    'required' => true,
                    'type'     => 'array',
                    'min'      => 1,
                    'items'    => array('type' => 'string', 'rename' => 'Node')
                )
            )
        ));

        $param->setParent(new Operation(array('data' => array('ns' => 'https://foo/', 'root' => 'Test'))));

        $value = array('Nodes' => array('foo', 'baz'));
        $this->assertTrue($param->process($value));
        $visitor->visit($this->command, $request, $param, $value);
        $visitor->after($this->command, $request);

        $this->assertEquals(
            "<?xml version=\"1.0\"?>\n"
            . "<Test xmlns=\"https://foo/\"><Out><Nodes><Node>foo</Node><Node>baz</Node></Nodes></Out></Test>\n",
            (string) $request->getBody()
        );
    }
}
