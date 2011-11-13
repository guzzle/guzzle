<?php

namespace Guzzle\Tests\Http\Pool;

use Guzzle\Common\Collection;
use Guzzle\Common\Event\Subject;
use Guzzle\Common\Event\Observer;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Message\Request;
use Guzzle\Http\Pool\PoolInterface;
use Guzzle\Http\Pool\Pool;
use Guzzle\Http\Pool\PoolRequestException;

/**
 * @group server
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class PoolTest extends \Guzzle\Tests\GuzzleTestCase implements Observer
{
    /**
     * @var Guzzle\Http\Pool\Pool
     */
    private $pool;

    /**
     * @var Guzzle\Common\Collection
     */
    private $updates;

    /**
     * Prepares the environment before running a test.
     */
    protected function setUp()
    {
        parent::setUp();
        $this->updates = new Collection();
        $this->pool = new MockPool();
        $this->pool->getEventManager()->attach($this);
    }

    /**
     * Cleans up the environment after running a test.
     */
    protected function tearDown()
    {
        $this->pool->getEventManager()->detach($this);
        $this->pool = null;
        parent::tearDown();
    }

    /**
     * @covers Guzzle\Http\Pool\Pool::__construct
     */
    public function testConstructorCreatesMultiHandle()
    {
        $this->assertInternalType('resource', $this->pool->getHandle());
        $this->assertEquals('curl_multi', get_resource_type($this->pool->getHandle()));
    }

    /**
     * @covers Guzzle\Http\Pool\Pool::__destruct
     */
    public function testDestructorClosesMultiHandle()
    {
        $handle = $this->pool->getHandle();
        $this->pool->__destruct();
        $this->assertFalse(is_resource($handle));
    }

    /**
     * @covers Guzzle\Http\Pool\Pool::add
     * @covers Guzzle\Http\Pool\Pool::count
     */
    public function testRequestsCanBeAddedAndCounted()
    {
        $request1 = $this->pool->add(new Request('GET', 'http://www.google.com/'));
        $this->assertEquals(array($request1), $this->pool->all());
        $request2 = $this->pool->add(new Request('POST', 'http://www.google.com/'));
        $this->assertEquals(array($request1, $request2), $this->pool->all());
        $this->assertEquals(2, count($this->pool));
        $this->assertTrue($this->updates->hasKey(Pool::ADD_REQUEST) !== false);
        $this->assertFalse($this->updates->hasKey(Pool::REMOVE_REQUEST) !== false);
        $this->assertFalse($this->updates->hasKey(Pool::POLLING) !== false);
        $this->assertFalse($this->updates->hasKey(Pool::COMPLETE) !== false);
    }

    /**
     * @covers Guzzle\Http\Pool\Pool::send
     * @covers Guzzle\Http\Pool\Pool::getState
     */
    public function testSendsRequestsInParallel()
    {
        $this->assertEquals('idle', $this->pool->getState());
        $request = $this->pool->add(new Request('GET', 'http://www.google.com/'));
        $request->setResponse(new Response(200, array(), 'Body'), true);
        $this->pool->send();
        $this->assertTrue($this->updates->hasKey(Pool::ADD_REQUEST) !== false);
        $this->assertTrue($this->updates->hasKey(Pool::POLLING) !== false);
        $this->assertTrue($this->updates->hasKey(Pool::COMPLETE) !== false);
        $this->assertEquals(array('add_request', $request), $this->updates->get(Pool::ADD_REQUEST));
        $this->assertEquals(array('polling', null), $this->updates->get(Pool::POLLING));
        $this->assertEquals(array('complete', array($request)), $this->updates->get(Pool::COMPLETE));
        $this->assertEquals('complete', $this->pool->getState());
        $this->assertEquals('Body', $request->getResponse()->getBody()->__toString());

        // Sending it again will return false
        $this->assertFalse($this->pool->send());
    }

    /**
     * @covers Guzzle\Http\Pool\Pool::remove
     * @covers Guzzle\Http\Pool\Pool::all
     */
    public function testRequestsCanBeRemoved()
    {
        $request1 = $this->pool->add(new Request('GET', 'http://www.google.com/'));
        $request2 = $this->pool->add(new Request('PUT', 'http://www.google.com/'));
        $this->assertEquals(array($request1, $request2), $this->pool->all());
        $this->assertEquals($request1, $this->pool->remove($request1));
        $this->assertEquals(array($request2), $this->pool->all());
    }

    /**
     * @covers Guzzle\Http\Pool\Pool::reset
     */
    public function testsResetRemovesRequestsAndResetsState()
    {
        $request1 = $this->pool->add(new Request('GET', 'http://www.google.com/'));
        $this->pool->reset();
        $this->assertEquals(array(), $this->pool->all());
        $this->assertEquals('idle', $this->pool->getState());
        
        // Make sure the notification came through
        $this->assertEquals(array('reset', null), $this->updates->get('reset'));
    }

    /**
     * @covers Guzzle\Http\Pool\Pool::send
     * @covers Guzzle\Http\Pool\PoolRequestException
     */
    public function testPoolSendsThroughCurl()
    {
        $this->getServer()->enqueue(array(
            "HTTP/1.1 204 No content\r\n" .
            "Content-Length: 0\r\n" .
            "Server: Jetty(6.1.3)\r\n\r\n",
            
            "HTTP/1.1 200 OK\r\n" .
            "Content-Type: text/html; charset=utf-8\r\n" .
            "Content-Length: 4\r\n" .
            "Server: Jetty(6.1.3)\r\n\r\n" .
            "\r\n" .
            "data"
        ));
        
        $request1 = new Request('GET', $this->getServer()->getUrl());
        $request1->getEventManager()->attach($this);
        $request2 = new Request('GET', $this->getServer()->getUrl());
        $request2->getEventManager()->attach($this);
        
        $this->pool->add($request1);
        $this->pool->add($request2);
        $this->assertEquals(array($request1, $request2), $this->pool->send());

        $response1 = $request1->getResponse();
        $response2 = $request2->getResponse();

        $this->assertInstanceOf('Guzzle\\Http\\Message\\Response', $response1);
        $this->assertInstanceOf('Guzzle\\Http\\Message\\Response', $response2);

        $this->assertTrue($response1->getBody(true) == 'data' || $response2->getBody(true) == 'data');
        $this->assertTrue($response1->getBody(true) == '' || $response2->getBody(true) == '');
        $this->assertTrue($response1->getStatusCode() == '204' || $response2->getStatusCode() == '204');
        $this->assertNotEquals((string) $response1, (string) $response2);

        $this->assertTrue($this->updates->hasKey('request.before_send') !== false);
        $this->assertInternalType('array', $this->updates->get('request.before_send'));
    }

    /**
     * @covers Guzzle\Http\Pool\Pool::send
     * @covers Guzzle\Http\Pool\PoolRequestException
     * @depends testPoolSendsThroughCurl
     */
    public function testPoolSendsThroughCurlAndAggregatesRequestExceptions()
    {
        $this->getServer()->enqueue(array(
            "HTTP/1.1 200 OK\r\n" .
            "Content-Type: text/html; charset=utf-8\r\n" .
            "Content-Length: 4\r\n" .
            "Server: Jetty(6.1.3)\r\n" .
            "\r\n" .
            "data",

            "HTTP/1.1 204 No content\r\n" .
            "Content-Length: 0\r\n" .
            "Server: Jetty(6.1.3)\r\n" .
            "\r\n",

            "HTTP/1.1 404 Not Found\r\n" .
            "Content-Length: 0\r\n" .
            "\r\n"
        ));
        
        $request1 = new Request('GET', $this->getServer()->getUrl());
        $request2 = new Request('HEAD', $this->getServer()->getUrl());
        $request3 = new Request('GET', $this->getServer()->getUrl());
        $this->pool->add($request1);
        $this->pool->add($request2);
        $this->pool->add($request3);

        try {
            $this->pool->send();
            $this->fail('PoolRequestException not thrown when aggregating request exceptions');
        } catch (PoolRequestException $e) {

            $this->assertInternalType('array', $e->getRequestExceptions());
            $this->assertEquals(1, count($e));
            $exceptions = $e->getRequestExceptions();

            $response1 = $request1->getResponse();
            $response2 = $request2->getResponse();
            $response3 = $request3->getResponse();

            $this->assertNotEquals((string) $response1, (string) $response2);
            $this->assertNotEquals((string) $response3, (string) $response1);
            $this->assertInstanceOf('Guzzle\\Http\\Message\\Response', $response1);
            $this->assertInstanceOf('Guzzle\\Http\\Message\\Response', $response2);
            $this->assertInstanceOf('Guzzle\\Http\\Message\\Response', $response3);

            $failed = $exceptions[0]->getResponse();
            $this->assertEquals(404, $failed->getStatusCode());
            $this->assertEquals(1, count($e));

            // Test the IteratorAggregate functionality
            foreach ($e as $excep) {
                $this->assertEquals($failed, $excep->getResponse());
            }
        }
    }

    /**
     * Listens for updates to the pool object and logs them in a
     * Guzzle\Common\Collection object.
     *
     * {@inheritdoc}
     */
    public function update(Subject $subject, $event, $context = null)
    {
        $this->updates->add($event, array($event, $context));
    }
}