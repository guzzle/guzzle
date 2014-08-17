<?php

namespace GuzzleHttp\Tests\Adapter;

use GuzzleHttp\Adapter\MockAdapter;
use GuzzleHttp\Adapter\Transaction;
use GuzzleHttp\Adapter\TransactionInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Event\CompleteEvent;
use GuzzleHttp\Event\ErrorEvent;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Message\Request;
use GuzzleHttp\Message\Response;
use GuzzleHttp\Stream\Stream;

/**
 * @covers GuzzleHttp\Adapter\MockAdapter
 */
class MockAdapterTest extends \PHPUnit_Framework_TestCase
{
    public function testYieldsMockResponse()
    {
        $response = new Response(200);
        $m = new MockAdapter();
        $m->setResponse($response);
        $this->assertSame($response, $m->send(new Transaction(new Client(), new Request('GET', 'http://httbin.org'))));
    }

    public function testMocksWithCallable()
    {
        $response = new Response(200);
        $r = function (TransactionInterface $trans) use ($response) {
            return $response;
        };
        $m = new MockAdapter($r);
        $this->assertSame($response, $m->send(new Transaction(new Client(), new Request('GET', 'http://httbin.org'))));
    }

    /**
     * @expectedException \RuntimeException
     */
    public function testValidatesResponses()
    {
        $m = new MockAdapter();
        $m->setResponse('foo');
        $m->send(new Transaction(new Client(), new Request('GET', 'http://httbin.org')));
    }

    public function testHandlesErrors()
    {
        $m = new MockAdapter();
        $m->setResponse(new Response(404));
        $request = new Request('GET', 'http://httbin.org');
        $c = false;
        $request->getEmitter()->once('complete', function (CompleteEvent $e) use (&$c) {
            $c = true;
            throw new RequestException('foo', $e->getRequest());
        });
        $request->getEmitter()->on('error', function (ErrorEvent $e) {
            $e->intercept(new Response(201));
        });
        $r = $m->send(new Transaction(new Client(), $request));
        $this->assertTrue($c);
        $this->assertEquals(201, $r->getStatusCode());
    }

    /**
     * @expectedException \GuzzleHttp\Exception\RequestException
     */
    public function testThrowsUnhandledErrors()
    {
        $m = new MockAdapter();
        $m->setResponse(new Response(404));
        $request = new Request('GET', 'http://httbin.org');
        $request->getEmitter()->once('complete', function (CompleteEvent $e) {
            throw new RequestException('foo', $e->getRequest());
        });
        $m->send(new Transaction(new Client(), $request));
    }

    public function testReadsRequestBody()
    {
        $response = new Response(200);
        $m = new MockAdapter($response);
        $m->setResponse($response);
        $body = Stream::factory('foo');
        $request = new Request('PUT', 'http://httpbin.org/put', [], $body);
        $this->assertSame($response, $m->send(new Transaction(new Client(), $request)));
        $this->assertEquals(3, $body->tell());
    }

    public function testEmitsHeadersEvent()
    {
        $m = new MockAdapter(new Response(404));
        $request = new Request('GET', 'http://httbin.org');
        $called = false;
        $request->getEmitter()->once('headers', function () use (&$called) {
            $called = true;
        });
        $m->send(new Transaction(new Client(), $request));
        $this->assertTrue($called);
    }
}
