<?php

namespace GuzzleHttp\Tests\Adapter\Curl;

require_once __DIR__ . '/../../Server.php';

use GuzzleHttp\Adapter\Transaction;
use GuzzleHttp\Client;
use GuzzleHttp\Event\CompleteEvent;
use GuzzleHttp\Event\ErrorEvent;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Message\Request;
use GuzzleHttp\Message\Response;
use GuzzleHttp\Tests\Server;
use GuzzleHttp\Url;

abstract class AbstractCurl extends \PHPUnit_Framework_TestCase
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

    abstract protected function getAdapter($factory = null, $options = []);

    public function testSendsRequest()
    {
        self::$server->flush();
        self::$server->enqueue("HTTP/1.1 200 OK\r\nFoo: bar\r\nContent-Length: 0\r\n\r\n");
        $t = new Transaction(new Client(), new Request('GET', self::$server->getUrl()));
        $a = $this->getAdapter();
        $response = $a->send($t);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('bar', $response->getHeader('Foo'));
    }

    /**
     * @expectedException \GuzzleHttp\Exception\RequestException
     */
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
        $a = $this->getAdapter(null, ['handle_factory' => $f]);
        $a->send($t);
    }

    public function testDispatchesAfterSendEvent()
    {
        self::$server->flush();
        self::$server->enqueue("HTTP/1.1 201 OK\r\nContent-Length: 0\r\n\r\n");
        $r = new Request('GET', self::$server->getUrl());
        $t = new Transaction(new Client(), $r);
        $a = $this->getAdapter();
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
        $a = $this->getAdapter();
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
