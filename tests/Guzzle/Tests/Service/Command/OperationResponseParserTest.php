<?php

namespace Guzzle\Tests\Service\Command;

use Guzzle\Http\Message\Response;
use Guzzle\Service\Client;
use Guzzle\Service\Command\OperationResponseParser;
use Guzzle\Service\Command\OperationCommand;
use Guzzle\Service\Description\Operation;
use Guzzle\Service\Description\ServiceDescription;
use Guzzle\Service\Command\LocationVisitor\Response\StatusCodeVisitor;
use Guzzle\Service\Command\LocationVisitor\Response\ReasonPhraseVisitor;
use Guzzle\Service\Command\LocationVisitor\Response\JsonVisitor;
use Guzzle\Service\Command\LocationVisitor\Response\BodyVisitor;
use Guzzle\Service\Command\LocationVisitor\VisitorFlyweight;

/**
 * @covers Guzzle\Service\Command\OperationResponseParser
 * @covers Guzzle\Service\Command\CreateResponseClassEvent
 */
class OperationResponseParserTest extends \Guzzle\Tests\GuzzleTestCase
{
    public function testHasVisitors()
    {
        $p = new OperationResponseParser(new VisitorFlyweight(array()));
        $visitor = new BodyVisitor();
        $p->addVisitor('foo', $visitor);
        $this->assertSame($visitor, $this->readAttribute($p, 'factory')->getResponseVisitor('foo'));
    }

    public function testUsesParentParser()
    {
        $p = new OperationResponseParser(new VisitorFlyweight());
        $operation = new Operation();
        $operation->setServiceDescription(new ServiceDescription());
        $op = new OperationCommand(array(), $operation);
        $op->setResponseParser($p)->setClient(new Client());
        $op->prepare()->setResponse(new Response(200, array('Content-Type' => 'application/xml'), '<F><B>C</B></F>'), true);
        $this->assertInstanceOf('SimpleXMLElement', $op->execute());
    }

    public function testVisitsLocations()
    {
        $parser = new OperationResponseParser(new VisitorFlyweight(array()));
        $parser->addVisitor('statusCode', new StatusCodeVisitor());
        $parser->addVisitor('reasonPhrase', new ReasonPhraseVisitor());
        $parser->addVisitor('json', new JsonVisitor());
        $op = new OperationCommand(array(), $this->getDescription()->getOperation('test'));
        $op->setResponseParser($parser)->setClient(new Client());
        $op->prepare()->setResponse(new Response(201), true);
        $result = $op->execute();
        $this->assertEquals(201, $result['code']);
        $this->assertEquals('Created', $result['phrase']);
    }

    public function testVisitsLocationsForJsonResponse()
    {
        $parser = OperationResponseParser::getInstance();
        $operation = $this->getDescription()->getOperation('test');
        $op = new OperationCommand(array(), $operation);
        $op->setResponseParser($parser)->setClient(new Client());
        $op->prepare()->setResponse(new Response(200, array(
            'Content-Type' => 'application/json'
        ), '{"baz":"bar","enigma":"123"}'), true);
        $result = $op->execute();
        $this->assertEquals(array(
            'baz'    => 'bar',
            'enigma' => '123',
            'code'   => 200,
            'phrase' => 'OK'
        ), $result->toArray());
    }

    public function testSkipsUnkownModels()
    {
        $parser = OperationResponseParser::getInstance();
        $operation = $this->getDescription()->getOperation('test');
        $operation->setResponseClass('Baz')->setResponseType('model');
        $op = new OperationCommand(array(), $operation);
        $op->setResponseParser($parser)->setClient(new Client());
        $op->prepare()->setResponse(new Response(201), true);
        $this->assertInstanceOf('Guzzle\Http\Message\Response', $op->execute());
    }

