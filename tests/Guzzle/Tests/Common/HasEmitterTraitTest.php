<?php

namespace Guzzle\Tests\Common;

use Guzzle\Common\HasEmitterInterface;
use Guzzle\Common\HasEmitterTrait;

class AbstractHasEmitter implements HasEmitterInterface
{
    use HasEmitterTrait;
}

/**
 * @covers Guzzle\Common\HasEmitterTrait
 */
class HasDispatcherTraitTest extends \PHPUnit_Framework_TestCase
{
    public function testHelperAttachesSubscribers()
    {
        $mock = $this->getMockBuilder('Guzzle\Tests\Common\AbstractHasEmitter')
            ->getMockForAbstractClass();

        $result = $mock->getEmitter();
        $this->assertInstanceOf('Guzzle\Common\EmitterInterface', $result);
        $result2 = $mock->getEmitter();
        $this->assertSame($result, $result2);
    }
}
