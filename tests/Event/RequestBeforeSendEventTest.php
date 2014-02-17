<?php

namespace GuzzleHttp\Tests\Event;

use GuzzleHttp\Adapter\Transaction;
use GuzzleHttp\Client;
use GuzzleHttp\Event\BeforeEvent;
use GuzzleHttp\Message\Request;
use GuzzleHttp\Message\Response;

/**
 * @covers GuzzleHttp\Event\BeforeEvent
 */
class BeforeEventTest extends \PHPUnit_Framework_TestCase
{
    public function testInterceptsWithEvent()
    {
        $response = new Response(200);
        $res = null;
        $t = new Transaction(new Client(), new Request('GET', '/'));
        $t->getRequest()->getEmitter()->on('complete', function ($e) use (&$res) {
            $res = $e;
        });
        $e = new BeforeEvent($t);
        $e->intercept($response);
        $this->assertTrue($e->isPropagationStopped());
        $this->assertSame($res->getClient(), $e->getClient());
    }
}
