<?php

namespace Guzzle\Tests\Service;

use Guzzle\Common\Collection;
use Guzzle\Common\Log\ClosureLogAdapter;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Message\RequestFactory;
use Guzzle\Http\Curl\CurlMulti;
use Guzzle\Http\Plugin\MockPlugin;
use Guzzle\Service\Description\ApiCommand;
use Guzzle\Service\Client;
use Guzzle\Service\Command\CommandInterface;
use Guzzle\Service\Description\XmlDescriptionBuilder;
use Guzzle\Service\Description\ServiceDescription;
use Guzzle\Tests\Service\Mock\Command\MockCommand;
use Guzzle\Service\Resource\ResourceIteratorClassFactory;

/**
 * @group server
 */
class ClientTest extends \Guzzle\Tests\GuzzleTestCase
{
    protected $service;
    protected $serviceTest;

    public function setUp()
    {
        $this->serviceTest = new ServiceDescription(array(
            'test_command' => new ApiCommand(array(
                'doc' => 'documentationForCommand',
                'method' => 'DELETE',
                'class' => 'Guzzle\\Tests\\Service\\Mock\\Command\\MockCommand',
                'args' => array(
                    'bucket' => array(
                        'required' => true
                    ),
                    'key' => array(
                        'required' => true
                    )
                )
            ))
        ));

        $this->service = ServiceDescription::factory(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'TestData' . DIRECTORY_SEPARATOR . 'test_service.xml');
    }

    /**
     * @covers Guzzle\Service\Client::factory
     */
    public function testFactoryCreatesClient()
    {
        $client = Client::factory(array(
            'base_url' => 'http://www.test.com/',
            'test' => '123'
        ));

        $this->assertEquals('http://www.test.com/', $client->getBaseUrl());
        $this->assertEquals('123', $client->getConfig('test'));
    }

    /**
     * @covers Guzzle\Service\Client::getAllEvents
     */
    public function testDescribesEvents()
    {
        $this->assertInternalType('array', Client::getAllEvents());
    }

    /**
     * @covers Guzzle\Service\Client::execute
     */
    public function testExecutesCommands()
    {
        $this->getServer()->flush();
        $this->getServer()->enqueue("HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n");

        $client = new Client($this->getServer()->getUrl());
        $cmd = new MockCommand();
        $client->execute($cmd);

        $this->assertInstanceOf('Guzzle\\Http\\Message\\Response', $cmd->getResponse());
        $this->assertInstanceOf('Guzzle\\Http\\Message\\Response', $cmd->getResult());
        $this->assertEquals(1, count($this->getServer()->getReceivedRequests(false)));
    }

    /**
     * @covers Guzzle\Service\Client::execute
     */
    public function testExecutesCommandsWithArray()
    {
        $client = new Client('http://www.test.com/');
        $client->getEventDispatcher()->addSubscriber(new MockPlugin(array(
            new Response(200),
            new Response(200)
        )));

        // Create a command set and a command
        $set = array(new MockCommand(), new MockCommand());
        $client->execute($set);

        // Make sure it sent
        $this->assertTrue($set[0]->isExecuted());
        $this->assertTrue($set[1]->isExecuted());
    }

    /**
     * @covers Guzzle\Service\Client::execute
     * @expectedException Guzzle\Common\Exception\InvalidArgumentException
     */
    public function testThrowsExceptionWhenInvalidCommandIsExecuted()
    {
        $client = new Client();
        $client->execute(new \stdClass());
    }

    /**
     * @covers Guzzle\Service\Client::getCommand
     * @expectedException InvalidArgumentException
     */
    public function testThrowsExceptionWhenMissingCommand()
    {
        $client = new Client();

        $mock = $this->getMock('Guzzle\\Service\\Command\\Factory\\FactoryInterface');
        $mock->expects($this->any())
             ->method('factory')
             ->with($this->equalTo('test'))
             ->will($this->returnValue(null));

        $client->setCommandFactory($mock);
        $client->getCommand('test');
    }

