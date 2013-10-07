<?php

namespace Guzzle\Tests\Http\Event;

use Guzzle\Http\Adapter\Transaction;
use Guzzle\Http\Client;
use Guzzle\Http\Event\RequestErrorEvent;
use Guzzle\Http\Exception\RequestException;
use Guzzle\Http\Message\Request;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Event\RequestEvents;

/**
 * @covers Guzzle\Http\Event\RequestErrorEvent
 */
class RequestErrorEventTest extends \PHPUnit_Framework_TestCase
{
    public function testInterceptsWithEvent()
    {
        $client = new Client();
        $request = new Request('GET', '/');
        $response = new Response(404);
        $transaction = new Transaction($client, $request);
        $except = new RequestException('foo', $request, $response);
        $event = new RequestErrorEvent($transaction, $except);

        $this->assertSame($except, $event->getException());
        $this->assertSame($response, $event->getResponse());
        $this->assertSame($request, $event->getRequest());

        $res = null;
        $request->getEventDispatcher()->addListener(RequestEvents::AFTER_SEND, function ($e) use (&$res) {
            $res = $e;
        });

        $good = new Response(200);
        $event->intercept($good);
        $this->assertTrue($event->isPropagationStopped());
        $this->assertSame($res->getClient(), $event->getClient());
        $this->assertSame($good, $res->getResponse());
    }
}
