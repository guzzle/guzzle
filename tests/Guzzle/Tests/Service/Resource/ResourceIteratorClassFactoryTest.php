<?php

namespace Guzzle\Tests\Service\Resource;

use Guzzle\Service\Resource\ResourceIteratorClassFactory;
use Guzzle\Tests\Service\Mock\Command\MockCommand;

/**
 * @covers Guzzle\Service\Resource\ResourceIteratorClassFactory
 */
class ResourceIteratorClassFactoryTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @expectedException InvalidArgumentException
     * @expectedExceptionMessage The first argument must be an instance of CommandInterface
     */
    public function testValidatesCommand()
    {
        $factory = new ResourceIteratorClassFactory('foo');
        $factory->build('foo');
    }

    public function testBuildsResourceIterators()
    {
        $factory = new ResourceIteratorClassFactory('Guzzle\Tests\Service\Mock\Model');
        $command = new MockCommand();
        $iterator = $factory->build($command, array(
            'client.namespace' => 'Guzzle\Tests\Service\Mock'
        ));

        $this->assertInstanceOf('Guzzle\Tests\Service\Mock\Model\MockCommandIterator', $iterator);
    }
}
