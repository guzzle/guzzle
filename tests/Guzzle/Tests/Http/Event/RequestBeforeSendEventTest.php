<?php

namespace Guzzle\Tests\Http\Event;

use Guzzle\Http\Adapter\Transaction;
use Guzzle\Http\Client;
use Guzzle\Http\Event\RequestBeforeSendEvent;
use Guzzle\Http\Message\Request;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Event\RequestEvents;

/**
 * @covers Guzzle\Http\Event\RequestBeforeSendEvent
 */
class RequestBeforeSendEventTest extends \PHPUnit_Framework_TestCase
{
    public function testInterceptsWithEvent()
    {
        $response = new Response(200);
        $res = null;
        $t = new Transaction(new Client(), new Request('GET', '/'));
        $t->getRequest()->getEventDispatcher()->addListener(RequestEvents::AFTER_SEND, function ($e) use (&$res) {
            $res = $e;
        });
        $e = new RequestBeforeSendEvent($t);
        $e->intercept($response);
        $this->assertTrue($e->isPropagationStopped());
        $this->assertSame($res->getClient(), $e->getClient());
    }
}
