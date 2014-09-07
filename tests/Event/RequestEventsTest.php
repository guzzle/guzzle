<?php
namespace GuzzleHttp\Tests\Event;

use GuzzleHttp\Client;
use GuzzleHttp\Event\ProgressEvent;
use GuzzleHttp\Message\MessageFactory;
use GuzzleHttp\Ring\Client\MockAdapter;
use GuzzleHttp\Ring\Client\StreamAdapter;
use GuzzleHttp\Stream\Stream;
use GuzzleHttp\Tests\Server;
use GuzzleHttp\Transaction;
use GuzzleHttp\Event\BeforeEvent;
use GuzzleHttp\Event\ErrorEvent;
use GuzzleHttp\Event\RequestEvents;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Message\Request;
use GuzzleHttp\Message\Response;
use GuzzleHttp\Subscriber\Mock;

/**
 * @covers GuzzleHttp\Event\RequestEvents
 */
class RequestEventsTest extends \PHPUnit_Framework_TestCase
{
    public function testEmitsAfterSendEvent()
    {
        $res = null;
        $t = new Transaction(new Client(), new Request('GET', '/'));
        $t->response = new Response(200);
        $t->request->getEmitter()->on('complete', function ($e) use (&$res) {
            $res = $e;
        });
        RequestEvents::emitComplete($t);
        $this->assertSame($res->getClient(), $t->client);
        $this->assertSame($res->getRequest(), $t->request);
        $this->assertEquals('/', $t->response->getEffectiveUrl());
    }

    public function testEmitsAfterSendEventAndEmitsErrorIfNeeded()
    {
        $ex2 = $res = null;
        $request = new Request('GET', '/');
        $t = new Transaction(new Client(), $request);
        $t->response = new Response(200);
        $ex = new RequestException('foo', $request);
        $t->request->getEmitter()->on('complete', function ($e) use ($ex) {
            $ex->e = $e;
            throw $ex;
        });
        $t->request->getEmitter()->on('error', function ($e) use (&$ex2) {
            $ex2 = $e->getException();
            $e->stopPropagation();
        });
        RequestEvents::emitComplete($t);
        $this->assertSame($ex, $ex2);
    }

    public function testBeforeSendEmitsErrorEvent()
    {
        $ex = new \Exception('Foo');
        $client = new Client();
        $request = new Request('GET', '/');
        $response = new Response(200);
        $t = new Transaction($client, $request);
        $beforeCalled = $errCalled = 0;

        $request->getEmitter()->on(
            'before',
            function (BeforeEvent $e) use ($request, $client, &$beforeCalled, $ex) {
                $this->assertSame($request, $e->getRequest());
                $this->assertSame($client, $e->getClient());
                $beforeCalled++;
                throw $ex;
            }
        );

        $request->getEmitter()->on(
            'error',
            function (ErrorEvent $e) use (&$errCalled, $response, $ex) {
                $errCalled++;
                $this->assertInstanceOf('GuzzleHttp\Exception\RequestException', $e->getException());
                $this->assertSame($ex, $e->getException()->getPrevious());
                $e->intercept($response);
            }
        );

        RequestEvents::emitBefore($t);
        $this->assertEquals(1, $beforeCalled);
        $this->assertEquals(1, $errCalled);
        $this->assertSame($response, $t->response);
    }

    public function testThrowsUnInterceptedErrors()
    {
        $ex = new \Exception('Foo');
        $client = new Client();
        $request = new Request('GET', '/');
        $t = new Transaction($client, $request);
        $errCalled = 0;

        $request->getEmitter()->on('before', function (BeforeEvent $e) use ($ex) {
            throw $ex;
        });

        $request->getEmitter()->on('error', function (ErrorEvent $e) use (&$errCalled) {
            $errCalled++;
        });

        try {
            RequestEvents::emitBefore($t);
            $this->fail('Did not throw');
        } catch (RequestException $e) {
            $this->assertEquals(1, $errCalled);
        }
    }

