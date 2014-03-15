<?php

namespace GuzzleHttp\Tests\Event;

use GuzzleHttp\Adapter\Transaction;
use GuzzleHttp\Client;
use GuzzleHttp\Event\ErrorEvent;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Message\Request;
use GuzzleHttp\Message\Response;

/**
 * @covers GuzzleHttp\Event\ErrorEvent
 */
class ErrorEventTest extends \PHPUnit_Framework_TestCase
{
    public function testInterceptsWithEvent()
    {
        $client = new Client();
        $request = new Request('GET', '/');
        $response = new Response(404);
        $transaction = new Transaction($client, $request);
        $except = new RequestException('foo', $request, $response);
        $event = new ErrorEvent($transaction, $except);

        $this->assertSame($except, $event->getException());
        $this->assertSame($response, $event->getResponse());
        $this->assertSame($request, $event->getRequest());

        $res = null;
        $request->getEmitter()->on('complete', function ($e) use (&$res) {
            $res = $e;
        });

        $good = new Response(200);
        $event->intercept($good);
        $this->assertTrue($event->isPropagationStopped());
        $this->assertSame($res->getClient(), $event->getClient());
        $this->assertSame($good, $res->getResponse());
    }
}
