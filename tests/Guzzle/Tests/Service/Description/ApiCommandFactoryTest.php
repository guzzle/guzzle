<?php

namespace Guzzle\Tests\Service\Description;

use Guzzle\Service\Description\ApiCommandFactory;
use Guzzle\Service\Description\ApiCommand;

class ApiCommandFactoryTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Description\ApiCommandFactory
     */
    public function testBuildsCommandsUsingApiCommand()
    {
        $apiCommand = new ApiCommand(array(
            'name' => 'foo',
            'method' => 'GET',
            'path' => '/',
            'class' => 'Guzzle\\Service\\Command\\DynamicCommand'
        ));

        $factory = new ApiCommandFactory();

        $command = $factory->createCommand($apiCommand, array(
            'param' => 'value'
        ));

        $this->assertInstanceOf('Guzzle\\Service\\Command\\DynamicCommand', $command);
        $this->assertEquals('value', $command->get('param'));
    }
}