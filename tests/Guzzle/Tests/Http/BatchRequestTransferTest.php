<?php

namespace Guzzle\Tests\Http;

use Guzzle\Http\Client;
use Guzzle\Http\BatchRequestTransfer;
use Guzzle\Http\Curl\CurlMulti;

/**
 * @covers Guzzle\Http\BatchRequestTransfer
 */
class BatchRequestTransferTest extends \Guzzle\Tests\GuzzleTestCase
{
    public function testCreatesBatchesBasedOnCurlMultiHandles()
    {
        $client1 = new Client('http://www.example.com');
        $client1->setCurlMulti(new CurlMulti());

        $client2 = new Client('http://www.example.com');
        $client2->setCurlMulti(new CurlMulti());

        $request1 = $client1->get();
        $request2 = $client2->get();
        $request3 = $client1->get();
        $request4 = $client2->get();
        $request5 = $client1->get();

        $queue = new \SplQueue();
        $queue[] = $request1;
        $queue[] = $request2;
        $queue[] = $request3;
        $queue[] = $request4;
        $queue[] = $request5;

        $batch = new BatchRequestTransfer(2);
        $this->assertEquals(array(
            array($request1, $request3),
            array($request3),
            array($request2, $request4)
        ), $batch->createBatches($queue));
    }

    /**
     * @expectedException Guzzle\Common\Exception\InvalidArgumentException
     */
    public function testEnsuresAllItemsAreRequests()
    {
        $queue = new \SplQueue();
        $queue[] = 'foo';
        $batch = new BatchRequestTransfer(2);
        $batch->createBatches($queue);
    }

    public function testTransfersBatches()
    {
        $client = new Client('http://localhost:123');
        $request = $client->get();

        $multi = $this->getMock('Guzzle\Http\Curl\CurlMultiInterface');
        $client->setCurlMulti($multi);
        $multi->expects($this->once())
            ->method('add')
            ->with($request);
        $multi->expects($this->once())
            ->method('send');

        $batch = new BatchRequestTransfer(2);
        $batch->transfer(array($request));
    }

    public function testDoesNotTransfersEmptyBatches()
    {
        $batch = new BatchRequestTransfer(2);
        $batch->transfer(array());
    }
}
