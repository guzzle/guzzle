<?php

namespace Guzzle\Tests\Http\Curl;

use Guzzle\Common\Event;
use Guzzle\Http\Exception\MultiTransferException;
use Guzzle\Common\Collection;
use Guzzle\Http\Client;
use Guzzle\Http\Message\Request;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Message\RequestFactory;
use Guzzle\Http\Curl\CurlMulti;
use Guzzle\Http\Exception\CurlException;
use Guzzle\Tests\Mock\MockMulti;

/**
 * @group server
 * @covers Guzzle\Http\Curl\CurlMulti
 */
class CurlMultiTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @var \Guzzle\Http\Curl\CurlMulti
     */
    private $multi;

    /**
     * @var \Guzzle\Common\Collection
     */
    private $updates;

    private $mock;

    /**
     * Prepares the environment before running a test.
     */
    protected function setUp()
    {
        parent::setUp();
        $this->updates = new Collection();
        $this->multi = new MockMulti();
        $this->mock = $this->getWildcardObserver($this->multi);
    }

    /**
     * @covers Guzzle\Http\Curl\CurlMulti::__construct
     * @covers Guzzle\Http\Curl\CurlMulti::__destruct
     */
    public function testConstructorCreateMultiHandle()
    {
        $this->assertInternalType('resource', $this->multi->getHandle());
        $this->assertEquals('curl_multi', get_resource_type($this->multi->getHandle()));
    }

    /**
     * @covers Guzzle\Http\Curl\CurlMulti::__destruct
     */
    public function testDestructorClosesMultiHandle()
    {
        $handle = $this->multi->getHandle();
        $this->multi->__destruct();
        $this->assertFalse(is_resource($handle));
    }

    /**
     * @covers Guzzle\Http\Curl\curlMulti::add
     * @covers Guzzle\Http\Curl\curlMulti::all
     * @covers Guzzle\Http\Curl\curlMulti::count
     */
    public function testRequestsCanBeAddedAndCounted()
    {
        $multi = new CurlMulti();
        $mock = $this->getWildcardObserver($multi);
        $request1 = new Request('GET', 'http://www.google.com/');
        $multi->add($request1);
        $this->assertEquals(array($request1), $multi->all());

        $request2 = new Request('POST', 'http://www.google.com/');
        $multi->add($request2);
        $this->assertEquals(array($request1, $request2), $multi->all());
        $this->assertEquals(2, count($multi));

        $this->assertTrue($mock->has(CurlMulti::ADD_REQUEST));
        $this->assertFalse($mock->has(CurlMulti::REMOVE_REQUEST));
        $this->assertFalse($mock->has(CurlMulti::COMPLETE));
    }

    /**
     * @covers Guzzle\Http\Curl\CurlMulti::remove
     * @covers Guzzle\Http\Curl\CurlMulti::all
     */
    public function testRequestsCanBeRemoved()
    {
        $request1 = new Request('GET', 'http://www.google.com/');
        $this->multi->add($request1);
        $request2 = new Request('PUT', 'http://www.google.com/');
        $this->multi->add($request2);
        $this->assertEquals(array($request1, $request2), $this->multi->all());
        $this->assertSame($this->multi, $this->multi->remove($request1));
        $this->assertEquals(array($request2), $this->multi->all());
    }

    /**
     * @covers Guzzle\Http\Curl\CurlMulti::reset
     */
    public function testsResetRemovesRequestsAndResetsState()
    {
        $request1 = new Request('GET', 'http://www.google.com/');
        $this->multi->add($request1);
        $this->multi->reset();
        $this->assertEquals(array(), $this->multi->all());
        $this->assertEquals('idle', $this->multi->getState());
    }

    /**
     * @covers Guzzle\Http\Curl\CurlMulti::send
     * @covers Guzzle\Http\Curl\CurlMulti::getState
     */
    public function testSendsRequestsInParallel()
    {
        $this->getServer()->enqueue("HTTP/1.1 200 OK\r\nContent-Length: 4\r\n\r\nBody");
        $this->assertEquals('idle', $this->multi->getState());
        $request = new Request('GET', $this->getServer()->getUrl());
        $this->multi->add($request);
        $this->multi->send();

        $this->assertEquals('idle', $this->multi->getState());

        $this->assertTrue($this->mock->has(CurlMulti::ADD_REQUEST));
        $this->assertTrue($this->mock->has(CurlMulti::COMPLETE));

        $this->assertEquals('Body', $request->getResponse()->getBody()->__toString());

        // Sending it again will not do anything because there are no requests
        $this->multi->send();
    }

    /**
     * @covers Guzzle\Http\Curl\CurlMulti::send
     */
    public function testSendsRequestsThroughCurl()
    {
        $this->getServer()->enqueue(array(
            "HTTP/1.1 204 No content\r\n" .
            "Content-Length: 0\r\n" .
            "Server: Jetty(6.1.3)\r\n\r\n",

            "HTTP/1.1 200 OK\r\n" .
            "Content-Type: text/html; charset=utf-8\r\n" .
            "Content-Length: 4\r\n" .
            "Server: Jetty(6.1.3)\r\n\r\n" .
            "data"
        ));

        $request1 = new Request('GET', $this->getServer()->getUrl());
        $mock1 = $this->getWildcardObserver($request1);
        $request2 = new Request('GET', $this->getServer()->getUrl());
        $mock2 = $this->getWildcardObserver($request2);

        $this->multi->add($request1);
        $this->multi->add($request2);
        $this->multi->send();

        $response1 = $request1->getResponse();
        $response2 = $request2->getResponse();

        $this->assertInstanceOf('Guzzle\\Http\\Message\\Response', $response1);
        $this->assertInstanceOf('Guzzle\\Http\\Message\\Response', $response2);

        $this->assertTrue($response1->getBody(true) == 'data' || $response2->getBody(true) == 'data');
        $this->assertTrue($response1->getBody(true) == '' || $response2->getBody(true) == '');
        $this->assertTrue($response1->getStatusCode() == '204' || $response2->getStatusCode() == '204');
        $this->assertNotEquals((string) $response1, (string) $response2);

        $this->assertTrue($mock1->has('request.before_send'));
        $this->assertTrue($mock2->has('request.before_send'));
    }

    /**
     * @covers Guzzle\Http\Curl\CurlMulti::send
     * @covers Guzzle\Http\Exception\MultiTransferException
     */
    public function testSendsThroughCurlAndAggregatesRequestExceptions()
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
        $this->multi->add($request1);
        $this->multi->add($request2);
        $this->multi->add($request3);

        try {
            $this->multi->send();
            $this->fail('MultiTransferException not thrown when aggregating request exceptions');
        } catch (MultiTransferException $e) {

            $this->assertInstanceOf('ArrayIterator', $e->getIterator());
            $this->assertEquals(1, count($e));
            $exceptions = $e->getIterator();

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
            foreach ($e as $except) {
                $this->assertEquals($failed, $except->getResponse());
            }

            $this->assertEquals(1, count($e->getFailedRequests()));
            $this->assertEquals(2, count($e->getSuccessfulRequests()));
            $this->assertEquals(3, count($e->getAllRequests()));
        }
    }

    /**
     * @covers Guzzle\Http\Curl\CurlMulti::send
     * @covers Guzzle\Http\Curl\CurlMulti::processResponse
     * @covers Guzzle\Http\Exception\CurlException
     */
    public function testCurlErrorsAreCaught()
    {
        $this->getServer()->enqueue("HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n");

        try {
            $request = RequestFactory::getInstance()->create('GET', 'http://127.0.0.1:9876/');
            $request->setClient(new Client());
            $request->getCurlOptions()->set(CURLOPT_FRESH_CONNECT, true);
            $request->getCurlOptions()->set(CURLOPT_FORBID_REUSE, true);
            $request->getCurlOptions()->set(CURLOPT_CONNECTTIMEOUT_MS, 5);
            $request->send();
            $this->fail('CurlException not thrown');
        } catch (CurlException $e) {
            $m = $e->getMessage();
            $this->assertContains('[curl] ', $m);
            $this->assertContains('[url] http://127.0.0.1:9876/', $m);
            $this->assertInternalType('array', $e->getCurlInfo());
        }
    }

    /**
     * @covers Guzzle\Http\Curl\CurlMulti
     */
    public function testRemovesQueuedRequests()
    {
        $request = RequestFactory::getInstance()->create('GET', 'http://127.0.0.1:9876/');
        $request->setClient(new Client());
        $request->setResponse(new Response(200), true);
        $this->multi->add($request);
        $this->multi->send();
        $this->assertTrue($this->mock->has(CurlMulti::ADD_REQUEST));
        $this->assertTrue($this->mock->has(CurlMulti::COMPLETE) !== false);
    }

    /**
     * @covers Guzzle\Http\Curl\CurlMulti
     */
    public function testRemovesQueuedRequestsAddedInTransit()
    {
        $this->getServer()->flush();
        $this->getServer()->enqueue(array(
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n"
        ));
        $client = new Client($this->getServer()->getUrl());
        $r = $client->get();
        $r->getEventDispatcher()->addListener('request.receive.status_line', function(Event $event) use ($client) {
            // Create a request using a queued response
            $request = $client->get()->setResponse(new Response(200), true);
            $request->send();
        });

        $r->send();
        $this->assertEquals(1, count($this->getServer()->getReceivedRequests(false)));
    }

    /**
     * @covers Guzzle\Http\Curl\CurlMulti
     */
    public function testProperlyBlocksBasedOnRequestsInScope()
    {
        $this->getServer()->flush();
        $this->getServer()->enqueue(array(
            "HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\ntest1",
            "HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\ntest2",
            "HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\ntest3",
            "HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\ntest4",
            "HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\ntest5",
            "HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\ntest6",
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n"
        ));

        $client = new Client($this->getServer()->getUrl());

        $requests = array(
            $client->get(),
            $client->get()
        );

        $sendHeadFunction = function($event) use ($client) {
            $client->head()->send();
        };

        // Sends 2 new requests in the middle of a CurlMulti loop while other requests
        // are completing.  This causes the scope of the multi handle to go up.
        $callback = function(Event $event) use ($client, $sendHeadFunction) {
            $client->getConfig()->set('called', $client->getConfig('called') + 1);
            if ($client->getConfig('called') <= 2) {
                $request = $client->get();
                $request->getEventDispatcher()->addListener('request.complete', $sendHeadFunction);
                $request->send();
            }
        };

        $requests[0]->getEventDispatcher()->addListener('request.complete', $callback);

        $client->send($requests);

        $this->assertEquals(4, count($this->getServer()->getReceivedRequests(false)));
    }

    /**
     * @covers Guzzle\Http\Curl\CurlMulti
     * @expectedException RuntimeException
     * @expectedExceptionMessage Testing!
     */
    public function testCatchesExceptionsBeforeSendingCurlMulti()
    {
        $client = new Client($this->getServer()->getUrl());
        $multi = new CurlMulti();
        $client->setCurlMulti($multi);
        $multi->getEventDispatcher()->addListener(CurlMulti::BEFORE_SEND, function() {
            throw new \RuntimeException('Testing!');
        });
        $client->get()->send();
    }

    /**
     * @covers Guzzle\Http\Curl\CurlMulti
     * @covers Guzzle\Http\Curl\CurlMulti::removeErroredRequest
     * @expectedException Guzzle\Common\Exception\ExceptionCollection
     * @expectedExceptionMessage Thrown before sending!
     */
    public function testCatchesExceptionsBeforeSendingRequests()
    {
        $client = new Client($this->getServer()->getUrl());
        $request = $client->get();
        $request->getEventDispatcher()->addListener('request.before_send', function() {
            throw new \RuntimeException('Thrown before sending!');
        });
        $client->send(array($request));
    }

    /**
     * @covers Guzzle\Http\Curl\CurlMulti
     * @covers Guzzle\Http\Curl\CurlMulti::removeErroredRequest
     * @expectedException Guzzle\Http\Exception\BadResponseException
     */
    public function testCatchesExceptionsWhenRemovingQueuedRequests()
    {
        $this->getServer()->enqueue("HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n");
        $client = new Client($this->getServer()->getUrl());
        $r = $client->get();
        $r->getEventDispatcher()->addListener('request.sent', function() use ($client) {
            // Create a request using a queued response
            $client->get()->setResponse(new Response(404), true)->send();
        });
        $r->send();
    }

    /**
     * @covers Guzzle\Http\Curl\CurlMulti
     * @covers Guzzle\Http\Curl\CurlMulti::removeErroredRequest
     * @expectedException Guzzle\Http\Exception\BadResponseException
     */
    public function testCatchesExceptionsWhenRemovingQueuedRequestsBeforeSending()
    {
        $this->getServer()->enqueue("HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n");
        $client = new Client($this->getServer()->getUrl());
        $r = $client->get();
        $r->getEventDispatcher()->addListener('request.before_send', function() use ($client) {
            // Create a request using a queued response
            $client->get()->setResponse(new Response(404), true)->send();
        });
        $r->send();
    }

    /**
     * @covers Guzzle\Http\Curl\CurlMulti::send
     * @covers Guzzle\Http\Curl\CurlMulti::removeErroredRequest
     * @expectedException Guzzle\Common\Exception\ExceptionCollection
     * @expectedExceptionMessage test
     */
    public function testCatchesRandomExceptionsThrownDuringPerform()
    {
        $client = new Client($this->getServer()->getUrl());
        $multi = $this->getMock('Guzzle\\Http\\Curl\\CurlMulti', array('perform'));
        $multi->expects($this->once())
              ->method('perform')
              ->will($this->throwException(new \Exception('test')));
        $multi->add($client->get());
        $multi->send();
    }

    /**
     * @covers Guzzle\Http\Curl\CurlMulti::send
     */
    public function testDoesNotSendRequestsDecliningToBeSent()
    {
        if (!defined('CURLOPT_TIMEOUT_MS')) {
            $this->markTestSkipped('Update curl');
        }

        // Create a client that is bound to fail connecting
        $client = new Client('http://localhost:123', array(
            'curl.CURLOPT_PORT'              => 123,
            'curl.CURLOPT_CONNECTTIMEOUT_MS' => 1,
        ));

        $request = $client->get();
        $multi = new CurlMulti();
        $multi->add($request);

        // Listen for request exceptions, and when they occur, first change the
        // state of the request back to transferring, and then just allow it to
        // exception out
        $request->getEventDispatcher()->addListener('request.exception', function(Event $event) use ($multi) {
            $retries = $event['request']->getParams()->get('retries');
            // Allow the first failure to retry
            if ($retries == 0) {
                $event['request']->setState('transfer');
                $event['request']->getParams()->set('retries', 1);
                // Remove the request to try again
                $multi->remove($event['request']);
                $multi->add($event['request'], true);
            }
        });

        try {
            $multi->send();
            $this->fail('Did not throw an exception at all!?!');
        } catch (\Exception $e) {
            $this->assertEquals(1, $request->getParams()->get('retries'));
        }
    }

    /**
     * @covers Guzzle\Http\Curl\CurlMulti::send
     */
    public function testDoesNotThrowExceptionsWhenRequestsRecoverWithRetry()
    {
        $this->getServer()->flush();
        $client = new Client($this->getServer()->getUrl());
        $request = $client->get();
        $request->getEventDispatcher()->addListener('request.before_send', function(Event $event) {
            $event['request']->setResponse(new Response(200));
        });

        $multi = new CurlMulti();
        $multi->add($request);
        $multi->send();
        $this->assertEquals(0, count($this->getServer()->getReceivedRequests(false)));
    }

    /**
     * @covers Guzzle\Http\Curl\CurlMulti::send
     */
    public function testDoesNotThrowExceptionsWhenRequestsRecoverWithSuccess()
    {
        // Attempt a port that 99.9% is not listening
        $client = new Client('http://localhost:123');
        $request = $client->get();
        // Ensure it times out quickly if needed
        $request->getCurlOptions()->set(CURLOPT_TIMEOUT_MS, 1)->set(CURLOPT_CONNECTTIMEOUT_MS, 1);

        $request->getEventDispatcher()->addListener('request.exception', function(Event $event) use (&$count) {
            $event['request']->setResponse(new Response(200));
        });

        $multi = new CurlMulti();
        $multi->add($request);
        $multi->send();

        // Ensure that the exception was caught, and the response was set manually
        $this->assertEquals(200, $request->getResponse()->getStatusCode());
    }

    /**
     * @covers Guzzle\Http\Curl\CurlMulti::reset
     */
    public function testHardResetReopensMultiHandle()
    {
        $this->getServer()->enqueue(array(
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n"
        ));

        $stream = fopen('php://temp', 'w+');
        $client = new Client($this->getServer()->getUrl());
        $client->getConfig()->set('curl.CURLOPT_VERBOSE', true)->set('curl.CURLOPT_STDERR', $stream);

        $request = $client->get();
        $multi = new CurlMulti();
        $multi->add($request);
        $multi->send();
        $multi->reset(true);
        $multi->add($request);
        $multi->send();

        rewind($stream);
        $this->assertNotContains('Re-using existing connection', stream_get_contents($stream));
    }

    /**
     * @covers Guzzle\Http\Curl\CurlMulti::checkCurlResult
     */
    public function testThrowsMeaningfulExceptionsForCurlMultiErrors()
    {
        $multi = new CurlMulti();

        // Set the state of the multi object to sending to trigger the exception
        $reflector = new \ReflectionMethod('Guzzle\Http\Curl\CurlMulti', 'checkCurlResult');
        $reflector->setAccessible(true);

        // Successful
        $reflector->invoke($multi, 0);

        // Known error
        try {
            $reflector->invoke($multi, CURLM_BAD_HANDLE);
            $this->fail('Expected an exception here');
        } catch (CurlException $e) {
            $this->assertContains('The passed-in handle is not a valid CURLM handle.', $e->getMessage());
            $this->assertContains('CURLM_BAD_HANDLE', $e->getMessage());
            $this->assertContains(strval(CURLM_BAD_HANDLE), $e->getMessage());
        }

        // Unknown error
        try {
            $reflector->invoke($multi, 255);
            $this->fail('Expected an exception here');
        } catch (CurlException $e) {
            $this->assertEquals('Unexpected cURL error: 255', $e->getMessage());
        }
    }

    /**
     * @covers Guzzle\Http\Curl\curlMulti::add
     */
    public function testAddsAsyncRequestsNormallyWhenNotSending()
    {
        $multi = new CurlMulti();
        $request = new Request('GET', 'http://www.google.com/');
        $multi->add($request, true);

        // Ensure that the request was added at the correct next scope
        $requests = $this->readAttribute($multi, 'requests');
        $this->assertEquals(array($request), $requests[0]);
    }

    /**
     * @covers Guzzle\Http\Curl\CurlMulti::send
     * @covers Guzzle\Http\Message\EntityEnclosingRequest::setState
     */
    public function testRequestBeforeSendIncludesContentLengthHeaderIfEmptyBody()
    {
        $this->getServer()->enqueue("HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n");
        $request = new Request('PUT', $this->getServer()->getUrl());
        $that = $this;
        $request->getEventDispatcher()->addListener('request.before_send', function ($event) use ($that) {
            $that->assertEquals(0, $event['request']->getHeader('Content-Length'));
        });
        $this->multi->add($request);
        $this->multi->send();
    }
}
