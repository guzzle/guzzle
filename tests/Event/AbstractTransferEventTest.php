<?php
namespace GuzzleHttp\Tests\Event;

use GuzzleHttp\Client;
use GuzzleHttp\Message\Response;
use GuzzleHttp\Transaction;
use GuzzleHttp\Message\Request;

/**
 * @covers GuzzleHttp\Event\AbstractTransferEvent
 */
class AbstractTransferEventTest extends \PHPUnit_Framework_TestCase
{
    public function testHasStats()
    {
        $t = new Transaction(new Client(), new Request('GET', '/'));
        $t->transferInfo = ['foo' => 'bar'];
        $e = $this->getMockBuilder('GuzzleHttp\Event\AbstractTransferEvent')
            ->setConstructorArgs([$t])
            ->getMockForAbstractClass();
        $this->assertNull($e->getTransferInfo('baz'));
        $this->assertEquals('bar', $e->getTransferInfo('foo'));
        $this->assertEquals($t->transferInfo, $e->getTransferInfo());
    }

    public function testHasResponse()
    {
        $t = new Transaction(new Client(), new Request('GET', '/'));
        $t->response = new Response(200);
        $e = $this->getMockBuilder('GuzzleHttp\Event\AbstractTransferEvent')
            ->setConstructorArgs([$t])
            ->getMockForAbstractClass();
        $this->assertTrue($e->hasResponse());
        $this->assertSame($t->response, $e->getResponse());
    }

    public function testCanInterceptWithResponse()
    {
        $t = new Transaction(new Client(), new Request('GET', '/'));
        $r = new Response(200);
        $e = $this->getMockBuilder('GuzzleHttp\Event\AbstractTransferEvent')
            ->setConstructorArgs([$t])
            ->getMockForAbstractClass();
        $e->intercept($r);
        $this->assertSame($t->response, $r);
        $this->assertSame($t->response, $e->getResponse());
        $this->assertTrue($e->isPropagationStopped());
    }
}