    /**
     * @covers Guzzle\Service\Client::getCommand
     */
    public function testCreatesCommandsUsingCommandFactory()
    {
        $mockCommand = new MockCommand();

        $client = new Mock\MockClient();
        $mock = $this->getMock('Guzzle\\Service\\Command\\Factory\\FactoryInterface');
        $mock->expects($this->any())
             ->method('factory')
             ->with($this->equalTo('foo'))
             ->will($this->returnValue($mockCommand));

        $client->setCommandFactory($mock);

        $command = $client->getCommand('foo', array(
            'acl' => '123'
        ));

        $this->assertSame($mockCommand, $command);
        $this->assertSame($client, $command->getClient());
    }

    /**
     * @covers Guzzle\Service\Client::getDescription
     * @covers Guzzle\Service\Client::setDescription
     */
    public function testOwnsServiceDescription()
    {
        $client = new Mock\MockClient();
        $this->assertNull($client->getDescription());

        $description = $this->getMock('Guzzle\\Service\\Description\\ServiceDescription');
        $this->assertSame($client, $client->setDescription($description));
        $this->assertSame($description, $client->getDescription());
    }

    /**
     * @covers Guzzle\Service\Client::setDescription
     */
    public function testSettingServiceDescriptionUpdatesFactories()
    {
        $client = new Mock\MockClient();
        $factory = $this->getMockBuilder('Guzzle\\Service\\Command\\Factory\\MapFactory')
            ->disableOriginalConstructor()
            ->getMock();
        $client->setCommandFactory($factory);

        $description = $this->getMock('Guzzle\\Service\\Description\\ServiceDescription');
        $client->setDescription($description);

        $cf = $this->readAttribute($client, 'commandFactory');
        $this->assertNotSame($factory, $cf);
        $this->assertInstanceOf('Guzzle\\Service\\Command\\Factory\\CompositeFactory', $cf);

        $array = $cf->getIterator()->getArrayCopy();
        $this->assertSame($array[0], $factory);
        $this->assertInstanceOf('Guzzle\\Service\\Command\\Factory\\ServiceDescriptionFactory', $array[1]);
        $this->assertSame($description, $array[1]->getServiceDescription());

        $description2 = $this->getMock('Guzzle\\Service\\Description\\ServiceDescription');
        $client->setDescription($description2);

        $cf = $this->readAttribute($client, 'commandFactory');
        $array = $cf->getIterator()->getArrayCopy();
        $this->assertSame($array[0], $factory);
        $this->assertInstanceOf('Guzzle\\Service\\Command\\Factory\\ServiceDescriptionFactory', $array[1]);
        $this->assertSame($description2, $array[1]->getServiceDescription());
    }

    /**
     * @covers Guzzle\Service\Client::__call
     * @expectedException BadMethodCallException
     */
    public function testMagicCallBehaviorIsDisabledByDefault()
    {
        $client = new Client();
        $client->foo();
    }

    /**
     * @covers Guzzle\Service\Client::__call
     * @covers Guzzle\Service\Client::setMagicCallBehavior
     * @expectedException InvalidArgumentException
     * @expectedExceptionMessage Command was not found matching foo
     */
    public function testMagicCallBehaviorEnsuresCommandExists()
    {
        $client = new Mock\MockClient();
        $client->setDescription($this->service);
        $client->setMagicCallBehavior(Client::MAGIC_CALL_RETURN);
        $client->foo();
    }

    /**
     * @covers Guzzle\Service\Client::__call
     */
    public function testMagicCallBehaviorReturnReturnsCommands()
    {
        $client = new Mock\MockClient();
        $client->setMagicCallBehavior(Client::MAGIC_CALL_RETURN);
        $client->setDescription($this->service);
        $this->assertInstanceOf('Guzzle\Tests\Service\Mock\Command\MockCommand', $client->mockCommand());
    }

    /**
     * @covers Guzzle\Service\Client::__call
     */
    public function testMagicCallBehaviorExecuteExecutesCommands()
    {
        $client = new Mock\MockClient();
        $client->setMagicCallBehavior(Client::MAGIC_CALL_EXECUTE);
        $client->setDescription($this->service);
        $client->getEventDispatcher()->addSubscriber(new MockPlugin(array(new Response(200))));
        $this->assertInstanceOf('Guzzle\Http\Message\Response', $client->mockCommand());
    }

