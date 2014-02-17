<?php

namespace GuzzleHttp\Tests\Event;

use GuzzleHttp\Adapter\Transaction;
use GuzzleHttp\Client;
use GuzzleHttp\Event\CompleteEvent;
use GuzzleHttp\Message\Request;
use GuzzleHttp\Message\Response;

/**
 * @covers GuzzleHttp\Event\CompleteEvent
 */
class CompleteEventTest extends \PHPUnit_Framework_TestCase
{
    public function testHasValues()
    {
        $c = new Client();
        $r = new Request('GET', '/');
        $res = new Response(200);
        $t = new Transaction($c, $r);
        $e = new CompleteEvent($t);
        $e->intercept($res);
        $this->assertTrue($e->isPropagationStopped());
        $this->assertSame($res, $e->getResponse());
    }
}
