<?php

namespace Guzzle\Tests\Service\Command;

use Guzzle\Tests\Common\Mock\MockObserver;
use Guzzle\Http\Pool\Pool;
use Guzzle\Http\Message\Response;
use Guzzle\Service\Command\CommandSet;
use Guzzle\Service\Client;
use Guzzle\Service\DescriptionBuilder\XmlDescriptionBuilder;
use Guzzle\Service\Command\ConcreteCommandFactory;
use Guzzle\Tests\Service\Mock\Command\MockCommand;
use Guzzle\Service\Plugin\MockPlugin;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class CommandSetTest extends AbstractCommandTest
{
    /**
     * @covers Guzzle\Service\Command\CommandSet::__construct
     * @covers Guzzle\Service\Command\CommandSet::hasCommand
     * @covers Guzzle\Service\Command\CommandSet::addCommand
     */
    public function test__construct()
    {
        $pool = new Pool();
        $cmd = new MockCommand();
        $commandSet = new CommandSet(array($cmd), $pool);
        $this->assertTrue($commandSet->hasCommand($cmd));
    }

    /**
     * @covers Guzzle\Service\Command\CommandSet::hasCommand
     * @covers Guzzle\Service\Command\CommandSet::addCommand
     * @covers Guzzle\Service\Command\CommandSet::removeCommand
     * @covers Guzzle\Service\Command\CommandSet::getParallelCommands
     * @covers Guzzle\Service\Command\CommandSet::getSerialCommands
     * @covers Guzzle\Service\Command\CommandSet::count
     * @covers Guzzle\Service\Command\CommandSet::getIterator
     */
    public function testAllowsCommandManipulationAndIntrospection()
    {
        $commandSet = new CommandSet();

        // Check when no commands are set
        $this->assertEquals(array(), $commandSet->getSerialCommands());

        // Create some mock commands
        $command1 = new MockCommand();
        $command2 = new MockCommand();
        $command2->setCanBatch(false);

        // Check the fluent interface
        $this->assertEquals($commandSet, $commandSet->addCommand($command1));
        $commandSet->addCommand($command2);

        // Check that the commands are registered and findable
        $this->assertTrue($commandSet->hasCommand($command1));
        $this->assertTrue($commandSet->hasCommand($command2));
        $this->assertTrue($commandSet->hasCommand('Guzzle\\Tests\\Service\\Mock\\Command\\MockCommand'));

        // Test that the Countable interface is working
        $this->assertEquals(2, count($commandSet));
        // Test that the IteratorAggregate interface is working
        $this->assertInstanceOf('ArrayIterator', $commandSet->getIterator());
        $this->assertEquals(2, count($commandSet->getIterator()));

        // Check that filtering by command type works-- serial vs parallel
        $this->assertEquals(array($command1), $commandSet->getParallelCommands());
        $this->assertEquals(array($command2), $commandSet->getSerialCommands());

        // Remove the command by object
        $commandSet->removeCommand($command1);
        $this->assertFalse($commandSet->hasCommand($command1));
        $this->assertTrue($commandSet->hasCommand($command2));

        // Remove the command by class
        $commandSet->removeCommand('Guzzle\\Tests\\Service\\Mock\\Command\\MockCommand');
        $this->assertFalse($commandSet->hasCommand($command2));
    }

    /**
     * @covers Guzzle\Service\Command\CommandSet::execute
     * @covers Guzzle\Service\Command\CommandSetException
     */
    public function testThrowsExceptionWhenAnyCommandHasNoClient()
    {
        $cmd = new MockCommand;
        $commandSet = new CommandSet(array($cmd));
        try {
            $commandSet->execute();
            $this->fail('CommandSetException not thrown when a command did not have a client');
        } catch (\Guzzle\Service\Command\CommandSetException $e) {
            $this->assertEquals(array($cmd), $e->getCommands());
        }
    }

    /**
     * @covers Guzzle\Service\Command\CommandSet::execute
     * @covers Guzzle\Service\Command\CommandSet::update
     */
    public function testExecutesCommands()
    {
        $client = $this->getClient();
        $observer = new MockObserver();

        // Create a Mock response
        $response = new Response(200, array(
            'Content-Type' => 'application/xml'
        ), '<xml><data>123</data></xml>');

        $client->getEventManager()->attach(new MockPlugin(array(
            $response,
            $response,
            $response
        )));

        $command1 = new MockCommand();
        $command1->setClient($client);
        $command2 = new MockCommand();
        $command2->setClient($client);
        $command2->setCanBatch(false);
        $command3 = new MockCommand();
        $command3->setClient($client);

        $commandSet = new CommandSet(array($command1, $command2, $command3));
        $client->getEventManager()->attach($observer);
        $commandSet->execute();

        $this->assertTrue($command1->isExecuted());
        $this->assertTrue($command2->isExecuted());
        $this->assertTrue($command3->isExecuted());
        $this->assertTrue($command1->isPrepared());
        $this->assertTrue($command2->isPrepared());
        $this->assertTrue($command3->isPrepared());

        $this->assertEquals($response, $command1->getResponse());
        $this->assertEquals($response, $command2->getResponse());

        $this->assertEquals(3, count(array_filter($observer->events, function($e) {
            return $e == 'command.before_send';
        })));

        $this->assertEquals(3, count(array_filter($observer->events, function($e) {
            return $e == 'command.after_send';
        })));

        // make sure the command set was detached as a listener
        $this->assertFalse($command1->getRequest()->getEventManager()->hasObserver('Guzzle\\Service\\Command\\CommandSet'));
        // make sure that the command reference was removed
        $this->assertFalse($command1->getRequest()->getParams()->hasKey('command'));

        // Make sure that the command.after_send events are staggered, meaning they happened as requests completed
        $lastEvent = '';
        foreach ($observer->events as $e) {
            if ($lastEvent == 'command.after_send' && $e == 'command.after_send') {
                $this->fail('Not completing commands as they complete: ' . var_export($observer->events, true));
            }
            $lastEvent = $e;
        }
    }
}