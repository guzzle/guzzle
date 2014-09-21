<?php
namespace GuzzleHttp\Tests;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Message\Response;
use GuzzleHttp\RequestFsm;
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

class RequestFsmTest extends \PHPUnit_Framework_TestCase
{
    public function testEmitsBeforeEventInTransition()
    {
        $fsm = new RequestFsm();
        $t = new Transaction(new Client(), new Request('GET', 'http://foo.com'));
        $c = false;
        $t->request->getEmitter()->on('before', function (BeforeEvent $e) use (&$c) {
            $c = true;
        });
        $fsm->run($t, 'before');
        $this->assertTrue($c);
    }

    public function testEmitsCompleteEventInTransition()
    {
        $fsm = new RequestFsm();
        $t = new Transaction(new Client(), new Request('GET', 'http://foo.com'));
        $t->response = new Response(200);
        $t->state = 'complete';
        $c = false;
        $t->request->getEmitter()->on('complete', function (CompleteEvent $e) use (&$c) {
            $c = true;
        });
        $fsm->run($t, 'complete');
        $this->assertTrue($c);
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage Invalid complete state
     */
    public function testEnsuresResponseIsSetInCompleteState()
    {
        $fsm = new RequestFsm();
        $t = new Transaction(new Client(), new Request('GET', 'http://foo.com'));
        $t->state = 'complete';
        $fsm->run($t, 'complete');
    }

    public function testDoesNotEmitCompleteForFuture()
    {
        $fsm = new RequestFsm();
        $t = new Transaction(new Client(), new Request('GET', 'http://foo.com'));
        $t->response = new FutureResponse(function () {});
        $t->state = 'complete';
        $c = false;
        $t->request->getEmitter()->on('complete', function (CompleteEvent $e) use (&$c) {
            $c = true;
        });
        $fsm->run($t, 'complete');
        $this->assertFalse($c);
    }

    public function testDoesNotEmitEndForFuture()
    {
        $fsm = new RequestFsm();
        $t = new Transaction(new Client(), new Request('GET', 'http://foo.com'));
        $t->response = new FutureResponse(function () {});
        $t->state = 'end';
        $c = false;
        $t->request->getEmitter()->on('end', function (EndEvent $e) use (&$c) {
            $c = true;
        });
        $fsm->run($t, 'end');
        $this->assertFalse($c);
    }

    public function testTransitionsThroughSuccessfulTransfer()
    {
        $fsm = new RequestFsm();
        $t = new Transaction(new Client(), new Request('GET', 'http://foo.com'));
        $calls = [];
        $this->addListeners($t->request, $calls);
        $fsm->run($t, 'send');
        $t->response = new Response(200);
        $fsm->run($t);
        $this->assertEquals(['before', 'complete', 'end'], $calls);
    }

    public function testTransitionsThroughErrorsInBefore()
    {
        $fsm = new RequestFsm();
        $client = new Client();
        $request = $client->createRequest('GET', 'http://ewfewwef.com');
        $t = new Transaction($client, $request);
        $calls = [];
        $this->addListeners($t->request, $calls);
        $t->request->getEmitter()->on('before', function (BeforeEvent $e) {
            throw new \Exception('foo');
        });
        try {
            $fsm->run($t, 'send');
            $this->fail('did not throw');
        } catch (RequestException $e) {
            $this->assertContains('foo', $t->exception->getMessage());
            $this->assertEquals(['before', 'error', 'end'], $calls);
        }
    }

    public function testTransitionsThroughErrorsInComplete()
    {
        $fsm = new RequestFsm();
        $client = new Client();
        $request = $client->createRequest('GET', 'http://ewfewwef.com');
        $t = new Transaction($client, $request);
        $calls = [];
        $this->addListeners($t->request, $calls);
        $t->request->getEmitter()->on('complete', function (CompleteEvent $e) {
            throw new \Exception('foo');
        });
        $fsm->run($t, 'send');
        $t->response = new Response(200);
        try {
            $fsm->run($t);
            $this->fail('did not throw');
        } catch (RequestException $e) {
            $this->assertContains('foo', $t->exception->getMessage());
            $this->assertEquals(['before', 'complete', 'error', 'end'], $calls);
        }
    }

    public function testTransitionsThroughErrorInterception()
    {
        $fsm = new RequestFsm();
        $client = new Client();
        $request = $client->createRequest('GET', 'http://ewfewwef.com');
        $t = new Transaction($client, $request);
        $calls = [];
        $this->addListeners($t->request, $calls);
        $t->request->getEmitter()->on('error', function (ErrorEvent $e) {
            $e->intercept(new Response(200));
        });
        $fsm->run($t, 'send');
        $t->response = new Response(404);
        $t->state = 'complete';
        $fsm->run($t);
        $this->assertEquals(200, $t->response->getStatusCode());
        $this->assertNull($t->exception);
        $this->assertEquals(['before', 'complete', 'error', 'complete', 'end'], $calls);
    }

    public function testCanStopExceptionsWithFuture()
    {
        $fsm = new RequestFsm();
        $client = new Client();
        $request = $client->createRequest('GET', 'http://ewfewwef.com');
        $t = new Transaction($client, $request);
        $calls = [];
        $this->addListeners($t->request, $calls);
        $t->request->getEmitter()->on('end', function (EndEvent $e) {
            RequestEvents::stopException($e);
        });
        $fsm->run($t, 'send');
        $t->response = new Response(404);
        $t->state = 'complete';
        $fsm->run($t);
        $this->assertNull($t->exception);
        $this->assertEquals(['before', 'complete', 'error', 'end'], $calls);
        $this->assertInstanceOf('GuzzleHttp\Message\FutureResponse', $t->response);
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
}
