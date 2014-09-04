<?php
namespace GuzzleHttp\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Message\Request;
use GuzzleHttp\Message\Response;
use GuzzleHttp\Transaction;

class TransactionTest extends \PHPUnit_Framework_TestCase
{
    public function testHoldsData()
    {
        $client = new Client();
        $request = new Request('GET', 'http://www.foo.com');
        $t = new Transaction($client, $request);
        $this->assertSame($client, $t->client);
        $this->assertSame($request, $t->request);
        $response = new Response(200);
        $t->response = $response;
        $this->assertSame($response, $t->response);
    }
}
