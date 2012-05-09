<?php

namespace Guzzle\Tests\Service\Resource;

use Guzzle\Service\Resource\ResourceIteratorApplyBatched;
use Guzzle\Service\Resource\ResourceIterator;
use Guzzle\Tests\Service\Mock\Model\MockCommandIterator;

/**
 * @group server
 */
class ResourceIteratorApplyBatchedTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Resource\ResourceIteratorApplyBatched::getAllEvents
     */
    public function testDescribesEvents()
    {
        $this->assertInternalType('array', ResourceIteratorApplyBatched::getAllEvents());
    }

    /**
     * @covers Guzzle\Service\Resource\ResourceIteratorApplyBatched
     */
    public function testSendsRequestsForNextSetOfResources()
    {
        $this->getServer()->flush();
        $this->getServer()->enqueue(array(
            "HTTP/1.1 200 OK\r\nContent-Length: 52\r\n\r\n{ \"next_token\": \"g\", \"resources\": [\"d\", \"e\", \"f\"] }",
            "HTTP/1.1 200 OK\r\nContent-Length: 52\r\n\r\n{ \"next_token\": \"j\", \"resources\": [\"g\", \"h\", \"i\"] }",
            "HTTP/1.1 200 OK\r\nContent-Length: 41\r\n\r\n{ \"next_token\": \"\", \"resources\": [\"j\"] }",
        ));

        $ri = new MockCommandIterator($this->getServiceBuilder()->get('mock')->getCommand('iterable_command'), array(
            'page_size' => 3,
            'limit'     => 7
        ));

        $received = array();
        $apply = new ResourceIteratorApplyBatched($ri, function(ResourceIterator $i, array $batch) use (&$received) {
            $received[] = $batch;
        });

        $apply->apply(3);

        $requests = $this->getServer()->getReceivedRequests(true);
        $this->assertEquals(3, count($requests));
        $this->assertEquals(3, $requests[0]->getQuery()->get('page_size'));
        $this->assertEquals(3, $requests[1]->getQuery()->get('page_size'));
        $this->assertEquals(1, $requests[2]->getQuery()->get('page_size'));

        $this->assertEquals(array('d', 'e', 'f'), array_values($received[0]));
        $this->assertEquals(array('g', 'h', 'i'), array_values($received[1]));
        $this->assertEquals(array('j'), array_values($received[2]));

        $this->assertEquals(3, $apply->getBatchCount());
        $this->assertEquals(7, $apply->getIteratedCount());
    }
}
