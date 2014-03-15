<?php

namespace GuzzleHttp\Tests\Event;

use GuzzleHttp\Client;
use GuzzleHttp\Adapter\Transaction;
use GuzzleHttp\Message\Request;

/**
 * @covers GuzzleHttp\Event\AbstractTransferEvent
 */
class AbstractTransferEventTest extends \PHPUnit_Framework_TestCase
{
    public function testHasStats()
    {
        $s = ['foo' => 'bar'];
        $t = new Transaction(new Client(), new Request('GET', '/'));
        $e = $this->getMockBuilder('GuzzleHttp\Event\AbstractTransferEvent')
            ->setConstructorArgs([$t, $s])
            ->getMockForAbstractClass();
        $this->assertNull($e->getTransferInfo('baz'));
        $this->assertEquals('bar', $e->getTransferInfo('foo'));
        $this->assertEquals($s, $e->getTransferInfo());
    }
}