    public function testAllowsModelProcessingToBeDisabled()
    {
        $parser = OperationResponseParser::getInstance();
        $operation = $this->getDescription()->getOperation('test');
        $op = new OperationCommand(array('command.response_processing' => 'native'), $operation);
        $op->setResponseParser($parser)->setClient(new Client());
        $op->prepare()->setResponse(new Response(200, array(
            'Content-Type' => 'application/json'
        ), '{"baz":"bar","enigma":"123"}'), true);
        $result = $op->execute();
        $this->assertInstanceOf('Guzzle\Service\Resource\Model', $result);
        $this->assertEquals(array(
            'baz'    => 'bar',
            'enigma' => '123'
        ), $result->toArray());
    }

    public function testDoesNotParseXmlWhenNotUsingXmlVisitor()
    {
        $parser = OperationResponseParser::getInstance();
        $description = ServiceDescription::factory(array(
            'operations' => array('test' => array('responseClass' => 'Foo')),
            'models'     => array(
                'Foo' => array(
                    'type'       => 'object',
                    'properties' => array('baz' => array('location' => 'body'))
                )
            )
        ));
        $operation = $description->getOperation('test');
        $op = new OperationCommand(array(), $operation);
        $op->setResponseParser($parser)->setClient(new Client());
        $brokenXml = '<broken><><><<xml>>>>>';
        $op->prepare()->setResponse(new Response(200, array(
            'Content-Type' => 'application/xml'
        ), $brokenXml), true);
        $result = $op->execute();
        $this->assertEquals(array('baz'), $result->getKeys());
        $this->assertEquals($brokenXml, (string)$result['baz']);
    }

    public function testVisitsAdditionalPropertiesWithJson()
    {
        $parser = OperationResponseParser::getInstance();
        $description = ServiceDescription::factory(array(
            'operations' => array('test' => array('responseClass' => 'Foo')),
            'models'     => array(
                'Foo' => array(
                    'type'                 => 'object',
                    'properties'           => array(
                        'code' => array(
                            'location' => 'statusCode'
                        )
                    ),
                    'additionalProperties' => array(
                        'location'   => 'json',
                        'type'       => 'object',
                        'properties' => array(
                            'a' => array(
                                'type'    => 'string',
                                'filters' => 'strtoupper'
                            )
                        )
                    )
                )
            )
        ));

        $operation = $description->getOperation('test');
        $op = new OperationCommand(array(), $operation);
        $op->setResponseParser($parser)->setClient(new Client());
        $json = '[{"a":"test"},{"a":"baz"}]';
        $op->prepare()->setResponse(new Response(200, array('Content-Type' => 'application/json'), $json), true);
        $result = $op->execute()->toArray();
        $this->assertEquals(array(
            'code' => 200,
            array('a' => 'TEST'),
            array('a' => 'BAZ')
        ), $result);
    }

    public function testVisitsAdditionalPropertiesWithXML()
    {
        $parser = OperationResponseParser::getInstance();
        $description = ServiceDescription::factory(array(
            'operations' => array('test' => array('responseClass' => 'Foo')),
            'models'     => array(
                'Foo' => array(
                    'type'                 => 'object',
                    'properties'           => array(
                        'code' => array(
                            'location' => 'statusCode'
                        )
                    ),
                    'additionalProperties' => array(
                        'location'             => 'xml',
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'properties'           => array(
                            'id' => array(
                                'type' => 'integer'
                            )
                        )
                    )
                )
            )
        ));
        $operation = $description->getOperation('test');
        $op = new OperationCommand(array(), $operation);
        $op->setResponseParser($parser)->setClient(new Client());
        $xml = '
            <xml>
                <nestedNoAdditional>
                    <id>15</id>
                    <unknown>discard me</unknown>
                </nestedNoAdditional>
                <nestedNoAdditional2>
                    <id>25</id>
                    <unknown>discard me</unknown>
                </nestedNoAdditional2>
            </xml>
        ';
        $op->prepare()->setResponse(new Response(200, array('Content-Type' => 'application/xml'), $xml), true);
        $result = $op->execute()->toArray();
        $this->assertEquals(array(
            'code'                => 200,
            'nestedNoAdditional'  => array(
                'id' => '15'
            ),
            'nestedNoAdditional2' => array(
                'id' => '25'
            ),
        ), $result);
    }

