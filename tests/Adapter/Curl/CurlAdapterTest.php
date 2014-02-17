<?php

namespace GuzzleHttp\Tests\Adapter\Curl;

require_once __DIR__ . '/../../Server.php';

use GuzzleHttp\Adapter\Curl\CurlAdapter;
use GuzzleHttp\Adapter\Transaction;
use GuzzleHttp\Client;
use GuzzleHttp\Event\CompleteEvent;
use GuzzleHttp\Event\ErrorEvent;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Message\MessageFactory;
use GuzzleHttp\Message\Request;
use GuzzleHttp\Message\Response;
use GuzzleHttp\Tests\Server;
use GuzzleHttp\Url;

/**
 * @covers GuzzleHttp\Adapter\Curl\CurlAdapter
 */
class CurlAdapterTest extends \PHPUnit_Framework_TestCase
{
    /** @var \GuzzleHttp\Tests\Server */
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

    public function testSendsParallelRequests()
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
        $a->sendAll(new \ArrayIterator($transactions), 20);
        foreach ($transactions as $t) {
            $this->assertContains($t->getResponse()->getStatusCode(), [200, 201, 202]);
        }
    }

    public function testCatchesErrorWhenPreparing()
    {
        $r = new Request('GET', self::$server->getUrl());

        $f = $this->getMockBuilder('GuzzleHttp\Adapter\Curl\CurlFactory')
            ->setMethods(['createHandle'])
            ->getMock();
        $f->expects($this->once())
            ->method('createHandle')
            ->will($this->throwException(new RequestException('foo', $r)));

        $t = new Transaction(new Client(), $r);
        $a = new CurlAdapter(new MessageFactory(), ['handle_factory' => $f]);
        $ev = null;
        $r->getEmitter()->on('error', function (ErrorEvent $e) use (&$ev) {
            $ev = $e;
        });
        try {
            $a->send($t);
            $this->fail('Did not throw');
        } catch (RequestException $e) {}
        $this->assertInstanceOf('GuzzleHttp\Event\ErrorEvent', $ev);
        $this->assertSame($r, $ev->getRequest());
        $this->assertInstanceOf('GuzzleHttp\Exception\RequestException', $ev->getException());
    }

    public function testDispatchesAfterSendEvent()
    {
        self::$server->flush();
        self::$server->enqueue("HTTP/1.1 201 OK\r\nContent-Length: 0\r\n\r\n");
        $r = new Request('GET', self::$server->getUrl());
        $t = new Transaction(new Client(), $r);
        $a = new CurlAdapter(new MessageFactory());
        $ev = null;
        $r->getEmitter()->on('complete', function (CompleteEvent $e) use (&$ev) {
            $ev = $e;
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
        $r->getEmitter()->once('complete', function (CompleteEvent $e) {
            throw new RequestException('Foo', $e->getRequest());
        });
        $r->getEmitter()->on('error', function (ErrorEvent $e) {
            $e->intercept(new Response(200, ['Foo' => 'bar']));
        });
        $response = $a->send($t);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('bar', $response->getHeader('Foo'));
    }

    /**
     * @expectedException \GuzzleHttp\Exception\AdapterException
     * @expectedExceptionMessage cURL error -2:
     */
    public function testChecksCurlMultiResult()
    {
        CurlAdapter::throwMultiError(-2);
    }

    public function testChecksForCurlException()
    {
        $request = new Request('GET', '/');
        $transaction = $this->getMockBuilder('GuzzleHttp\Adapter\Transaction')
            ->setMethods(['getRequest'])
            ->disableOriginalConstructor()
            ->getMock();
        $transaction->expects($this->exactly(2))
            ->method('getRequest')
            ->will($this->returnValue($request));
        $context = $this->getMockBuilder('GuzzleHttp\Adapter\Curl\BatchContext')
            ->setMethods(['throwsExceptions'])
            ->disableOriginalConstructor()
            ->getMock();
        $context->expects($this->once())
            ->method('throwsExceptions')
            ->will($this->returnValue(true));
        $a = new CurlAdapter(new MessageFactory());
        $r = new \ReflectionMethod($a, 'isCurlException');
        $r->setAccessible(true);
        try {
            $r->invoke($a, $transaction, ['result' => -10], $context);
            $this->fail('Did not throw');
        } catch (RequestException $e) {
            $this->assertSame($request, $e->getRequest());
            $this->assertContains('[curl] (#-10) ', $e->getMessage());
            $this->assertContains($request->getUrl(), $e->getMessage());
        }
    }

    public function testStripsFragmentFromHost()
    {
        self::$server->flush();
        self::$server->enqueue("HTTP/1.1 200 OK\r\n\r\nContent-Length: 0\r\n\r\n");
        // This will fail if the removal of the #fragment is not performed
        $url = Url::fromString(self::$server->getUrl())->setPath(null)->setFragment('foo');
        $client = new Client();
        $client->get($url);
    }
}
