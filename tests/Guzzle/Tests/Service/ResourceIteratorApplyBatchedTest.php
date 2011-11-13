<?php

namespace Guzzle\Tests\Service;

use Guzzle\Service\ResourceIteratorApplyBatched;
use Guzzle\Service\ResourceIterator;
use Guzzle\Tests\Service\Mock\MockResourceIterator;

/**
 * @group server
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class ResourceIteratorApplyBatchedTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\ResourceIteratorApplyBatched
     */
    public function testSendsRequestsForNextSetOfResources()
    {
        $this->getServer()->flush();
        $this->getServer()->enqueue(array(
            "HTTP/1.1 200 OK\r\nContent-Length: 52\r\n\r\n{ \"next_token\": \"g\", \"resources\": [\"d\", \"e\", \"f\"] }",
            "HTTP/1.1 200 OK\r\nContent-Length: 52\r\n\r\n{ \"next_token\": \"j\", \"resources\": [\"g\", \"h\", \"i\"] }",
            "HTTP/1.1 200 OK\r\nContent-Length: 41\r\n\r\n{ \"next_token\": \"\", \"resources\": [\"j\"] }",
        ));

        $ri = new MockResourceIterator($this->getServiceBuilder()->get('mock'), array(
            'page_size' => 3,
            'resources' => array('a', 'b', 'c'),
            'next_token' => 'd'
        ));

        $received = array();
        $apply = new ResourceIteratorApplyBatched($ri, function(ResourceIterator $i, array $batch) use (&$received) {
            $received[] = $batch;
        });

        $apply->apply(3);

        $requests = $this->getServer()->getReceivedRequests(true);
        $this->assertEquals(3, count($requests));
        $this->assertEquals(3, $requests[0]->getQuery()->get('count'));
        $this->assertEquals(3, $requests[1]->getQuery()->get('count'));
        $this->assertEquals(3, $requests[2]->getQuery()->get('count'));

        $this->assertEquals(array('a', 'b', 'c'), array_values($received[0]));
        $this->assertEquals(array('d', 'e', 'f'), array_values($received[1]));
        $this->assertEquals(array('g', 'h', 'i'), array_values($received[2]));
        $this->assertEquals(array('j'), array_values($received[3]));

        $this->assertEquals(4, $apply->getBatchCount());
        $this->assertEquals(10, $apply->getIteratedCount());
    }
}