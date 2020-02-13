<?php
namespace GuzzleHttp\Tests\Event;

class AbstractEventTest extends \PHPUnit\Framework\TestCase
{
    public function testStopsPropagation()
    {
        $e = $this->getMockBuilder('GuzzleHttp\Event\AbstractEvent')
            ->getMockForAbstractClass();
        $this->assertFalse($e->isPropagationStopped());
        $e->stopPropagation();
        $this->assertTrue($e->isPropagationStopped());
    }
}
