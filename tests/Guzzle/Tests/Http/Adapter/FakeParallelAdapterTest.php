<?php

namespace Guzzle\Tests\Http\Adapter;

use Guzzle\Http\Adapter\FakeParallelAdapter;
use Guzzle\Http\Adapter\MockAdapter;
use Guzzle\Http\Client;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Adapter\TransactionIterator;

/**
 * @covers Guzzle\Http\Adapter\FakeParallelAdapter
 */
class FakeParallelAdapterTest extends \PHPUnit_Framework_TestCase
{
    public function testSendsAllTransactions()
    {
        $client = new Client();
        $requests = [
            $client->createRequest('GET', '/'),
            $client->createRequest('HEAD', '/'),
        ];

        $sent = [];
        $f = new FakeParallelAdapter(new MockAdapter(function ($trans) use (&$sent) {
            $sent[] = $trans->getRequest()->getMethod();
            return new Response(200);
        }));

        $tIter = new TransactionIterator($requests, $client, []);
        $f->sendAll($tIter, 2);
        $this->assertContains('GET', $sent);
        $this->assertContains('HEAD', $sent);
    }
}
