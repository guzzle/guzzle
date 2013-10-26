<?php

namespace Guzzle\Tests\Http\Adapter\Curl;

require_once __DIR__ . '/../../Server.php';

use Guzzle\Http\Adapter\Curl\CurlAdapter;
use Guzzle\Http\Adapter\Transaction;
use Guzzle\Http\Client;
use Guzzle\Http\Event\RequestAfterSendEvent;
use Guzzle\Http\Event\RequestErrorEvent;
use Guzzle\Http\Event\RequestEvents;
use Guzzle\Http\Exception\RequestException;
use Guzzle\Http\Message\MessageFactory;
use Guzzle\Http\Message\Request;
use Guzzle\Http\Message\Response;
use Guzzle\Tests\Http\Server;

/**
 * @covers Guzzle\Http\Adapter\Curl\CurlAdapter
 */
class CurlAdapterTest extends \PHPUnit_Framework_TestCase
{
    /** @var \Guzzle\Tests\Http\Server */
    static $server;

    public static function setUpBeforeClass()
    {
        self::$server = new Server();
        self::$server->start();
    }

    public static function tearDownAfterClass()
    {
        self::$server->stop();
    }

    public function testSendsSingleRequest()
    {
        self::$server->flush();
        self::$server->enqueue("HTTP/1.1 200 OK\r\nFoo: bar\r\nContent-Length: 0\r\n\r\n");
        $t = new Transaction(new Client(), new Request('GET', self::$server->getUrl()));
        $a = new CurlAdapter(new MessageFactory());
        $response = $a->send($t);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('bar', $response->getHeader('Foo'));
    }

    public function testSendsBatchRequests()
    {
        $c = new Client();
        self::$server->flush();
        self::$server->enqueue([
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 201 OK\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 202 OK\r\nContent-Length: 0\r\n\r\n"
        ]);
        $transactions = [
            new Transaction($c, new Request('GET', self::$server->getUrl())),
            new Transaction($c, new Request('PUT', self::$server->getUrl())),
            new Transaction($c, new Request('HEAD', self::$server->getUrl()))
        ];
        $a = new CurlAdapter(new MessageFactory());
        $a->batch($transactions);
        foreach ($transactions as $t) {
            $this->assertContains($t->getResponse()->getStatusCode(), [200, 201, 202]);
        }
    }

    public function testCatchesErrorWhenPreparing()
    {
        $r = new Request('GET', self::$server->getUrl());

        $f = $this->getMockBuilder('Guzzle\Http\Adapter\Curl\CurlFactory')
            ->setMethods(['createHandle'])
            ->getMock();
        $f->expects($this->once())
            ->method('createHandle')
            ->will($this->throwException(new RequestException('foo', $r)));

        $t = new Transaction(new Client(), $r);
        $a = new CurlAdapter(new MessageFactory(), ['handle_factory' => $f]);
        $ev = null;
        $r->getEventDispatcher()->addListener(RequestEvents::ERROR, function (RequestErrorEvent $e) use (&$ev) {
            $ev = $e;
        });
        try {
            $a->send($t);
            $this->fail('Did not throw');
        } catch (RequestException $e) {}
        $this->assertInstanceOf('Guzzle\Http\Event\RequestErrorEvent', $ev);
        $this->assertSame($r, $ev->getRequest());
        $this->assertInstanceOf('Guzzle\Http\Exception\RequestException', $ev->getException());
        $this->assertEquals([], $ev->getTransferInfo());
    }

    public function testDispatchesAfterSendEvent()
    {
        self::$server->flush();
        self::$server->enqueue("HTTP/1.1 201 OK\r\nContent-Length: 0\r\n\r\n");
        $r = new Request('GET', self::$server->getUrl());
        $t = new Transaction(new Client(), $r);
        $a = new CurlAdapter(new MessageFactory());
        $r->getEventDispatcher()->addListener(RequestEvents::AFTER_SEND, function (RequestAfterSendEvent $e) {
            $e->intercept(new Response(200, ['Foo' => 'bar']));
        });
        $response = $a->send($t);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('bar', $response->getHeader('Foo'));
    }

    public function testDispatchesErrorEventAndRecovers()
    {
        self::$server->flush();
        self::$server->enqueue("HTTP/1.1 201 OK\r\nContent-Length: 0\r\n\r\n");
        $r = new Request('GET', self::$server->getUrl());
        $t = new Transaction(new Client(), $r);
        $a = new CurlAdapter(new MessageFactory());
        $listener = function (RequestAfterSendEvent $e) use (&$listener) {
            $e->getDispatcher()->removeListener(RequestEvents::AFTER_SEND, $listener);
            throw new RequestException('Foo', $e->getRequest());
        };
        $r->getEventDispatcher()->addListener(RequestEvents::AFTER_SEND, $listener);
        $r->getEventDispatcher()->addListener(RequestEvents::ERROR, function (RequestErrorEvent $e) {
            $e->intercept(new Response(200, ['Foo' => 'bar']));
        });
        $response = $a->send($t);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('bar', $response->getHeader('Foo'));
    }

    /**
     * @expectedException \Guzzle\Http\Exception\AdapterException
     * @expectedExceptionMessage cURL error -2:
     */
    public function testChecksCurlMultiResult()
    {
        $a = new CurlAdapter(new MessageFactory());
        $r = new \ReflectionMethod($a, 'checkCurlResult');
        $r->setAccessible(true);
        $r->invoke($a, -2);
    }

    public function testChecksForCurlException()
    {
        $request = new Request('GET', '/');
        $a = new CurlAdapter(new MessageFactory());
        $r = new \ReflectionMethod($a, 'isCurlException');
        $r->setAccessible(true);
        try {
            $r->invoke($a, $request, ['result' => -10]);
            $this->fail('Did not throw');
        } catch (RequestException $e) {
            $this->assertSame($request, $e->getRequest());
            $this->assertContains('[curl] (#-10) ', $e->getMessage());
            $this->assertContains($request->getUrl(), $e->getMessage());
        }
    }
}
