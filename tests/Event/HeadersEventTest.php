<?php

namespace GuzzleHttp\Tests\Event;

use GuzzleHttp\Adapter\Transaction;
use GuzzleHttp\Client;
use GuzzleHttp\Event\HeadersEvent;
use GuzzleHttp\Message\Request;
use GuzzleHttp\Message\Response;

/**
 * @covers GuzzleHttp\Event\HeadersEvent
 */
class HeadersEventTest extends \PHPUnit_Framework_TestCase
{
    public function testHasValues()
    {
        $c = new Client();
        $r = new Request('GET', '/');
        $t = new Transaction($c, $r);
        $response = new Response(200);
        $t->setResponse($response);
        $e = new HeadersEvent($t);
        $this->assertSame($c, $e->getClient());
        $this->assertSame($r, $e->getRequest());
        $this->assertSame($response, $e->getResponse());
    }

    /**
     * @expectedException \RuntimeException
     */
    public function testEnsuresResponseIsSet()
    {
        $c = new Client();
        $r = new Request('GET', '/');
        $t = new Transaction($c, $r);
        new HeadersEvent($t);
    }
}
