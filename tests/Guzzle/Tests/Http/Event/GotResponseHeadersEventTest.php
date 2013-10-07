<?php

namespace Guzzle\Tests\Http\Event;

use Guzzle\Http\Adapter\Transaction;
use Guzzle\Http\Client;
use Guzzle\Http\Event\GotResponseHeadersEvent;
use Guzzle\Http\Message\Request;
use Guzzle\Http\Message\Response;

/**
 * @covers Guzzle\Http\Event\GotResponseHeadersEvent
 */
class GotResponseHeadersEventTest extends \PHPUnit_Framework_TestCase
{
    public function testHasValues()
    {
        $c = new Client();
        $r = new Request('GET', '/');
        $t = new Transaction($c, $r);
        $response = new Response(200);
        $t->setResponse($response);
        $e = new GotResponseHeadersEvent($t);
        $this->assertSame($c, $e->getClient());
        $this->assertSame($r, $e->getRequest());
        $this->assertSame($response, $e->getResponse());
    }
}
