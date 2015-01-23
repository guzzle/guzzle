<?php
namespace GuzzleHttp\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Event\ProgressEvent;
use GuzzleHttp\Message\MessageFactory;
use GuzzleHttp\RingBridge;
use GuzzleHttp\Stream\Stream;
use GuzzleHttp\Transaction;
use GuzzleHttp\Message\Request;
use GuzzleHttp\Message\Response;
use GuzzleHttp\Ring\Client\MockHandler;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Event\ErrorEvent;
use GuzzleHttp\RequestFsm;

class RingBridgeTest extends \PHPUnit_Framework_TestCase
{
    public function testCreatesRingRequests()
    {
        $stream = Stream::factory('test');
        $request = new Request('GET', 'http://httpbin.org/get?a=b', [
            'test' => 'hello'
        ], $stream);
        $request->getConfig()->set('foo', 'bar');
        $trans = new Transaction(new Client(), $request);
        $factory = new MessageFactory();
        $fsm = new RequestFsm(function () {}, new MessageFactory());
        $r = RingBridge::prepareRingRequest($trans, $factory, $fsm);
        $this->assertEquals('http', $r['scheme']);
        $this->assertEquals('1.1', $r['version']);
        $this->assertEquals('GET', $r['http_method']);
        $this->assertEquals('http://httpbin.org/get?a=b', $r['url']);
        $this->assertEquals('/get', $r['uri']);
        $this->assertEquals('a=b', $r['query_string']);
        $this->assertEquals([
            'Host' => ['httpbin.org'],
            'test' => ['hello']
        ], $r['headers']);
        $this->assertSame($stream, $r['body']);
        $this->assertEquals(['foo' => 'bar'], $r['client']);
        $this->assertFalse($r['future']);
    }

    public function testCreatesRingRequestsWithNullQueryString()
    {
        $request = new Request('GET', 'http://httpbin.org');
        $trans = new Transaction(new Client(), $request);
        $factory = new MessageFactory();
        $fsm = new RequestFsm(function () {}, new MessageFactory());
        $r = RingBridge::prepareRingRequest($trans, $factory, $fsm);
        $this->assertNull($r['query_string']);
        $this->assertEquals('/', $r['uri']);
        $this->assertEquals(['Host' => ['httpbin.org']], $r['headers']);
        $this->assertNull($r['body']);
        $this->assertEquals([], $r['client']);
    }

    public function testAddsProgress()
    {
        Server::enqueue([new Response(200)]);
        $client = new Client(['base_url' => Server::$url]);
        $request = $client->createRequest('GET');
        $called = false;
        $request->getEmitter()->on(
            'progress',
            function (ProgressEvent $e) use (&$called) {
                $called = true;
            }
        );
        $this->assertEquals(200, $client->send($request)->getStatusCode());
        $this->assertTrue($called);
    }

    public function testGetsResponseProtocolVersionAndEffectiveUrlAndReason()
    {
        $client = new Client([
            'handler' => new MockHandler([
                'status'  => 200,
                'reason' => 'test',
                'headers' => [],
                'version' => '1.0',
                'effective_url' => 'http://foo.com'
            ])
        ]);
        $request = $client->createRequest('GET', 'http://foo.com');
        $response = $client->send($request);
        $this->assertEquals('1.0', $response->getProtocolVersion());
        $this->assertEquals('http://foo.com', $response->getEffectiveUrl());
        $this->assertEquals('test', $response->getReasonPhrase());
    }

    public function testGetsStreamFromResponse()
    {
        $res = fopen('php://temp', 'r+');
        fwrite($res, 'foo');
        rewind($res);
        $client = new Client([
            'handler' => new MockHandler([
                'status'  => 200,
                'headers' => [],
                'body' => $res
            ])
        ]);
        $request = $client->createRequest('GET', 'http://foo.com');
        $response = $client->send($request);
        $this->assertEquals('foo', (string) $response->getBody());
    }

    public function testEmitsErrorEventOnError()
    {
        $client = new Client(['base_url' => 'http://127.0.0.1:123']);
        $request = $client->createRequest('GET');
        $called = false;
        $request->getEmitter()->on('error', function () use (&$called) {
            $called = true;
        });
        $request->getConfig()['timeout'] = 0.001;
        $request->getConfig()['connect_timeout'] = 0.001;
        try {
            $client->send($request);
            $this->fail('did not throw');
        } catch (RequestException $e) {
            $this->assertSame($request, $e->getRequest());
            $this->assertContains('cURL error', $e->getMessage());
            $this->assertTrue($called);
        }
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testValidatesRingRequest()
    {
        RingBridge::fromRingRequest([]);
    }

    public function testCreatesRequestFromRing()
    {
        $request = RingBridge::fromRingRequest([
            'http_method' => 'GET',
            'uri' => '/',
            'headers' => [
                'foo' => ['bar'],
                'host' => ['foo.com']
            ],
            'body' => 'test',
            'version' => '1.0'
        ]);
        $this->assertEquals('GET', $request->getMethod());
        $this->assertEquals('http://foo.com/', $request->getUrl());
        $this->assertEquals('1.0', $request->getProtocolVersion());
        $this->assertEquals('test', (string) $request->getBody());
        $this->assertEquals('bar', $request->getHeader('foo'));
    }

    public function testCanInterceptException()
    {
        $client = new Client(['base_url' => 'http://127.0.0.1:123']);
        $request = $client->createRequest('GET');
        $called = false;
        $request->getEmitter()->on(
            'error',
            function (ErrorEvent $e) use (&$called) {
                $called = true;
                $e->intercept(new Response(200));
            }
        );
        $request->getConfig()['timeout'] = 0.001;
        $request->getConfig()['connect_timeout'] = 0.001;
        $this->assertEquals(200, $client->send($request)->getStatusCode());
        $this->assertTrue($called);
    }

    public function testCreatesLongException()
    {
        $r = new Request('GET', 'http://www.google.com');
        $e = RingBridge::getNoRingResponseException($r);
        $this->assertInstanceOf('GuzzleHttp\Exception\RequestException', $e);
        $this->assertSame($r, $e->getRequest());
    }

    public function testEnsuresResponseOrExceptionWhenCompletingResponse()
    {
        $trans = new Transaction(new Client(), new Request('GET', 'http://f.co'));
        $f = new MessageFactory();
        $fsm = new RequestFsm(function () {}, new MessageFactory());
        try {
            RingBridge::completeRingResponse($trans, [], $f, $fsm);
        } catch (RequestException $e) {
            $this->assertSame($trans->request, $e->getRequest());
            $this->assertContains('RingPHP', $e->getMessage());
        }
    }
}
