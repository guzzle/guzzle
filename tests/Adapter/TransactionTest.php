<?php

namespace GuzzleHttp\Tests\Adapter;

use GuzzleHttp\Adapter\Transaction;
use GuzzleHttp\Client;
use GuzzleHttp\Message\Request;
use GuzzleHttp\Message\Response;

/**
 * @covers GuzzleHttp\Adapter\Transaction
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
