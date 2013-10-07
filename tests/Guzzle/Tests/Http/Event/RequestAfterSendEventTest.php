<?php

namespace Guzzle\Tests\Http\Event;

use Guzzle\Http\Adapter\Transaction;
use Guzzle\Http\Client;
use Guzzle\Http\Event\RequestAfterSendEvent;
use Guzzle\Http\Message\Request;
use Guzzle\Http\Message\Response;

/**
 * @covers Guzzle\Http\Event\RequestAfterSendEvent
 */
class RequestAfterSendEventTest extends \PHPUnit_Framework_TestCase
{
    public function testHasValues()
    {
        $c = new Client();
        $r = new Request('GET', '/');
        $res = new Response(200);
        $t = new Transaction($c, $r);
        $e = new RequestAfterSendEvent($t);
        $e->intercept($res);
        $this->assertTrue($e->isPropagationStopped());
        $this->assertSame($res, $e->getResponse());
    }
}
