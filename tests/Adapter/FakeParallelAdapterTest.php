<?php
namespace GuzzleHttp\Tests\Adapter;

use GuzzleHttp\Adapter\FakeParallelAdapter;
use GuzzleHttp\Adapter\MockAdapter;
use GuzzleHttp\Adapter\TransactionIterator;
use GuzzleHttp\Client;
use GuzzleHttp\Event\ErrorEvent;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Message\Response;

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

    public function testThrowsImmediatelyIfInstructed()
    {
        $client = new Client();
        $request = $client->createRequest('GET', 'http://httbin.org');
        $request->getEmitter()->on('error', function (ErrorEvent $e) {
            $e->throwImmediately(true);
        });
        $sent = [];
        $f = new FakeParallelAdapter(
            new MockAdapter(function ($trans) use (&$sent) {
                $sent[] = $trans->getRequest()->getMethod();
                return new Response(404);
            })
        );
        $tIter = new TransactionIterator([$request], $client, []);
        try {
            $f->sendAll($tIter, 1);
            $this->fail('Did not throw');
        } catch (RequestException $e) {
            $this->assertSame($request, $e->getRequest());
        }
    }
}
