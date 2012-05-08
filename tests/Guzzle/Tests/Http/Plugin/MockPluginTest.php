<?php

namespace Guzzle\Tests\Http\Plugin;

use Guzzle\Common\Event;
use Guzzle\Http\EntityBody;
use Guzzle\Http\Message\RequestFactory;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Plugin\MockPlugin;
use Guzzle\Http\Client;

class MockPluginTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Http\Plugin\MockPlugin::getSubscribedEvents
     */
    public function testDescribesSubscribedEvents()
    {
        $this->assertInternalType('array', MockPlugin::getSubscribedEvents());
    }

    /**
     * @covers Guzzle\Http\Plugin\MockPlugin::getAllEvents
     */
    public function testDescribesEvents()
    {
        $this->assertInternalType('array', MockPlugin::getAllEvents());
    }

    /**
     * @covers Guzzle\Http\Plugin\MockPlugin::__construct
     * @covers Guzzle\Http\Plugin\MockPlugin::isTemporary
     */
    public function testCanBeTemporary()
    {
        $plugin = new MockPlugin();
        $this->assertFalse($plugin->isTemporary());
        $plugin = new MockPlugin(null, true);
        $this->assertTrue($plugin->isTemporary());
    }

    /**
     * @covers Guzzle\Http\Plugin\MockPlugin::count
     */
    public function testIsCountable()
    {
        $plugin = new MockPlugin();
        $plugin->addResponse(Response::fromMessage("HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n"));
        $this->assertEquals(1, count($plugin));
    }

    /**
     * @covers Guzzle\Http\Plugin\MockPlugin::clearQueue
     * @depends testIsCountable
     */
    public function testCanClearQueue()
    {
        $plugin = new MockPlugin();
        $plugin->addResponse(Response::fromMessage("HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n"));
        $plugin->clearQueue();
        $this->assertEquals(0, count($plugin));
    }

    /**
     * @covers Guzzle\Http\Plugin\MockPlugin::getQueue
     */
    public function testCanInspectQueue()
    {
        $plugin = new MockPlugin();
        $this->assertInternalType('array', $plugin->getQueue());
        $plugin->addResponse(Response::fromMessage("HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n"));
        $queue = $plugin->getQueue();
        $this->assertInternalType('array', $queue);
        $this->assertEquals(1, count($queue));
    }

    /**
     * @covers Guzzle\Http\Plugin\MockPlugin::getMockFile
     */
    public function testRetrievesResponsesFromFiles()
    {
        $response = MockPlugin::getMockFile(__DIR__ . '/../../TestData/mock_response');
        $this->assertInstanceOf('Guzzle\\Http\\Message\\Response', $response);
        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * @covers Guzzle\Http\Plugin\MockPlugin::getMockFile
     * @expectedException InvalidArgumentException
     */
    public function testThrowsExcpetionWhenResponseFileIsNotFound()
    {
        MockPlugin::getMockFile('missing/filename');
    }

    /**
     * @covers Guzzle\Http\Plugin\MockPlugin::addResponse
     * @expectedException InvalidArgumentException
     */
    public function testInvalidResponsesThrowAnException()
    {
        $p = new MockPlugin();
        $p->addResponse($this);
    }

    /**
     * @covers Guzzle\Http\Plugin\MockPlugin::addResponse
     */
    public function testAddsResponseObjectsToQueue()
    {
        $p = new MockPlugin();
        $response = Response::fromMessage("HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n");
        $p->addResponse($response);
        $this->assertEquals(array($response), $p->getQueue());
    }

    /**
     * @covers Guzzle\Http\Plugin\MockPlugin::addResponse
     */
    public function testAddsResponseFilesToQueue()
    {
        $p = new MockPlugin();
        $p->addResponse(__DIR__ . '/../../TestData/mock_response');
        $this->assertEquals(1, count($p));
    }

    /**
     * @covers Guzzle\Http\Plugin\MockPlugin::onRequestCreate
     * @covers Guzzle\Http\Plugin\MockPlugin::addResponse
     * @covers Guzzle\Http\Plugin\MockPlugin::dequeue
     * @depends testAddsResponseFilesToQueue
     */
    public function testAddsMockResponseToRequestFromClient()
    {
        $p = new MockPlugin();
        $response = MockPlugin::getMockFile(__DIR__ . '/../../TestData/mock_response');
        $p->addResponse($response);

        $client = new Client('http://localhost:123/');
        $client->getEventDispatcher()->addSubscriber($p, 9999);
        $request = $client->get();
        $request->send();

        $this->assertSame($response, $request->getResponse());
        $this->assertEquals(0, count($p));
    }

    /**
     * @covers Guzzle\Http\Plugin\MockPlugin::onRequestCreate
     * @depends testAddsResponseFilesToQueue
     */
    public function testUpdateIgnoresWhenEmpty()
    {
        $p = new MockPlugin();
        $p->onRequestCreate(new Event());
    }

    /**
     * @covers Guzzle\Http\Plugin\MockPlugin::onRequestCreate
     * @covers Guzzle\Http\Plugin\MockPlugin::dequeue
     * @depends testAddsMockResponseToRequestFromClient
     */
    public function testDetachesTemporaryWhenEmpty()
    {
        $p = new MockPlugin(null, true);
        $p->addResponse(MockPlugin::getMockFile(__DIR__ . '/../../TestData/mock_response'));
        $client = new Client('http://localhost:123/');
        $client->getEventDispatcher()->addSubscriber($p, 9999);
        $request = $client->get();
        $request->send();

        $this->assertFalse($this->hasSubscriber($client, $p));
    }

    /**
     * @covers Guzzle\Http\Plugin\MockPlugin::__construct
     */
    public function testLoadsResponsesFromConstructor()
    {
        $p = new MockPlugin(array(new Response(200)));
        $this->assertEquals(1, $p->count());
    }

    /**
     * @covers Guzzle\Http\Plugin\MockPlugin::getReceivedRequests
     * @covers Guzzle\Http\Plugin\MockPlugin::flush
     */
    public function testStoresMockedRequests()
    {
        $p = new MockPlugin(array(new Response(200), new Response(200)));
        $client = new Client('http://localhost:123/');
        $client->getEventDispatcher()->addSubscriber($p, 9999);

        $request1 = $client->get();
        $request1->send();
        $this->assertEquals(array($request1), $p->getReceivedRequests());

        $request2 = $client->get();
        $request2->send();
        $this->assertEquals(array($request1, $request2), $p->getReceivedRequests());

        $p->flush();
        $this->assertEquals(array(), $p->getReceivedRequests());
    }
}
