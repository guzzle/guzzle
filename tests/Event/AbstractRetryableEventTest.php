<?php
namespace GuzzleHttp\Tests\Event;

use GuzzleHttp\Client;
use GuzzleHttp\Transaction;
use GuzzleHttp\Message\Request;

/**
 * @covers GuzzleHttp\Event\AbstractRetryableEvent
 */
class AbstractRetryableEventTest extends \PHPUnit_Framework_TestCase
{
    public function testCanRetry()
    {
        $t = new Transaction(new Client(), new Request('GET', '/'));
        $t->transferInfo = ['foo' => 'bar'];
        $e = $this->getMockBuilder('GuzzleHttp\Event\AbstractRetryableEvent')
            ->setConstructorArgs([$t])
            ->getMockForAbstractClass();
        $e->retry();
        $this->assertTrue($e->isPropagationStopped());
        $this->assertEquals('before', $t->state);
    }

    public function testCanRetryAfterDelay()
    {
        $t = new Transaction(new Client(), new Request('GET', '/'));
        $t->transferInfo = ['foo' => 'bar'];
        $e = $this->getMockBuilder('GuzzleHttp\Event\AbstractRetryableEvent')
            ->setConstructorArgs([$t])
            ->getMockForAbstractClass();
        $e->retry(10);
        $this->assertTrue($e->isPropagationStopped());
        $this->assertEquals('before', $t->state);
        $this->assertEquals(10, $t->request->getConfig()->get('delay'));
    }
}
