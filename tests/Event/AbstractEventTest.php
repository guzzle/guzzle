<?php
namespace GuzzleHttp\Tests\Event;

class AbstractEventTest extends \PHPUnit_Framework_TestCase
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
