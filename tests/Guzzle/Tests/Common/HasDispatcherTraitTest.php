<?php

namespace Guzzle\Tests\Common;

use Guzzle\Common\HasDispatcherInterface;
use Guzzle\Common\HasDispatcherTrait;

class AbstractHasDispatcher implements HasDispatcherInterface
{
    use HasDispatcherTrait;
}

/**
 * @covers Guzzle\Common\HasDispatcherTrait
 */
class HasDispatcherTraitTest extends \PHPUnit_Framework_TestCase
{
    public function testHelperAttachesSubscribers()
    {
        $mock = $this->getMockBuilder('Guzzle\Tests\Common\AbstractHasDispatcher')
            ->getMockForAbstractClass();

        $result = $mock->getEventDispatcher();
        $this->assertInstanceOf('Symfony\Component\EventDispatcher\EventDispatcherInterface', $result);
        $result2 = $mock->getEventDispatcher();
        $this->assertSame($result, $result2);
    }
}
