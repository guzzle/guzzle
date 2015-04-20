<?php
namespace GuzzleHttp\Tests\Subscriber;

use GuzzleHttp\Client;
use GuzzleHttp\Subscriber\Mock;
use GuzzleHttp\Message\FutureResponse;
use GuzzleHttp\Transaction;
use GuzzleHttp\Event\BeforeEvent;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Message\MessageFactory;
use GuzzleHttp\Message\Request;
use GuzzleHttp\Message\Response;
use GuzzleHttp\Stream\Stream;
use React\Promise\Deferred;

/**
 * @covers GuzzleHttp\Subscriber\Mock
 */
class MockTest extends \PHPUnit_Framework_TestCase
{
    public static function createFuture(
        callable $wait,
        callable $cancel = null
    ) {
        $deferred = new Deferred();
        return new FutureResponse(
            $deferred->promise(),
            function () use ($deferred, $wait) {
                $deferred->resolve($wait());
            },
            $cancel
        );
    }

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
        $this->assertSame($response, $t->response);
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

    public function testCanMockFutureResponses()
    {
        $client = new Client(['base_url' => 'http://test.com']);
        $request = $client->createRequest('GET', '/', ['future' => true]);
        $response = new Response(200);
        $future = self::createFuture(function () use ($response) {
            return $response;
        });
        $mock = new Mock([$future]);
        $this->assertCount(1, $mock);
        $request->getEmitter()->attach($mock);
        $res = $client->send($request);
        $this->assertSame($future, $res);
        $this->assertFalse($this->readAttribute($res, 'isRealized'));
        $this->assertSame($response, $res->wait());
    }

    public function testCanMockExceptionFutureResponses()
    {
        $client = new Client(['base_url' => 'http://test.com']);
        $request = $client->createRequest('GET', '/', ['future' => true]);
        $future = self::createFuture(function () use ($request) {
            throw new RequestException('foo', $request);
        });

        $mock = new Mock([$future]);
        $request->getEmitter()->attach($mock);
        $response = $client->send($request);
        $this->assertSame($future, $response);
        $this->assertFalse($this->readAttribute($response, 'isRealized'));

        try {
            $response->wait();
            $this->fail('Did not throw');
        } catch (RequestException $e) {
            $this->assertContains('foo', $e->getMessage());
        }
    }

    public function testSaveToFile()
    {
        $filename = sys_get_temp_dir().'/mock_test_'.uniqid();
        $file = tmpfile();
        $stream = new Stream(tmpfile());

        $m = new Mock([
        	new Response(200, [], Stream::factory('TEST FILENAME')),
        	new Response(200, [], Stream::factory('TEST FILE')),
        	new Response(200, [], Stream::factory('TEST STREAM')),
        ]);

        $client = new Client();
        $client->getEmitter()->attach($m);

        $client->get('/', ['save_to' => $filename]);
        $client->get('/', ['save_to' => $file]);
        $client->get('/', ['save_to' => $stream]);

        $this->assertFileExists($filename);
        $this->assertEquals('TEST FILENAME', file_get_contents($filename));

        $meta = stream_get_meta_data($file);

        $this->assertFileExists($meta['uri']);
        $this->assertEquals('TEST FILE', file_get_contents($meta['uri']));

        $this->assertFileExists($stream->getMetadata('uri'));
        $this->assertEquals('TEST STREAM', file_get_contents($stream->getMetadata('uri')));

        unlink($filename);
    }

    public function testCanMockFailedFutureResponses()
    {
        $client = new Client(['base_url' => 'http://test.com']);
        $request = $client->createRequest('GET', '/', ['future' => true]);

        // The first mock will be a mocked future response.
        $future = self::createFuture(function () use ($client) {
            // When dereferenced, we will set a mocked response and send
            // another request.
            $client->get('http://httpbin.org', ['events' => [
                'before' => function (BeforeEvent $e) {
                    $e->intercept(new Response(404));
                }
            ]]);
        });

        $mock = new Mock([$future]);
        $request->getEmitter()->attach($mock);
        $response = $client->send($request);
        $this->assertSame($future, $response);
        $this->assertFalse($this->readAttribute($response, 'isRealized'));

        try {
            $response->wait();
            $this->fail('Did not throw');
        } catch (RequestException $e) {
            $this->assertEquals(404, $e->getResponse()->getStatusCode());
        }
    }
}
