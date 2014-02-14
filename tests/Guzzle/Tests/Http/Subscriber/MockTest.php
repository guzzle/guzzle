<?php

namespace Guzzle\Tests\Http\Subscriber;

use Guzzle\Http\Adapter\Transaction;
use Guzzle\Http\Event\BeforeEvent;
use Guzzle\Http\Exception\RequestException;
use Guzzle\Http\Subscriber\Mock;
use Guzzle\Http\Client;
use Guzzle\Http\Message\Request;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Message\MessageFactory;
use Guzzle\Stream\Stream;

/**
 * @covers Guzzle\Http\Subscriber\Mock
 */
class MockTest extends \PHPUnit_Framework_TestCase
{
    public function testDescribesSubscribedEvents()
    {
        $this->assertInternalType('array', Mock::getSubscribedEvents());
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
        $m->onRequestBeforeSend($ev);
        $this->assertSame($response, $t->getResponse());
    }

    /**
     * @expectedException \OutOfBoundsException
     */
    public function testUpdateThrowsExceptionWhenEmpty()
    {
        $p = new Mock();
        $ev = new BeforeEvent(new Transaction(new Client(), new Request('GET', '/')));
        $p->onRequestBeforeSend($ev);
    }

    public function testReadsBodiesFromMockedRequests()
    {
        $m = new Mock([new Response(200)]);
        $client = new Client();
        $client->getEmitter()->addSubscriber($m);
        $body = Stream::factory('foo');
        $client->put('/', ['body' => $body]);
        $this->assertEquals(3, $body->tell());
    }

    public function testCanMockBadRequestExceptions()
    {
        $client = new Client();
        $request = $client->createRequest('GET', '/');
        $ex = new RequestException('foo', $request);
        $mock = new Mock([$ex]);
        $this->assertCount(1, $mock);
        $request->getEmitter()->addSubscriber($mock);

        try {
            $client->send($request);
            $this->fail('Did not dequeue an exception');
        } catch (RequestException $e) {
            $this->assertSame($e, $ex);
            $this->assertSame($request, $ex->getRequest());
        }
    }
}
