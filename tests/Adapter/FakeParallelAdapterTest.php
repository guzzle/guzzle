<?php

namespace GuzzleHttp\Tests\Adapter;

use GuzzleHttp\Adapter\FakeParallelAdapter;
use GuzzleHttp\Adapter\MockAdapter;
use GuzzleHttp\Client;
use GuzzleHttp\Message\Response;
use GuzzleHttp\Adapter\TransactionIterator;

/**
 * @covers GuzzleHttp\Adapter\FakeParallelAdapter
 */
class FakeParallelAdapterTest extends \PHPUnit_Framework_TestCase
{
    public function testSendsAllTransactions()
    {
        $client = new Client();
        $requests = [
            $client->createRequest('GET', 'http://httbin.org'),
            $client->createRequest('HEAD', 'http://httbin.org'),
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
