<?php

namespace Guzzle\Tests\Http\Event;

use Guzzle\Http\Client;
use Guzzle\Http\Event\ClientCreateRequestEvent;
use Guzzle\Http\Message\Request;

/**
 * @covers Guzzle\Http\Event\ClientCreateRequestEvent
 */
class ClientCreateRequestEventTest extends \PHPUnit_Framework_TestCase
{
    public function testHasValues()
    {
        $c = new Client();
        $r = new Request('GET', '/');
        $o = ['foo' => 'bar'];
        $e = new ClientCreateRequestEvent($c, $r, $o);
        $this->assertSame($c, $e->getClient());
        $this->assertSame($r, $e->getRequest());
        $this->assertSame($o, $e->getRequestOptions());
    }
}
