<?php

namespace Guzzle\Tests\Service\Command;

use Guzzle\Http\Message\Response;
use Guzzle\Service\Client;
use Guzzle\Service\Command\CommandInterface;
use Guzzle\Service\Command\AbstractCommand;
use Guzzle\Service\Description\ApiCommand;
use Guzzle\Service\Description\ApiParam;
use Guzzle\Service\Inspector;
use Guzzle\Http\Plugin\MockPlugin;
use Guzzle\Tests\Service\Mock\Command\MockCommand;
use Guzzle\Tests\Service\Mock\Command\Sub\Sub;

class CommandTest extends AbstractCommandTest
{
    /**
     * @covers Guzzle\Service\Command\AbstractCommand::__construct
     * @covers Guzzle\Service\Command\AbstractCommand::init
     * @covers Guzzle\Service\Command\AbstractCommand::isPrepared
     * @covers Guzzle\Service\Command\AbstractCommand::isExecuted
     */
    public function testConstructorAddsDefaultParams()
    {
        $command = new MockCommand();
        $this->assertEquals('123', $command->get('test'));
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
     * @covers Guzzle\Service\Client::execute
     */
    public function testExecute()
    {
        $client = $this->getClient();

        $response = new \Guzzle\Http\Message\Response(200, array(
            'Content-Type' => 'application/xml'
        ), '<xml><data>123</data></xml>');

        // Set a mock response
        $client->getEventDispatcher()->addSubscriber(new MockPlugin(array(
            $response
        )));

        $command = new MockCommand();

        $this->assertSame($command, $command->setClient($client));

        // Returns the result of the command
        $this->assertInstanceOf('SimpleXMLElement', $command->execute());

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
    public function testConvertsJsonResponsesToArray()
    {
        $client = $this->getClient();
        $client->getEventDispatcher()->addSubscriber(new MockPlugin(array(
            new \Guzzle\Http\Message\Response(200, array(
                'Content-Type' => 'application/json'
                ), '{ "key": "Hi!" }'
            )
        )));
        $command = new MockCommand();
        $command->setClient($client);
        $command->execute();
        $this->assertEquals(array(
            'key' => 'Hi!'
        ), $command->getResult());
    }

    /**
     * @covers Guzzle\Service\Command\AbstractCommand::process
     * @expectedException Guzzle\Service\Exception\JsonException
     */
    public function testConvertsInvalidJsonResponsesToArray()
    {
        $client = $this->getClient();
        $client->getEventDispatcher()->addSubscriber(new MockPlugin(array(
            new \Guzzle\Http\Message\Response(200, array(
                'Content-Type' => 'application/json'
                ), '{ "key": "Hi!" }invalid'
            )
        )));
        $command = new MockCommand();
        $command->setClient($client);
        $command->execute();
    }

    /**
     * @covers Guzzle\Service\Command\AbstractCommand::process
     */
    public function testProcessResponseIsNotXml()
    {
        $client = $this->getClient();

        $client->getEventDispatcher()->addSubscriber(new MockPlugin(array(
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
        $this->assertEquals('123', (string) $command->getRequest()->getHeader('test'));
    }

    /**
     * @covers Guzzle\Service\Command\AbstractCommand::__construct
     */
    public function testCommandsAllowsCustomRequestHeadersAsArray()
    {
        $command = new MockCommand(array(
            'headers' => array(
                'Foo' => 'Bar'
            )
        ));
        $this->assertInstanceOf('Guzzle\Common\Collection', $command->getRequestHeaders());
        $this->assertEquals('Bar', $command->getRequestHeaders()->get('Foo'));
    }

    private function getApiCommand()
    {
        return new ApiCommand(array(
            'name' => 'foobar',
            'method' => 'POST',
            'class' => 'Guzzle\\Tests\\Service\\Mock\\Command\\MockCommand',
            'params' => array(
                'test' => array(
                    'default' => '123',
                    'type' => 'string'
                )
        )));
    }

    /**
     * @covers Guzzle\Service\Command\AbstractCommand
     */
    public function testCommandsUsesApiCommand()
    {
        $api = $this->getApiCommand();
        $command = new MockCommand(array(), $api);
        $this->assertSame($api, $command->getApiCommand());
        $command->setClient($this->getClient())->prepare();
        $this->assertEquals('123', $command->get('test'));
        $this->assertSame($api, $command->getApiCommand($api));
    }

    /**
     * @covers Guzzle\Service\Command\AbstractCommand::__call
     * @expectedException Guzzle\Common\Exception\BadMethodCallException
     * @expectedExceptionMessage Magic method calls are disabled for this command.  Consider enabling magic method calls by setting the command.magic_method_call parameter to true.
     */
    public function testMissingMethodCallsThrowExceptionsWhenMagicIsDisabled()
    {
        $command = new MockCommand(array());
        $command->setFooBarBaz('123');
    }

    /**
     * @covers Guzzle\Service\Command\AbstractCommand::__call
     * @expectedException Guzzle\Common\Exception\BadMethodCallException
     * @expectedExceptionMessage Missing method setFoo
     */
    public function testMissingMethodCallsThrowExceptionsWhenParameterIsInvalid()
    {
        $command = new MockCommand(array(
            'command.magic_method_call' => true
        ), $this->getApiCommand());
        $command->setFoo('bar_baz');
    }

    /**
     * @covers Guzzle\Service\Command\AbstractCommand::__call
     */
    public function testMissingMethodCallsAllowedWhenEnabled()
    {
        $command = new MockCommand(array(
            'command.magic_method_call' => true
        ), $this->getApiCommand());
        $command->setTest('foo');
        $this->assertEquals('foo', $command->get('test'));
    }

    /**
     * @covers Guzzle\Service\Command\AbstractCommand::__clone
     */
    public function testCloneMakesNewRequest()
    {
        $client = $this->getClient();
        $command = new MockCommand(array(
            'command.magic_method_call' => true
        ), $this->getApiCommand());
        $command->setClient($client);

        $command->prepare();
        $this->assertTrue($command->isPrepared());

        $command2 = clone $command;
        $this->assertFalse($command2->isPrepared());
    }

    /**
     * @covers Guzzle\Service\Command\AbstractCommand::setOnComplete
     * @covers Guzzle\Service\Command\AbstractCommand::__construct
     * @covers Guzzle\Service\Command\AbstractCommand::getResult
     */
    public function testHasOnCompleteMethod()
    {
        $that = $this;
        $called = 0;

        $testFunction = function($command) use (&$called, $that) {
            $called++;
            $that->assertInstanceOf('Guzzle\Service\Command\CommandInterface', $command);
        };

        $client = $this->getClient();
        $command = new MockCommand(array(
            'command.on_complete' => $testFunction
        ), $this->getApiCommand());
        $command->setClient($client);

        $command->prepare()->setResponse(new Response(200));
        $command->execute();
        $this->assertEquals(1, $called);
    }

    /**
     * @covers Guzzle\Service\Command\AbstractCommand::setOnComplete
     * @expectedException Guzzle\Common\Exception\InvalidArgumentException
     */
    public function testOnCompleteMustBeCallable()
    {
        $client = $this->getClient();
        $command = new MockCommand();
        $command->setOnComplete('foo');
    }

    /**
     * @covers Guzzle\Service\Command\AbstractCommand::setInspector
     * @covers Guzzle\Service\Command\AbstractCommand::getInspector
     */
    public function testInspectorCanBeInjected()
    {
        $instance = Inspector::getInstance();
        $command = new MockCommand();

        $refObject = new \ReflectionObject($command);
        $method = $refObject->getMethod('getInspector');
        $method->setAccessible(true);

        $this->assertSame($instance, $method->invoke($command));

        $newInspector = new Inspector();
        $command->setInspector($newInspector);
        $this->assertSame($newInspector, $method->invoke($command));
    }

    /**
     * @covers Guzzle\Service\Command\AbstractCommand::setResult
     */
    public function testCanSetResultManually()
    {
        $client = $this->getClient();
        $client->getEventDispatcher()->addSubscriber(new MockPlugin(array(
            new Response(200)
        )));
        $command = new MockCommand();
        $client->execute($command);
        $command->setResult('foo!');
        $this->assertEquals('foo!', $command->getResult());
    }

    /**
     * @covers Guzzle\Service\Command\AbstractCommand::initConfig
     */
    public function testCanInitConfig()
    {
        $command = $this->getMockBuilder('Guzzle\\Service\\Command\\AbstractCommand')
            ->setConstructorArgs(array(array(
                'foo' => 'bar'
            ), new ApiCommand(array(
                'params' => array(
                    'baz' => new ApiParam(array(
                        'default' => 'baaar'
                    ))
                )
            ))))
            ->getMockForAbstractClass();

        $this->assertEquals('bar', $command['foo']);
        $this->assertEquals('baaar', $command['baz']);
    }
}
