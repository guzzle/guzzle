<?php

namespace Guzzle\Tests\Http\Event;

use Guzzle\Http\Client;
use Guzzle\Http\Adapter\Transaction;
use Guzzle\Http\Event\RequestEvents;
use Guzzle\Http\Message\Request;

/**
 * @covers Guzzle\Http\Event\AbstractRequestEvent
 */
class AbstractRequestEventTest extends \PHPUnit_Framework_TestCase
{
    public function testHasTransactionMethods()
    {
        $t = new Transaction(new Client(), new Request('GET', '/'));
        $e = $this->getMockBuilder('Guzzle\Http\Event\AbstractRequestEvent')
            ->setConstructorArgs([$t])
            ->getMockForAbstractClass();
        $this->assertSame($t->getClient(), $e->getClient());
        $this->assertSame($t->getRequest(), $e->getRequest());
    }

    public function testHasTransaction()
    {
        $t = new Transaction(new Client(), new Request('GET', '/'));
        $e = $this->getMockBuilder('Guzzle\Http\Event\AbstractRequestEvent')
            ->setConstructorArgs([$t])
            ->getMockForAbstractClass();
        $r = new \ReflectionMethod($e, 'getTransaction');
        $r->setAccessible(true);
        $this->assertSame($t, $r->invoke($e));
    }

    public function testEmitsAfterSendEvent()
    {
        $res = null;
        $t = new Transaction(new Client(), new Request('GET', '/'));
        $t->getRequest()->getEventDispatcher()->addListener(RequestEvents::AFTER_SEND, function ($e) use (&$res) {
            $res = $e;
        });
        $e = $this->getMockBuilder('Guzzle\Http\Event\AbstractRequestEvent')
            ->setConstructorArgs([$t])
            ->getMockForAbstractClass();
        $r = new \ReflectionMethod($e, 'emitAfterSend');
        $r->setAccessible(true);
        $r->invoke($e);
        $this->assertSame($res->getClient(), $t->getClient());
        $this->assertSame($res->getRequest(), $t->getRequest());
    }
}
