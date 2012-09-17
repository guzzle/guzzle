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
use Guzzle\Service\Command\LocationVisitor\Response\BodyVisitor;

/**
 * @covers Guzzle\Service\Command\OperationResponseParser
 */
class OperationResponseParserTest extends \Guzzle\Tests\GuzzleTestCase
{
    public function testHasVisitors()
    {
        $p = new OperationResponseParser();
        $visitor = new BodyVisitor();
        $p->addVisitor('foo', $visitor);
        $this->assertSame(array('foo' => $visitor), $this->readAttribute($p, 'visitors'));
    }

    public function testUsesParentParser()
    {
        $p = new OperationResponseParser();
        $operation = new Operation();
        $operation->setServiceDescription(new ServiceDescription());
        $op = new OperationCommand(array(), $operation);
        $op->setResponseParser($p)->setClient(new Client());
        $op->prepare()->setResponse(new Response(200, array('Content-Type' => 'application/xml'), '<F><B>C</B></F>'), true);
        $this->assertInstanceOf('SimpleXMLElement', $op->execute());
    }

    public function testConvertsSimpleXMLElementToArrayWhenModelIsFound()
    {
        $parser = new OperationResponseParser();
        $op = new OperationCommand(array(), $this->getDescription()->getOperation('test'));
        $op->setResponseParser($parser)->setClient(new Client());
        $op->prepare()->setResponse(new Response(200, array('Content-Type' => 'application/xml'), '<F><B>C</B></F>'), true);
        $this->assertInternalType('array', $op->execute());
    }

    public function testVisitsLocations()
    {
        $parser = new OperationResponseParser();
        $parser->addVisitor('statusCode', new StatusCodeVisitor());
        $parser->addVisitor('reasonPhrase', new ReasonPhraseVisitor());
        $op = new OperationCommand(array(), $this->getDescription()->getOperation('test'));
        $op->setResponseParser($parser)->setClient(new Client());
        $op->prepare()->setResponse(new Response(201), true);
        $result = $op->execute();
        $this->assertEquals(201, $result['code']);
        $this->assertEquals('Created', $result['phrase']);
    }

    public function testSkipsUnkownModels()
    {
        $parser = new OperationResponseParser();
        $operation = $this->getDescription()->getOperation('test');
        $operation->setResponseClass('array');
        $op = new OperationCommand(array(), $operation);
        $op->setResponseParser($parser)->setClient(new Client());
        $op->prepare()->setResponse(new Response(201), true);
        $this->assertInstanceOf('Guzzle\Http\Message\Response', $op->execute());
    }

    protected function getDescription()
    {
        return new ServiceDescription(array(
            'operations' => array('test' => array('responseClass' => 'Foo')),
            'models' => array(
                'Foo' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'baz'    => array('type' => 'string'),
                        'code'   => array('location' => 'statusCode'),
                        'phrase' => array('location' => 'reasonPhrase'),
                    )
                )
            )
        ));
    }
}
