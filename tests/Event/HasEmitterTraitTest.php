<?php
namespace GuzzleHttp\Tests\Event;

use GuzzleHttp\Event\HasEmitterInterface;
use GuzzleHttp\Event\HasEmitterTrait;

class AbstractHasEmitter implements HasEmitterInterface
{
    use HasEmitterTrait;
}

/**
 * @covers GuzzleHttp\Event\HasEmitterTrait
 */
class HasEmitterTraitTest extends \PHPUnit_Framework_TestCase
{
    public function testHelperAttachesSubscribers()
    {
        $mock = $this->getMockBuilder('GuzzleHttp\Tests\Event\AbstractHasEmitter')
            ->getMockForAbstractClass();

        $result = $mock->getEmitter();
        $this->assertInstanceOf('GuzzleHttp\Event\EmitterInterface', $result);
        $result2 = $mock->getEmitter();
        $this->assertSame($result, $result2);
    }
}
