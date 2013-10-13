<?php

namespace Guzzle\Tests\Http\Adapter;

use Guzzle\Http\Adapter\Transaction;
use Guzzle\Http\Client;
use Guzzle\Http\Message\Request;
use Guzzle\Http\Message\Response;

/**
 * @covers Guzzle\Http\Adapter\Transaction
 */
class TransactionTest extends \PHPUnit_Framework_TestCase
{
    public function testHasRequestAndClient()
    {
        $c = new Client();
        $req = new Request('GET', '/');
        $response = new Response(200);
        $t = new Transaction($c, $req);
        $this->assertSame($c, $t->getClient());
        $this->assertSame($req, $t->getRequest());
        $this->assertNull($t->getResponse());
        $t->setResponse($response);
        $this->assertSame($response, $t->getResponse());
    }
}
