<?php

namespace Guzzle\Tests\Service;

use Guzzle\Service\ResourceIterator;
use Guzzle\Tests\Service\Mock\MockResourceIterator;

/**
 * @group server
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class ResourceIteratorTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
      * @covers Guzzle\Service\ResourceIterator
     */
    public function testConstructorConfiguresDefaults()
    {
        $ri = $this->getMockForAbstractClass('Guzzle\\Service\\ResourceIterator', array(
            $this->getServiceBuilder()->get('mock'),
            array(
                'limit' => 10,
                'page_size' => 3,
                'resources' => array('a', 'b', 'c'),
                'next_token' => 'd'
            )
        ), 'MockIterator');

        $this->assertEquals('d', $ri->getNextToken());
        $this->assertEquals(array('a', 'b', 'c'), $ri->toArray());

        $ri->rewind();
        $this->assertEquals('a', $ri->current());
        $ri->next();
        $this->assertEquals('b', $ri->current());
        $ri->next();
        $this->assertEquals('c', $ri->current());

        // It ran out
        $ri->next();
        $this->assertEquals('', $ri->current());

        $this->assertEquals(3, count($ri));
        $this->assertEquals(3, $ri->getPosition());

        // Rewind works?
        $ri->rewind();
        $this->assertEquals('a', $ri->current());
        $ri->next();
        $this->assertEquals('b', $ri->current());
        $ri->next();
        $this->assertEquals('c', $ri->current());
    }

    /**
     * @covers Guzzle\Service\ResourceIterator
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

        $this->assertEquals(array('a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j'), $ri->toArray());
        $requests = $this->getServer()->getReceivedRequests(true);
        $this->assertEquals(3, count($requests));
        $this->assertEquals(3, $requests[0]->getQuery()->get('count'));
        $this->assertEquals(3, $requests[1]->getQuery()->get('count'));
        $this->assertEquals(3, $requests[2]->getQuery()->get('count'));

        // Reset and resend
        $this->getServer()->flush();
        $this->getServer()->enqueue(array(
            "HTTP/1.1 200 OK\r\nContent-Length: 52\r\n\r\n{ \"next_token\": \"g\", \"resources\": [\"d\", \"e\", \"f\"] }",
            "HTTP/1.1 200 OK\r\nContent-Length: 52\r\n\r\n{ \"next_token\": \"j\", \"resources\": [\"g\", \"h\", \"i\"] }",
            "HTTP/1.1 200 OK\r\nContent-Length: 41\r\n\r\n{ \"next_token\": \"\", \"resources\": [\"j\"] }",
        ));
        $d = array();
        reset($ri);
        foreach ($ri as $data) {
            $d[] = $data;
        }
        $this->assertEquals(array('a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j'), $d);
    }

    /**
     * @covers Guzzle\Service\ResourceIterator
     */
    public function testCalculatesPageSize()
    {
        $this->getServer()->flush();
        $this->getServer()->enqueue(array(
            "HTTP/1.1 200 OK\r\nContent-Length: 52\r\n\r\n{ \"next_token\": \"g\", \"resources\": [\"d\", \"e\", \"f\"] }",
            "HTTP/1.1 200 OK\r\nContent-Length: 52\r\n\r\n{ \"next_token\": \"j\", \"resources\": [\"g\", \"h\", \"i\"] }",
            "HTTP/1.1 200 OK\r\nContent-Length: 52\r\n\r\n{ \"next_token\": \"j\", \"resources\": [\"g\", \"h\"] }"
        ));

        $ri = new MockResourceIterator($this->getServiceBuilder()->get('mock'), array(
            'page_size' => 3,
            'limit' => 8,
            'resources' => array('a', 'b', 'c'),
            'next_token' => 'd'
        ));

        $this->assertEquals(array('a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'), $ri->toArray());
        $requests = $this->getServer()->getReceivedRequests(true);
        $this->assertEquals(2, count($requests));
        $this->assertEquals(3, $requests[0]->getQuery()->get('count'));
        $this->assertEquals(2, $requests[1]->getQuery()->get('count'));
    }
}