    public function testVisitsNestedAdditionalPropertiesWithJson()
    {
        $parser = OperationResponseParser::getInstance();
        $description = ServiceDescription::factory(array(
            'operations' => array('test' => array('responseClass' => 'Foo')),
            'models'     => array(
                'Foo' => array(
                    'type'                 => 'object',
                    'properties'           => array(
                        'data' => array(
                            'location'             => 'json',
                            'type'                 => 'object',
                            'additionalProperties' => true,
                            'properties'           => array(
                                'a' => array(
                                    'type'    => 'string',
                                    'filters' => 'strtoupper'
                                )
                            )
                        ),
                        'code' => array(
                            'location' => 'statusCode'
                        )
                    ),
                    'additionalProperties' => false
                )
            )
        ));

        $operation = $description->getOperation('test');
        $op = new OperationCommand(array(), $operation);
        $op->setResponseParser($parser)->setClient(new Client());
        $json = '{
            "data" : {
                "a":"test",
                "weight": 2.15,
                "height": 3.30
            }
        }';
        $op->prepare()->setResponse(new Response(200, array('Content-Type' => 'application/json'), $json), true);
        $result = $op->execute()->toArray();
        $this->assertEquals(array(
            'code' => 200,
            'data' => array(
                'a' => 'TEST',
                'weight' => 2.15,
                'height' => 3.30
            )
        ), $result);
    }

    /**
     * @group issue-399
     * @link  https://github.com/guzzle/guzzle/issues/399
     */
    public function testAdditionalPropertiesDisabledDiscardsData()
    {
        $parser = OperationResponseParser::getInstance();
        $description = ServiceDescription::factory(array(
            'operations' => array('test' => array('responseClass' => 'Foo')),
            'models'     => array(
                'Foo' => array(
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'properties'           => array(
                        'name'   => array(
                            'location' => 'json',
                            'type'     => 'string',
                        ),
                        'nested' => array(
                            'location'             => 'json',
                            'type'                 => 'object',
                            'additionalProperties' => false,
                            'properties'           => array(
                                'width' => array(
                                    'type' => 'integer'
                                )
                            ),
                        ),
                        'code'   => array('location' => 'statusCode')
                    ),

                )
            )
        ));

        $operation = $description->getOperation('test');
        $op = new OperationCommand(array(), $operation);
        $op->setResponseParser($parser)->setClient(new Client());
        $json = '{"name":"test", "volume":2.0, "nested":{"width":10,"bogus":1}}';
        $op->prepare()->setResponse(new Response(200, array('Content-Type' => 'application/json'), $json), true);
        $result = $op->execute()->toArray();
        $this->assertEquals(array(
            'name'   => 'test',
            'nested' => array(
                'width' => 10,
            ),
            'code'   => 200
        ), $result);
    }

    public function testCreatesCustomResponseClassInterface()
    {
        $parser = OperationResponseParser::getInstance();
        $description = ServiceDescription::factory(array(
            'operations' => array('test' => array('responseClass' => 'Guzzle\Tests\Mock\CustomResponseModel'))
        ));
        $operation = $description->getOperation('test');
        $op = new OperationCommand(array(), $operation);
        $op->setResponseParser($parser)->setClient(new Client());
        $op->prepare()->setResponse(new Response(200, array('Content-Type' => 'application/json'), 'hi!'), true);
        $result = $op->execute();
        $this->assertInstanceOf('Guzzle\Tests\Mock\CustomResponseModel', $result);
        $this->assertSame($op, $result->command);
    }

    /**
     * @expectedException \Guzzle\Service\Exception\ResponseClassException
     * @expectedExceptionMessage must exist
     */
    public function testEnsuresResponseClassExists()
    {
        $parser = OperationResponseParser::getInstance();
        $description = ServiceDescription::factory(array(
            'operations' => array('test' => array('responseClass' => 'Foo\Baz\Bar'))
        ));
        $operation = $description->getOperation('test');
        $op = new OperationCommand(array(), $operation);
        $op->setResponseParser($parser)->setClient(new Client());
        $op->prepare()->setResponse(new Response(200, array('Content-Type' => 'application/json'), 'hi!'), true);
        $op->execute();
    }

    /**
     * @expectedException \Guzzle\Service\Exception\ResponseClassException
     * @expectedExceptionMessage and implement
     */
    public function testEnsuresResponseClassImplementsResponseClassInterface()
    {
        $parser = OperationResponseParser::getInstance();
        $description = ServiceDescription::factory(array(
            'operations' => array('test' => array('responseClass' => __CLASS__))
        ));
        $operation = $description->getOperation('test');
        $op = new OperationCommand(array(), $operation);
        $op->setResponseParser($parser)->setClient(new Client());
        $op->prepare()->setResponse(new Response(200, array('Content-Type' => 'application/json'), 'hi!'), true);
        $op->execute();
    }

    protected function getDescription()
    {
        return ServiceDescription::factory(array(
            'operations' => array('test' => array('responseClass' => 'Foo')),
            'models'     => array(
                'Foo' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'baz'    => array('type' => 'string', 'location' => 'json'),
                        'code'   => array('location' => 'statusCode'),
                        'phrase' => array('location' => 'reasonPhrase'),
                    )
                )
            )
        ));
    }

    public function testCanAddListenerToParseDomainObjects()
    {
        $client = new Client();
        $client->setDescription(ServiceDescription::factory(array(
            'operations' => array('test' => array('responseClass' => 'FooBazBar'))
        )));
        $foo = new \stdClass();
        $client->getEventDispatcher()->addListener('command.parse_response', function ($e) use ($foo) {
            $e['result'] = $foo;
        });
        $command = $client->getCommand('test');
        $command->prepare()->setResponse(new Response(200), true);
        $result = $command->execute();
        $this->assertSame($result, $foo);
    }

    public function testMultipleLocations()
    {
        $parser = OperationResponseParser::getInstance();
        $description = ServiceDescription::factory(array(
            'operations' => array('test' => array('responseClass' => 'Foo')),
            'models'     => array(
                'Foo' => array(
                    'type'                 => 'object',
                    'additionalProperties' => array(
                        'location' => 'header',
                        'type'     => 'string'
                    ),
                    'properties'           => array(
                        'name' => array(
                            'location'             => 'json',
                            'type'                 => 'string',
                            'additionalProperties' => false
                        ),
                        'code' => array(
                            'location' => 'statusCode'
                        )
                    ),
                )
            )
        ));

        $operation = $description->getOperation('test');
        $op = new OperationCommand(array(), $operation);
        $op->setResponseParser($parser)->setClient(new Client());
        $json = '{"name":"test", "volume":2.0, "nested":{"width":10,"bogus":1}}';
        $response = new Response(
            200,
            array(
                'Content-Type'   => 'application/json',
                'Content-Length' => 62
            ),
            $json
        );
        $op->prepare()->setResponse($response, true);
        $result = $op->execute()->toArray();
        $this->assertEquals(array(
            'name'           => 'test',
            'Content-Type'   => 'application/json',
            'Content-Length' => 62,
            'code'           => 200,
        ), $result);
    }

    /**
     * @group issue-317
     * @link  https://github.com/guzzle/guzzle/issues/317
     */
    public function testTopLevelLocationWithArray()
    {
        $parser = OperationResponseParser::getInstance();
        $description = ServiceDescription::factory(array(
            'operations' => array('test' => array('responseClass' => 'Foo')),
            'models'     => array(
                'Foo' => array(
                    'type'                 => 'array',
                    'location'             => 'json',
                    'additionalProperties' => false,
                    'items'                => array(
                        'type' => 'object'
                    )
                )
            )
        ));
        $operation = $description->getOperation('test');
        $op = new OperationCommand(array(), $operation);
        $op->setResponseParser($parser)->setClient(new Client());
        $op->prepare()->setResponse(new Response(200, array(
            'Content-Type' => 'text/plain'
        ), '[{"baz":"bar","enigma":"123"},{"baz":"foo","enigma":"321"}]'), true);
        $result = $op->execute();
        $this->assertEquals(array(
            array(
                'baz'    => 'bar',
                'enigma' => '123',
            ),
            array(
                'baz'    => 'foo',
                'enigma' => '321',
            )
        ), $result->toArray());
    }

    /**
     * @group issue-317
     * @link  https://github.com/guzzle/guzzle/issues/317
     */
    public function testLocationSpecifiedInItemsWithArray()
    {
        $parser = OperationResponseParser::getInstance();
        $description = ServiceDescription::factory(array(
            'operations' => array('test' => array('responseClass' => 'Foo')),
            'models'     => array(
                'Foo' => array(
                    'type'                 => 'array',
                    'additionalProperties' => false,
                    'items'                => array(
                        'location' => 'json',
                        'type'     => 'object'
                    )
                )
            )
        ));
        $operation = $description->getOperation('test');
        $op = new OperationCommand(array(), $operation);
        $op->setResponseParser($parser)->setClient(new Client());
        $op->prepare()->setResponse(new Response(200, array(
            'Content-Type' => 'text/plain'
        ), '[{"baz":"bar","enigma":"123"},{"baz":"foo","enigma":"321"}]'), true);
        $result = $op->execute();
        $this->assertEquals(array(
            array(
                'baz'    => 'bar',
                'enigma' => '123',
            ),
            array(
                'baz'    => 'foo',
                'enigma' => '321',
            )
        ), $result->toArray());
    }

    /**
     * @group issue-317
     * @link  https://github.com/guzzle/guzzle/issues/317
     */
    public function testJsonResponseWithTopLevelArray()
    {
        $parser = OperationResponseParser::getInstance();
        $description = ServiceDescription::factory(array(
            'operations' => array('test' => array('responseClass' => 'Foo')),
            'models'     => array(
                'Foo' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'body'   => array(
                            'type'                 => 'array',
                            'location'             => 'json',
                            'sentAs'               => '',       // the incoming array is on the top-level (no property)
                            'additionalProperties' => false,
                            'items'                => array(
                                'type' => 'object'
                            )
                        ),
                        'code'   => array('location' => 'statusCode'),
                        'phrase' => array('location' => 'reasonPhrase'),
                    )
                )
            )
        ));
        $operation = $description->getOperation('test');
        $op = new OperationCommand(array(), $operation);
        $op->setResponseParser($parser)->setClient(new Client());
        $op->prepare()->setResponse(new Response(200, array(
            'Content-Type' => 'application/json'
        ), '[{"baz":"bar","enigma":"123"},{"baz":"foo","enigma":"321"}]'), true);
        $result = $op->execute();
        $this->assertEquals(array(
            'body'   => array(
                array(
                    'baz'    => 'bar',
                    'enigma' => '123',
                ),
                array(
                    'baz'    => 'foo',
                    'enigma' => '321',
                )
            ),
            'code'   => 200,
            'phrase' => 'OK'
        ), $result->toArray());
    }

    /**
     * @group issue-317
     * @link  https://github.com/guzzle/guzzle/issues/317
     */
    public function testJsonResponseWithTopLevelArrayAndFlattening()
    {
        $parser = OperationResponseParser::getInstance();
        $description = ServiceDescription::factory(array(
            'operations' => array('test' => array('responseClass' => 'Foo')),
            'models'     => array(
                'Foo' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'body'   => array(
                            'type'                 => 'array',
                            'location'             => 'json',
                            'name'                 => '',       // no name provided, merge it with main result array
                            'sentAs'               => '',       // the incoming array is on the top-level
                            'additionalProperties' => false,
                            'items'                => array(
                                'type' => 'object'
                            )
                        ),
                        'code'   => array('location' => 'statusCode'),
                        'phrase' => array('location' => 'reasonPhrase'),
                    )
                )
            )
        ));
        $operation = $description->getOperation('test');
        $op = new OperationCommand(array(), $operation);
        $op->setResponseParser($parser)->setClient(new Client());
        $op->prepare()->setResponse(new Response(200, array(
            'Content-Type' => 'application/json'
        ), '[{"baz":"bar","enigma":"123"},{"baz":"foo","enigma":"321"}]'), true);
        $result = $op->execute();
        $this->assertEquals(array(
            array(
                'baz'    => 'bar',
                'enigma' => '123',
            ),
            array(
                'baz'    => 'foo',
                'enigma' => '321',
            ),
            'code'   => 200,
            'phrase' => 'OK'
        ), $result->toArray());
    }

    /**
     * @group issue-317
     * @link  https://github.com/guzzle/guzzle/issues/317
     */
    public function testJsonResponseWithTopLevelArrayAndFlattening2()
    {
        $parser = OperationResponseParser::getInstance();
        $description = ServiceDescription::factory(array(
            'operations' => array('test' => array('responseClass' => 'Foo')),
            'models'     => array(
                'Foo' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'code'   => array('location' => 'statusCode'),
                        'phrase' => array('location' => 'reasonPhrase'),
                        'body'   => array(
                            'type'                 => 'array',
                            'location'             => 'json',
                            'name'                 => '',       // no name provided, merge it with main result array
                            'sentAs'               => '',       // the incoming array is on the top-level
                            'additionalProperties' => false,
                            'items'                => array(
                                'type' => 'object'
                            )
                        ),
                    )
                )
            )
        ));
        $operation = $description->getOperation('test');
        $op = new OperationCommand(array(), $operation);
        $op->setResponseParser($parser)->setClient(new Client());
        $op->prepare()->setResponse(new Response(200, array(
            'Content-Type' => 'application/json'
        ), '[{"baz":"bar","enigma":"123"},{"baz":"foo","enigma":"321"}]'), true);
        $result = $op->execute();
        $this->assertEquals(array(
            array(
                'baz'    => 'bar',
                'enigma' => '123',
            ),
            array(
                'baz'    => 'foo',
                'enigma' => '321',
            ),
            'code'   => 200,
            'phrase' => 'OK'
        ), $result->toArray());
    }

    public function testJsonResponseAndEmptyObjectNameFlattensResult()
    {
        $parser = OperationResponseParser::getInstance();
        $description = ServiceDescription::factory(array(
            'operations' => array('test' => array('responseClass' => 'Foo')),
            'models'     => array(
                'Foo' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'results' => array(
                            'type'                 => 'object',
                            'location'             => 'json',
                            'sentAs'               => 'results', // it's coming as "results" inside JSON
                            'name'                 => '',        // no name provided, merge it with main result array
                            'additionalProperties' => false,
                            'properties'           => array(
                                'baz'    => array(
                                    'type' => 'string'
                                ),
                                'knight' => array(
                                    'type' => 'string'
                                ),
                            )
                        ),
                        'code'    => array('location' => 'statusCode'),
                        'phrase'  => array('location' => 'reasonPhrase'),
                    )
                )
            )
        ));
        $operation = $description->getOperation('test');
        $op = new OperationCommand(array(), $operation);
        $op->setResponseParser($parser)->setClient(new Client());
        $json = '
        {
            "results": {
                "baz":"bar",
                "enigma":"123",
                "knight":"rider"
            }
        }';
        $op->prepare()->setResponse(new Response(200, array('Content-Type' => 'application/json'), $json), true);
        $result = $op->execute();
        $this->assertEquals(array(
            'baz'    => 'bar',
            'knight' => 'rider',
            'code'   => 200,
            'phrase' => 'OK'
        ), $result->toArray());
    }
}
