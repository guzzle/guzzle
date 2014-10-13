<?php
namespace GuzzleHttp\Tests;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Message\MessageFactory;
use GuzzleHttp\Message\Response;
use GuzzleHttp\RequestFsm;
use GuzzleHttp\Subscriber\Mock;
use GuzzleHttp\Transaction;
use GuzzleHttp\Client;
use GuzzleHttp\Message\Request;
use GuzzleHttp\Event\BeforeEvent;
use GuzzleHttp\Event\CompleteEvent;
use GuzzleHttp\Event\ErrorEvent;
use GuzzleHttp\Event\EndEvent;
use GuzzleHttp\Message\FutureResponse;
use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Event\RequestEvents;
use React\Promise\Deferred;

class RequestFsmTest extends \PHPUnit_Framework_TestCase
{
    private $mf;

    public function setup()
    {
        $this->mf = new MessageFactory();
    }

    public function testEmitsBeforeEventInTransition()
    {
        $fsm = new RequestFsm(function () {}, $this->mf);
        $t = new Transaction(new Client(), new Request('GET', 'http://foo.com'));
        $c = false;
        $t->request->getEmitter()->on('before', function (BeforeEvent $e) use (&$c) {
            $c = true;
        });
        $fsm($t, 'send');
        $this->assertTrue($c);
    }

    public function testEmitsCompleteEventInTransition()
    {
        $fsm = new RequestFsm(function () {}, $this->mf);
        $t = new Transaction(new Client(), new Request('GET', 'http://foo.com'));
        $t->response = new Response(200);
        $t->state = 'complete';
        $c = false;
        $t->request->getEmitter()->on('complete', function (CompleteEvent $e) use (&$c) {
            $c = true;
        });
        $fsm($t, 'end');
        $this->assertTrue($c);
    }

    public function testDoesNotEmitCompleteForFuture()
    {
        $fsm = new RequestFsm(function () {}, $this->mf);
        $t = new Transaction(new Client(), new Request('GET', 'http://foo.com'));
        $deferred = new Deferred();
        $t->response = new FutureResponse($deferred->promise());
        $t->state = 'complete';
        $c = false;
        $t->request->getEmitter()->on('complete', function (CompleteEvent $e) use (&$c) {
            $c = true;
        });
        $fsm($t, 'end');
        $this->assertFalse($c);
    }

    public function testDoesNotEmitEndForFuture()
    {
        $fsm = new RequestFsm(function () {}, $this->mf);
        $t = new Transaction(new Client(), new Request('GET', 'http://foo.com'));
        $deferred = new Deferred();
        $t->response = new FutureResponse($deferred->promise());
        $t->state = 'end';
        $c = false;
        $t->request->getEmitter()->on('end', function (EndEvent $e) use (&$c) {
            $c = true;
        });
        $fsm($t);
        $this->assertFalse($c);
    }

    public function testTransitionsThroughSuccessfulTransfer()
    {
        $client = new Client();
        $client->getEmitter()->attach(new Mock([new Response(200)]));
        $request = $client->createRequest('GET', 'http://ewfewwef.com');
        $this->addListeners($request, $calls);
        $client->send($request);
        $this->assertEquals(['before', 'complete', 'end'], $calls);
    }

    public function testTransitionsThroughErrorsInBefore()
    {
        $fsm = new RequestFsm(function () {}, $this->mf);
        $client = new Client();
        $request = $client->createRequest('GET', 'http://ewfewwef.com');
        $t = new Transaction($client, $request);
        $calls = [];
        $this->addListeners($t->request, $calls);
        $t->request->getEmitter()->on('before', function (BeforeEvent $e) {
            throw new \Exception('foo');
        });
        try {
            $fsm($t, 'send');
            $this->fail('did not throw');
        } catch (RequestException $e) {
            $this->assertContains('foo', $t->exception->getMessage());
            $this->assertEquals(['before', 'error', 'end'], $calls);
        }
    }

    public function testTransitionsThroughErrorsInComplete()
    {
        $client = new Client();
        $client->getEmitter()->attach(new Mock([new Response(200)]));
        $request = $client->createRequest('GET', 'http://ewfewwef.com');
        $this->addListeners($request, $calls);
        $request->getEmitter()->once('complete', function (CompleteEvent $e) {
            throw new \Exception('foo');
        });
        try {
            $client->send($request);
            $this->fail('did not throw');
        } catch (RequestException $e) {
            $this->assertContains('foo', $e->getMessage());
            $this->assertEquals(['before', 'complete', 'error', 'end'], $calls);
        }
    }

    public function testTransitionsThroughErrorInterception()
    {
        $fsm = new RequestFsm(function () {}, $this->mf);
        $client = new Client();
        $request = $client->createRequest('GET', 'http://ewfewwef.com');
        $t = new Transaction($client, $request);
        $calls = [];
        $this->addListeners($t->request, $calls);
        $t->request->getEmitter()->on('error', function (ErrorEvent $e) {
            $e->intercept(new Response(200));
        });
        $fsm($t, 'send');
        $t->response = new Response(404);
        $t->state = 'complete';
        $fsm($t);
        $this->assertEquals(200, $t->response->getStatusCode());
        $this->assertNull($t->exception);
        $this->assertEquals(['before', 'complete', 'error', 'complete', 'end'], $calls);
    }

    private function addListeners(RequestInterface $request, &$calls)
    {
        $request->getEmitter()->on('before', function (BeforeEvent $e) use (&$calls) {
            $calls[] = 'before';
        }, RequestEvents::EARLY);
        $request->getEmitter()->on('complete', function (CompleteEvent $e) use (&$calls) {
            $calls[] = 'complete';
        }, RequestEvents::EARLY);
        $request->getEmitter()->on('error', function (ErrorEvent $e) use (&$calls) {
            $calls[] = 'error';
        }, RequestEvents::EARLY);
        $request->getEmitter()->on('end', function (EndEvent $e) use (&$calls) {
            $calls[] = 'end';
        }, RequestEvents::EARLY);
    }

    /**
     * @expectedException \GuzzleHttp\Exception\RequestException
     * @expectedExceptionMessage Too many state transitions
     */
    public function testDetectsInfiniteLoops()
    {
        $client = new Client([
            'fsm' => $fsm = new RequestFsm(
                    function () {},
                    new MessageFactory(),
                    3
                )
        ]);
        $request = $client->createRequest('GET', 'http://foo.com:123');
        $request->getEmitter()->on('before', function () {
            throw new \Exception('foo');
        });
        $request->getEmitter()->on('error', function ($e) {
            $e->retry();
        });
        $client->send($request);
    }
}
