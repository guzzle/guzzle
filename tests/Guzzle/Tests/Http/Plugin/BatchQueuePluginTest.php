<?php

namespace Guzzle\Tests\Http\Plugin;

use Guzzle\Http\Plugin\MockPlugin;
use Guzzle\Http\Plugin\BatchQueuePlugin;
use Guzzle\Common\Event;
use Guzzle\Http\Client;
use Guzzle\Http\Message\Response;

class BatchQueuePluginTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Http\Plugin\BatchQueuePlugin::getSubscribedEvents
     */
    public function testSubscribesToEvents()
    {
        $events = BatchQueuePlugin::getSubscribedEvents();
        $this->assertArrayHasKey('flush', $events);
        $this->assertArrayHasKey('client.create_request', $events);
        $this->assertArrayHasKey('request.before_send', $events);
    }

    /**
     * @covers Guzzle\Http\Plugin\BatchQueuePlugin::count
     * @covers Guzzle\Http\Plugin\BatchQueuePlugin::onRequestCreate
     */
    public function testAddsRequestToQueue()
    {
        $plugin = new BatchQueuePlugin();
        $this->assertEquals(0, count($plugin));

        $client = new Client('http://test.com/');
        $request = $client->get('/');

        $event = new Event(array(
            'request' => $request
        ));
        $plugin->onRequestCreate($event);

        $this->assertEquals(1, count($plugin));
    }

    /**
     * @covers Guzzle\Http\Plugin\BatchQueuePlugin::removeRequest
     * @covers Guzzle\Http\Plugin\BatchQueuePlugin::onRequestBeforeSend
     */
    public function testRemovesRequestsWhenTheyAreSentOutOfContext()
    {
        $plugin = new BatchQueuePlugin();
        $client = new Client('http://test.com/');

        // Create an event to use for our notifications
        $event = new Event(array(
            'request' => $client->get('/')
        ));

        // Add a request to the queue
        $plugin->onRequestCreate($event);
        $this->assertEquals(1, count($plugin));

        // Fake that the request is being sent outside of the queue
        $plugin->onRequestBeforeSend($event);
        // Ensure that the request is no longer queued
        $this->assertEquals(0, count($plugin));
    }

    /**
     * @covers Guzzle\Http\Plugin\BatchQueuePlugin::flush
     */
    public function testFlushSendsRequestsInQueue()
    {
        $this->getServer()->flush();
        $plugin = new BatchQueuePlugin();
        $client = new Client($this->getServer()->getUrl());

        // Create some test requests
        $requests = array(
            $client->get('/'),
            $client->get('/')
        );

        // Add the requests to the batch queue
        foreach ($requests as $request) {
            $plugin->onRequestCreate(new Event(array(
                'request' => $request
            )));
            $responses[] = new Response(200);
        }

        // Queue the test responses on node.js
        $this->getServer()->enqueue($responses);

        // Explicitly call flush to send the queued requests
        $plugin->flush();
        $received = $this->getServer()->getReceivedRequests();
        $this->assertEquals(count($requests), count($received));
        $this->assertEquals(0, count($plugin));
    }

    /**
     * @covers Guzzle\Http\Plugin\BatchQueuePlugin::__construct
     * @covers Guzzle\Http\Plugin\BatchQueuePlugin::onRequestCreate
     */
    public function testImplicitlyFlushesRequests()
    {
        $this->getServer()->flush();
        $plugin = new BatchQueuePlugin(2);
        $client = new Client($this->getServer()->getUrl());

        $this->getServer()->enqueue(array(
            new Response(200),
            new Response(200),
            new Response(200)
        ));

        $plugin->onRequestCreate(new Event(array(
            'request' => $client->get('/1')
        )));

        $plugin->onRequestCreate(new Event(array(
            'request' => $client->get('/2')
        )));

        $this->assertEquals(0, count($plugin));
        $received = $this->getServer()->getReceivedRequests();
        $this->assertEquals(2, count($received));

        $plugin->onRequestCreate(new Event(array(
            'request' => $client->get('/3')
        )));

        $this->assertEquals(1, count($plugin));
    }

    /**
     * @covers Guzzle\Http\Plugin\BatchQueuePlugin::onRequestCreate
     * @covers Guzzle\Http\Plugin\BatchQueuePlugin::onRequestBeforeSend
     */
    public function testWorksUsingEvents()
    {
        // Queue up some test responses
        $this->getServer()->flush();
        $this->getServer()->enqueue(array(
            new Response(200),
            new Response(200),
            new Response(200)
        ));

        $plugin = new BatchQueuePlugin(2);
        $client = new Client($this->getServer()->getUrl());
        $client->getEventDispatcher()->addSubscriber($plugin);

        $client->get('/');
        $client->get('/');
        // Ensure that the requests were sent implicitly
        $this->assertEquals(0, count($plugin));
        $this->assertEquals(2, count($this->getServer()->getReceivedRequests()));

        // Add a single request and ensure that it is in queue and not sent
        $client->get('/');
        $this->assertEquals(1, count($plugin));
        $this->assertEquals(2, count($this->getServer()->getReceivedRequests()));

        // Explicitly flush the queued requests
        $client->dispatch('flush');
        $this->assertEquals(0, count($plugin));
        $this->assertEquals(3, count($this->getServer()->getReceivedRequests()));
    }

    /**
     * @covers Guzzle\Http\Plugin\BatchQueuePlugin::flush
     */
    public function testWorksWithMockResponses()
    {
        $this->getServer()->flush();
        $mock = new MockPlugin(array(
            new Response(200),
            new Response(201)
        ));

        $plugin = new BatchQueuePlugin();
        $client = new Client($this->getServer()->getUrl());
        $client->getEventDispatcher()->addSubscriber($plugin);
        $client->getEventDispatcher()->addSubscriber($mock);

        $request1 = $client->get('/');
        $request2 = $client->get('/');
        $plugin->flush();
        $this->assertEquals(0, count($plugin));
        $this->assertEquals(0, count($this->getServer()->getReceivedRequests()));

        $this->assertEquals(200, $request1->getResponse()->getStatusCode());
        $this->assertEquals(201, $request2->getResponse()->getStatusCode());
    }
}
