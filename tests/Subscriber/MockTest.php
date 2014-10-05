<?php

namespace GuzzleHttp\Tests\Subscriber;

use GuzzleHttp\Adapter\Transaction;
use GuzzleHttp\Client;
use GuzzleHttp\Event\BeforeEvent;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Message\MessageFactory;
use GuzzleHttp\Message\Request;
use GuzzleHttp\Message\Response;
use GuzzleHttp\Stream\Stream;
use GuzzleHttp\Subscriber\Mock;

/**
 * @covers GuzzleHttp\Subscriber\Mock
 */
class MockTest extends \PHPUnit_Framework_TestCase
{
    public function testDescribesSubscribedEvents()
    {
        $mock = new Mock();
        $this->assertInternalType('array', $mock->getEvents());
    }

    public function testIsCountable()
    {
        $plugin = new Mock();
        $plugin->addResponse((new MessageFactory())->fromMessage("HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n"));
        $this->assertEquals(1, count($plugin));
    }

    public function testCanClearQueue()
    {
        $plugin = new Mock();
        $plugin->addResponse((new MessageFactory())->fromMessage("HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n"));
        $plugin->clearQueue();
        $this->assertEquals(0, count($plugin));
    }

    public function testRetrievesResponsesFromFiles()
    {
        $tmp = tempnam('/tmp', 'tfile');
        file_put_contents($tmp, "HTTP/1.1 201 OK\r\nContent-Length: 0\r\n\r\n");
        $plugin = new Mock();
        $plugin->addResponse($tmp);
        unlink($tmp);
        $this->assertEquals(1, count($plugin));
        $q = $this->readAttribute($plugin, 'queue');
        $this->assertEquals(201, $q[0]->getStatusCode());
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testThrowsExceptionWhenInvalidResponse()
    {
        (new Mock())->addResponse(false);
    }

    public function testAddsMockResponseToRequestFromClient()
    {
        $response = new Response(200);
        $t = new Transaction(new Client(), new Request('GET', '/'));
        $m = new Mock([$response]);
        $ev = new BeforeEvent($t);
        $m->onBefore($ev);
        $this->assertSame($response, $t->getResponse());
    }

    /**
     * @expectedException \OutOfBoundsException
     */
    public function testUpdateThrowsExceptionWhenEmpty()
    {
        $p = new Mock();
        $ev = new BeforeEvent(new Transaction(new Client(), new Request('GET', '/')));
        $p->onBefore($ev);
    }

    public function testReadsBodiesFromMockedRequests()
    {
        $m = new Mock([new Response(200)]);
        $client = new Client(['base_url' => 'http://test.com']);
        $client->getEmitter()->attach($m);
        $body = Stream::factory('foo');
        $client->put('/', ['body' => $body]);
        $this->assertEquals(3, $body->tell());
    }

    public function testCanMockBadRequestExceptions()
    {
        $client = new Client(['base_url' => 'http://test.com']);
        $request = $client->createRequest('GET', '/');
        $ex = new RequestException('foo', $request);
        $mock = new Mock([$ex]);
        $this->assertCount(1, $mock);
        $request->getEmitter()->attach($mock);

        try {
            $client->send($request);
            $this->fail('Did not dequeue an exception');
        } catch (RequestException $e) {
            $this->assertSame($e, $ex);
            $this->assertSame($request, $ex->getRequest());
        }
    }
}
