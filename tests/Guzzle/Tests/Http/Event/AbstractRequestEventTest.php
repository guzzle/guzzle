<?php

namespace Guzzle\Tests\Http\Event;

use Guzzle\Http\Client;
use Guzzle\Http\Adapter\Transaction;
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
}