    public function testDoesNotEmitErrorEventTwice()
    {
        $client = new Client();
        $mock = new Mock([new Response(500)]);
        $client->getEmitter()->attach($mock);

        $r = [];
        $client->getEmitter()->on('error', function (ErrorEvent $event) use (&$r) {
            $r[] = $event->getRequest();
        });

        try {
            $client->get('http://foo.com');
            $this->fail('Did not throw');
        } catch (RequestException $e) {
            $this->assertCount(1, $r);
        }
    }

    /**
     * Note: Longest test name ever.
     */
    public function testEmitsErrorEventForRequestExceptionsThrownDuringBeforeThatHaveNotEmittedAnErrorEvent()
    {
        $request = new Request('GET', '/');
        $ex = new RequestException('foo', $request);

        $client = new Client();
        $client->getEmitter()->on('before', function (BeforeEvent $event) use ($ex) {
            throw $ex;
        });
        $called = false;
        $client->getEmitter()->on('error', function (ErrorEvent $event) use ($ex, &$called) {
            $called = true;
            $this->assertSame($ex, $event->getException());
        });

        try {
            $client->get('http://foo.com');
            $this->fail('Did not throw');
        } catch (RequestException $e) {
            $this->assertTrue($called);
        }
    }

    public function prepareEventProvider()
    {
        $cb = function () {};

        return [
            [[], ['complete'], $cb, ['complete' => [$cb]]],
            [
                ['complete' => $cb],
                ['complete'],
                $cb,
                ['complete' => [$cb, $cb]]
            ],
            [
                ['prepare' => []],
                ['error', 'foo'],
                $cb,
                [
                    'prepare' => [],
                    'error'   => [$cb],
                    'foo'     => [$cb]
                ]
            ],
            [
                ['prepare' => []],
                ['prepare'],
                $cb,
                [
                    'prepare' => [$cb]
                ]
            ],
            [
                ['prepare' => ['fn' => $cb]],
                ['prepare'], $cb,
                [
                    'prepare' => [
                        ['fn' => $cb],
                        $cb
                    ]
                ]
            ],
        ];
    }

    /**
     * @dataProvider prepareEventProvider
     */
    public function testConvertsEventArrays(
        array $in,
        array $events,
        $add,
        array $out
    ) {
        $result = RequestEvents::convertEventArray($in, $events, $add);
        $this->assertEquals($out, $result);
    }

    public function testCreatesRingRequests()
    {
        $stream = Stream::factory('test');
        $request = new Request('GET', 'http://httpbin.org/get?a=b', [
            'test' => 'hello'
        ], $stream);
        $request->getConfig()->set('foo', 'bar');
        $trans = new Transaction(new Client(), $request);
        $factory = new MessageFactory();
        $r = RequestEvents::createRingRequest($trans, $factory);
        $this->assertEquals('http', $r['scheme']);
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
        $this->assertTrue(is_callable($r['then']));
    }

    public function testCreatesRingRequestsWithNullQueryString()
    {
        $request = new Request('GET', 'http://httpbin.org');
        $trans = new Transaction(new Client(), $request);
        $factory = new MessageFactory();
        $r = RequestEvents::createRingRequest($trans, $factory);
        $this->assertNull($r['query_string']);
        $this->assertEquals('/', $r['uri']);
        $this->assertEquals(['Host' => ['httpbin.org']], $r['headers']);
        $this->assertNull($r['body']);
        $this->assertEquals([], $r['client']);
    }

    public function testCallsThenAndAddsProgress()
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

    public function testEmitsErrorEventOnError()
    {
        $client = new Client(['base_url' => 'http://127.0.0.1:123']);
        $request = $client->createRequest('GET');
        $request->getConfig()['timeout'] = 0.001;
        $request->getConfig()['connect_timeout'] = 0.001;
        try {
            $client->send($request);
            $this->fail('did not throw');
        } catch (RequestException $e) {
            $this->assertSame($request, $e->getRequest());
            $this->assertContains('cURL error 7:', $e->getMessage());
        }
    }

    public function testGetsResponseProtocolVersion()
    {
        $client = new Client([
            'adapter' => new MockAdapter([
                'status'  => 200,
                'headers' => [],
                'version' => '1.0'
            ])
        ]);
        $request = $client->createRequest('GET', 'http://foo.com');
        $response = $client->send($request);
        $this->assertEquals('1.0', $response->getProtocolVersion());
    }
}
