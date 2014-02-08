<?php

namespace Guzzle\Tests\Http\Event;

use Guzzle\Http\Client;
use Guzzle\Http\Adapter\Transaction;
use Guzzle\Http\Event\RequestBeforeSendEvent;
use Guzzle\Http\Event\RequestErrorEvent;
use Guzzle\Http\Event\RequestEvents;
use Guzzle\Http\Exception\RequestException;
use Guzzle\Http\Message\Request;
use Guzzle\Http\Message\Response;

/**
 * @covers Guzzle\Http\Event\RequestEvents
 */
class RequestEventsTest extends \PHPUnit_Framework_TestCase
{
    public function testEmitsAfterSendEvent()
    {
        $res = null;
        $t = new Transaction(new Client(), new Request('GET', '/'));
        $t->setResponse(new Response(200));
        $t->getRequest()->getEmitter()->on(RequestEvents::AFTER_SEND, function ($e) use (&$res) {
            $res = $e;
        });
        RequestEvents::emitAfterSendEvent($t);
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
        $t->getRequest()->getEmitter()->on(RequestEvents::AFTER_SEND, function ($e) use ($ex) {
            $ex->e = $e;
            throw $ex;
        });
        $t->getRequest()->getEmitter()->on(RequestEvents::ERROR, function ($e) use (&$ex2) {
            $ex2 = $e->getException();
            $e->stopPropagation();
        });
        RequestEvents::emitAfterSendEvent($t);
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
            RequestEvents::BEFORE_SEND,
            function (RequestBeforeSendEvent $e) use ($request, $client, &$beforeCalled, $ex) {
                $this->assertSame($request, $e->getRequest());
                $this->assertSame($client, $e->getClient());
                $beforeCalled++;
                throw $ex;
            }
        );

        $request->getEmitter()->on(
            RequestEvents::ERROR,
            function (RequestErrorEvent $e) use (&$errCalled, $response, $ex) {
                $errCalled++;
                $this->assertInstanceOf('Guzzle\Http\Exception\RequestException', $e->getException());
                $this->assertSame($ex, $e->getException()->getPrevious());
                $e->intercept($response);
            }
        );

        RequestEvents::emitBeforeSendEvent($t);
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

        $request->getEmitter()->on(RequestEvents::BEFORE_SEND, function (RequestBeforeSendEvent $e) use ($ex) {
            throw $ex;
        });

        $request->getEmitter()->on(RequestEvents::ERROR, function (RequestErrorEvent $e) use (&$errCalled) {
            $errCalled++;
        });

        try {
            RequestEvents::emitBeforeSendEvent($t);
            $this->fail('Did not throw');
        } catch (RequestException $e) {
            $this->assertEquals(1, $errCalled);
        }
    }
}