    /**
     * @covers Guzzle\Service\Client::getCommandFactory
     * @covers Guzzle\Service\Client::setCommandFactory
     */
    public function testOwnsCommandFactory()
    {
        $client = new Mock\MockClient();
        $method = new \ReflectionMethod($client, 'getCommandFactory');
        $method->setAccessible(TRUE);
        $cf1 = $method->invoke($client);

        $cf = $this->readAttribute($client, 'commandFactory');
        $this->assertInstanceOf('Guzzle\\Service\\Command\\Factory\\CompositeFactory', $cf);
        $this->assertSame($method->invoke($client), $cf1);

        $mock = $this->getMock('Guzzle\\Service\\Command\\Factory\\CompositeFactory');
        $client->setCommandFactory($mock);
        $this->assertSame($mock, $this->readAttribute($client, 'commandFactory'));
    }

    /**
     * @covers Guzzle\Service\Client::getResourceIteratorFactory
     * @covers Guzzle\Service\Client::setResourceIteratorFactory
     */
    public function testOwnsResourceIteratorFactory()
    {
        $client = new Mock\MockClient();

        $method = new \ReflectionMethod($client, 'getResourceIteratorFactory');
        $method->setAccessible(TRUE);
        $rf1 = $method->invoke($client);

        $rf = $this->readAttribute($client, 'resourceIteratorFactory');
        $this->assertInstanceOf('Guzzle\\Service\\Resource\\ResourceIteratorClassFactory', $rf);
        $this->assertSame($rf1, $rf);

        $rf = new ResourceIteratorClassFactory('Guzzle\Tests\Service\Mock');
        $client->setResourceIteratorFactory($rf);
        $this->assertNotSame($rf1, $rf);
    }

    /**
     * @covers Guzzle\Service\Client::getCommand
     * @depends testMagicCallBehaviorExecuteExecutesCommands
     */
    public function testEnablesMagicMethodCallsOnCommandsIfEnabledOnClient()
    {
        $client = new Mock\MockClient();
        $command = $client->getCommand('other_command');
        $this->assertNull($command->get('command.magic_method_call'));

        $client->setMagicCallBehavior(Client::MAGIC_CALL_EXECUTE);
        $command = $client->getCommand('other_command');
        $this->assertTrue($command->get('command.magic_method_call'));
    }

    /**
     * @covers Guzzle\Service\Client::execute
     */
    public function testClientResetsRequestsBeforeExecutingCommands()
    {
        $this->getServer()->flush();
        $this->getServer()->enqueue(array(
            "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nHi",
            "HTTP/1.1 200 OK\r\nContent-Length: 1\r\n\r\nI"
        ));

        $client = new Mock\MockClient($this->getServer()->getUrl());

        $command = $client->getCommand('mock_command');
        $client->execute($command);
        $client->execute($command);
        $this->assertEquals('I', $command->getResponse()->getBody(true));
    }

    /**
     * @covers Guzzle\Service\Client::getIterator
     */
    public function testClientCreatesIterators()
    {
        $client = new Mock\MockClient();

        $iterator = $client->getIterator('mock_command', array(
            'foo' => 'bar'
        ), array(
            'limit' => 10
        ));

        $this->assertInstanceOf('Guzzle\Tests\Service\Mock\Model\MockCommandIterator', $iterator);
        $this->assertEquals(10, $this->readAttribute($iterator, 'limit'));

        $command = $this->readAttribute($iterator, 'originalCommand');
        $this->assertEquals('bar', $command->get('foo'));
    }

    /**
     * @covers Guzzle\Service\Client::getIterator
     */
    public function testClientCreatesIteratorsWithNoOptions()
    {
        $client = new Mock\MockClient();
        $iterator = $client->getIterator('mock_command');
        $this->assertInstanceOf('Guzzle\Tests\Service\Mock\Model\MockCommandIterator', $iterator);
    }

    /**
     * @covers Guzzle\Service\Client::getIterator
     */
    public function testClientCreatesIteratorsWithCommands()
    {
       $client = new Mock\MockClient();
       $command = new MockCommand();
       $iterator = $client->getIterator($command);
       $this->assertInstanceOf('Guzzle\Tests\Service\Mock\Model\MockCommandIterator', $iterator);
       $iteratorCommand = $this->readAttribute($iterator, 'originalCommand');
       $this->assertSame($command, $iteratorCommand);
   }
}
