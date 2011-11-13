<?php

namespace Guzzle\Tests\Service\Command;

use Guzzle\Http\Message\Response;
use Guzzle\Service\Client;
use Guzzle\Service\Command\CommandInterface;
use Guzzle\Service\Command\AbstractCommand;
use Guzzle\Service\Description\ApiCommand;
use Guzzle\Service\Plugin\MockPlugin;
use Guzzle\Tests\Service\Mock\Command\MockCommand;
use Guzzle\Tests\Service\Mock\Command\Sub\Sub;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class CommandTest extends AbstractCommandTest
{
    /**
     * @covers Guzzle\Service\Command\AbstractCommand::__construct
     * @covers Guzzle\Service\Command\AbstractCommand::init
     * @covers Guzzle\Service\Command\AbstractCommand::canBatch
     * @covers Guzzle\Service\Command\AbstractCommand::isPrepared
     * @covers Guzzle\Service\Command\AbstractCommand::isExecuted
     */
    public function testConstructorAddsDefaultParams()
    {
        $command = new MockCommand();
        $this->assertEquals('123', $command->get('test'));
        $this->assertTrue($command->canBatch());
        $this->assertFalse($command->isPrepared());
        $this->assertFalse($command->isExecuted());
    }

    /**
     * @covers Guzzle\Service\Command\AbstractCommand::getName
     */
    public function testDeterminesShortName()
    {
        $api = new ApiCommand(array(
            'name' => 'foobar'
        ));
        $command = new MockCommand(array(), $api);
        $this->assertEquals('foobar', $command->getName());

        $command = new MockCommand();
        $this->assertEquals('mock_command', $command->getName());

        $command = new Sub();
        $this->assertEquals('sub.sub', $command->getName());
    }

    /**
     * @covers Guzzle\Service\Command\AbstractCommand::getRequest
     * @expectedException RuntimeException
     */
    public function testGetRequestThrowsExceptionBeforePreparation()
    {
        $command = new MockCommand();
        $command->getRequest();
    }

    /**
     * @covers Guzzle\Service\Command\AbstractCommand::getResponse
     * @expectedException RuntimeException
     */
    public function testGetResponseThrowsExceptionBeforePreparation()
    {
        $command = new MockCommand();
        $command->getResponse();
    }

    /**
     * @covers Guzzle\Service\Command\AbstractCommand::getResult
     * @expectedException RuntimeException
     */
    public function testGetResultThrowsExceptionBeforePreparation()
    {
        $command = new MockCommand();
        $command->getResult();
    }

    /**
     * @covers Guzzle\Service\Command\AbstractCommand::setClient
     * @covers Guzzle\Service\Command\AbstractCommand::getClient
     * @covers Guzzle\Service\Command\AbstractCommand::prepare
     * @covers Guzzle\Service\Command\AbstractCommand::isPrepared
     */
    public function testSetClient()
    {
        $command = new MockCommand();
        $client = $this->getClient();

        $command->setClient($client);
        $this->assertEquals($client, $command->getClient());

        unset($client);
        unset($command);

        $command = new MockCommand();
        $client = $this->getClient();

        $command->setClient($client)->prepare();
        $this->assertEquals($client, $command->getClient());
        $this->assertTrue($command->isPrepared());
    }

    /**
     * @covers Guzzle\Service\Command\AbstractCommand::execute
     * @covers Guzzle\Service\Command\AbstractCommand::setClient
     * @covers Guzzle\Service\Command\AbstractCommand::getRequest
     * @covers Guzzle\Service\Command\AbstractCommand::getResponse
     * @covers Guzzle\Service\Command\AbstractCommand::getResult
     * @covers Guzzle\Service\Command\AbstractCommand::prepare
     * @covers Guzzle\Service\Command\AbstractCommand::process
     * @covers Guzzle\Service\Command\AbstractCommand::prepare
     *
     * @covers Guzzle\Service\Client::execute
     */
    public function testExecute()
    {
        $client = $this->getClient();

        $response = new \Guzzle\Http\Message\Response(200, array(
            'Content-Type' => 'application/xml'
        ), '<xml><data>123</data></xml>');

        // Set a mock response
        $client->getEventManager()->attach(new MockPlugin(array(
            $response
        )));

        $command = new MockCommand();

        $this->assertSame($command, $command->setClient($client));
        $this->assertSame($command, $command->execute()); // Implicitly calls prepare

        $this->assertTrue($command->isPrepared());
        $this->assertTrue($command->isExecuted());
        $this->assertSame($response, $command->getResponse());
        $this->assertInstanceOf('Guzzle\\Http\\Message\\Request', $command->getRequest());
        // Make sure that the result was automatically set to a SimpleXMLElement
        $this->assertInstanceOf('SimpleXMLElement', $command->getResult());
        $this->assertEquals('123', (string)$command->getResult()->data);
    }

    /**
     * @covers Guzzle\Service\Command\AbstractCommand::process
     */
    public function testProcessResponseIsNotXml()
    {
        $client = $this->getClient();

        $client->getEventManager()->attach(new MockPlugin(array(
            new Response(200, array(
                'Content-Type' => 'application/octect-stream'
            ), 'abc,def,ghi')
        )));

        $command = new MockCommand();
        $client->execute($command);

        // Make sure that the result was not converted to XML
        $this->assertFalse($command->getResult() instanceof \SimpleXMLElement);
    }

    /**
     * @covers Guzzle\Service\Command\AbstractCommand::execute
     * @expectedException RuntimeException
     */
    public function testExecuteThrowsExceptionWhenNoClientIsSet()
    {
        $command = new MockCommand();
        $command->execute();
    }

    /**
     * @covers Guzzle\Service\Command\AbstractCommand::prepare
     * @expectedException RuntimeException
     */
    public function testPrepareThrowsExceptionWhenNoClientIsSet()
    {
        $command = new MockCommand();
        $command->prepare();
    }

    /**
     * @covers Guzzle\Service\Command\AbstractCommand::prepare
     * @covers Guzzle\Service\Command\AbstractCommand::getRequestHeaders
     */
    public function testCommandsAllowsCustomRequestHeaders()
    {
        $command = new MockCommand();
        $command->getRequestHeaders()->set('test', '123');
        $this->assertInstanceOf('Guzzle\Common\Collection', $command->getRequestHeaders());
        $this->assertEquals('123', $command->getRequestHeaders()->get('test'));

        $command->setClient($this->getClient())->prepare();
        $this->assertEquals('123', $command->getRequest()->getHeaders()->get('test'));
    }

    /**
     * @covers Guzzle\Service\Command\AbstractCommand
     */
    public function testCommandsUsesApiCommand()
    {
        $api = new ApiCommand(array(
            'name' => 'foobar',
            'method' => 'POST',
            'min_args' => 1,
            'can_batch' => true,
            'class' => 'Guzzle\\Tests\\Service\\Mock\\Command\\MockCommand',
            'args' => array(
                'test' => array(
                    'default' => '123',
                    'type' => 'string'
                )
        )));

        $command = new MockCommand(array(), $api);
        $this->assertSame($api, $command->getApiCommand());
        $command->setClient($this->getClient())->prepare();
        $this->assertEquals('123', $command->get('test'));
        $this->assertSame($api, $command->getApiCommand($api));
    }
}