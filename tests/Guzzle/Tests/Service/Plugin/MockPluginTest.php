<?php

namespace Guzzle\Tests\Service\Plugin;

use Guzzle\Http\EntityBody;
use Guzzle\Http\Message\RequestFactory;
use Guzzle\Http\Message\Response;
use Guzzle\Service\Plugin\MockPlugin;
use Guzzle\Service\Client;
use Guzzle\Tests\Common\Mock\MockSubject;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class MockPluginTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Plugin\MockPlugin::__construct
     * @covers Guzzle\Service\Plugin\MockPlugin::isTemporary
     */
    public function testCanBeTemporary()
    {
        $plugin = new MockPlugin();
        $this->assertFalse($plugin->isTemporary());
        $plugin = new MockPlugin(null, true);
        $this->assertTrue($plugin->isTemporary());
    }
    
    /**
     * @covers Guzzle\Service\Plugin\MockPlugin::count
     */
    public function testIsCountable()
    {
        $plugin = new MockPlugin();
        $plugin->addResponse(Response::factory("HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n"));
        $this->assertEquals(1, count($plugin));
    }

    /**
     * @covers Guzzle\Service\Plugin\MockPlugin::clearQueue
     * @depends testIsCountable
     */
    public function testCanClearQueue()
    {
        $plugin = new MockPlugin();
        $plugin->addResponse(Response::factory("HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n"));
        $plugin->clearQueue();
        $this->assertEquals(0, count($plugin));
    }

    /**
     * @covers Guzzle\Service\Plugin\MockPlugin::getQueue
     */
    public function testCanInspectQueue()
    {
        $plugin = new MockPlugin();
        $this->assertInternalType('array', $plugin->getQueue());
        $plugin->addResponse(Response::factory("HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n"));
        $queue = $plugin->getQueue();
        $this->assertInternalType('array', $queue);
        $this->assertEquals(1, count($queue));
    }

    /**
     * @covers Guzzle\Service\Plugin\MockPlugin::getMockFile
     */
    public function testRetrievesResponsesFromFiles()
    {
        $response = MockPlugin::getMockFile(__DIR__ . '/../../TestData/mock_response');
        $this->assertInstanceOf('Guzzle\\Http\\Message\\Response', $response);
        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * @covers Guzzle\Service\Plugin\MockPlugin::getMockFile
     * @expectedException InvalidArgumentException
     */
    public function testThrowsExcpetionWhenResponseFileIsNotFound()
    {
        MockPlugin::getMockFile('missing/filename');
    }

    /**
     * @covers Guzzle\Service\Plugin\MockPlugin::addResponse
     * @expectedException InvalidArgumentException
     */
    public function testInvalidResponsesThrowAnException()
    {
        $p = new MockPlugin();
        $p->addResponse($this);
    }

    /**
     * @covers Guzzle\Service\Plugin\MockPlugin::addResponse
     */
    public function testAddsResponseObjectsToQueue()
    {
        $p = new MockPlugin();
        $response = Response::factory("HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n");
        $p->addResponse($response);
        $this->assertEquals(array($response), $p->getQueue());
    }

    /**
     * @covers Guzzle\Service\Plugin\MockPlugin::addResponse
     */
    public function testAddsResponseFilesToQueue()
    {
        $p = new MockPlugin();
        $p->addResponse(__DIR__ . '/../../TestData/mock_response');
        $this->assertEquals(1, count($p));
    }

    /**
     * @covers Guzzle\Service\Plugin\MockPlugin::update
     * @covers Guzzle\Service\Plugin\MockPlugin::addResponse
     * @covers Guzzle\Service\Plugin\MockPlugin::dequeue
     * @depends testAddsResponseFilesToQueue
     */
    public function testAddsMockResponseToRequestFromClient()
    {
        $p = new MockPlugin();
        $response = MockPlugin::getMockFile(__DIR__ . '/../../TestData/mock_response');
        $p->addResponse($response);

        $client = new Client('http://localhost:123/');
        $client->getEventManager()->attach($p, 9999);
        $request = $client->get();
        $request->send();

        $this->assertSame($response, $request->getResponse());
        $this->assertEquals(0, count($p));
    }

    /**
     * @covers Guzzle\Service\Plugin\MockPlugin::update
     * @depends testAddsResponseFilesToQueue
     */
    public function testUpdateIgnoresWhenEmpty()
    {
        $p = new MockPlugin();
        $p->update(new MockSubject(), 'request.create');
    }

    /**
     * @covers Guzzle\Service\Plugin\MockPlugin::update
     * @depends testAddsResponseFilesToQueue
     */
    public function testUpdateIgnoresOtherEvents()
    {
        $p = new MockPlugin();
        $p->addResponse(MockPlugin::getMockFile(__DIR__ . '/../../TestData/mock_response'));
        $p->update(new MockSubject(), 'foobar');
    }

    /**
     * @covers Guzzle\Service\Plugin\MockPlugin::update
     * @covers Guzzle\Service\Plugin\MockPlugin::dequeue
     * @depends testAddsMockResponseToRequestFromClient
     */
    public function testDetachesTemporaryWhenEmpty()
    {
        $p = new MockPlugin(null, true);
        $p->addResponse(MockPlugin::getMockFile(__DIR__ . '/../../TestData/mock_response'));
        $client = new Client('http://localhost:123/');
        $client->getEventManager()->attach($p, 9999);
        $request = $client->get();
        $request->send();

        $this->assertFalse($client->getEventManager()->hasObserver($p));
    }

    /**
     * @covers Guzzle\Service\Plugin\MockPlugin::__construct
     */
    public function testLoadsResponsesFromConstructor()
    {
        $p = new MockPlugin(array(new Response(200)));
        $this->assertEquals(1, $p->count());
    }
}