<?php

namespace GuzzleHttp\Tests\Event;

use GuzzleHttp\Client;
use GuzzleHttp\Adapter\Transaction;
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
        $t->setResponse(new Response(200));
        $t->getRequest()->getEmitter()->on('complete', function ($e) use (&$res) {
            $res = $e;
        });
        RequestEvents::emitComplete($t);
        $this->assertSame($res->getClient(), $t->getClient());
        $this->assertSame($res->getRequest(), $t->getRequest());
        $this->assertEquals('/', $t->getResponse()->getEffectiveUrl());
    }

    public function testEmitsAfterSendEventAndEmitsErrorIfNeeded()
    {
        $ex2 = $res = null;
        $request = new Request('GET', '/');
        $t = new Transaction(new Client(), $request);
        $t->setResponse(new Response(200));
        $ex = new RequestException('foo', $request);
        $t->getRequest()->getEmitter()->on('complete', function ($e) use ($ex) {
            $ex->e = $e;
            throw $ex;
        });
        $t->getRequest()->getEmitter()->on('error', function ($e) use (&$ex2) {
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
        $this->assertSame($response, $t->getResponse());
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
}
