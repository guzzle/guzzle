<?php

namespace Guzzle\Tests\Http\Adapter\Curl;

require_once __DIR__ . '/../../Server.php';

use Guzzle\Stream\Stream;
use Guzzle\Tests\Http\Server;
use Guzzle\Http\Adapter\Curl\CurlFactory;
use Guzzle\Http\Adapter\Transaction;
use Guzzle\Http\Client;
use Guzzle\Http\Message\MessageFactory;
use Guzzle\Http\Message\Request;

/**
 * @covers Guzzle\Http\Adapter\Curl\CurlFactory
 */
class CurlFactoryTest extends \PHPUnit_Framework_TestCase
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

    public function testCreatesCurlHandle()
    {
        self::$server->flush();
        self::$server->enqueue(["HTTP/1.1 200 OK\r\nFoo: Bar\r\n Baz:  bam\r\nContent-Length: 2\r\n\r\nhi"]);
        $request = new Request('PUT', self::$server->getUrl() . 'haha', ['Hi' => ' 123'], Stream::factory('testing'));
        $stream = Stream::factory();
        $request->getConfig()->set('save_to', $stream);

        $t = new Transaction(new Client(), $request);
        $f = new CurlFactory();
        $h = $f->createHandle($t, new MessageFactory());
        $this->assertInternalType('resource', $h);
        curl_exec($h);
        $response = $t->getResponse();
        $this->assertInstanceOf('Guzzle\Http\Message\ResponseInterface', $response);
        $this->assertEquals('hi', $response->getBody());
        $this->assertEquals('Bar', $response->getHeader('Foo'));
        $this->assertEquals('bam', $response->getHeader('Baz'));
        curl_close($h);

        $sent = self::$server->getReceivedRequests(true)[0];
        $this->assertEquals('PUT', $sent->getMethod());
        $this->assertEquals('/haha', $sent->getPath());
        $this->assertEquals('123', $sent->getHeader('Hi'));
        $this->assertEquals('7', $sent->getHeader('Content-Length'));
        $this->assertEquals('testing', $sent->getBody());
        $this->assertEquals('1.1', $sent->getProtocolVersion());
        $this->assertEquals('hi', (string) $stream);
    }

    public function testSendsHeadRequests()
    {
        self::$server->flush();
        self::$server->enqueue(["HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\n"]);
        $request = new Request('HEAD', self::$server->getUrl());

        $t = new Transaction(new Client(), $request);
        $f = new CurlFactory();
        $h = $f->createHandle($t, new MessageFactory());
        curl_exec($h);
        curl_close($h);
        $response = $t->getResponse();
        $this->assertEquals('2', $response->getHeader('Content-Length'));
        $this->assertEquals('', $response->getBody());

        $sent = self::$server->getReceivedRequests(true)[0];
        $this->assertEquals('HEAD', $sent->getMethod());
        $this->assertEquals('/', $sent->getPath());
    }

    public function testSendsPostRequestWithNoBody()
    {
        self::$server->flush();
        self::$server->enqueue(["HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n"]);
        $request = new Request('POST', self::$server->getUrl());
        $t = new Transaction(new Client(), $request);
        $h = (new CurlFactory())->createHandle($t, new MessageFactory());
        curl_exec($h);
        curl_close($h);
        $sent = self::$server->getReceivedRequests(true)[0];
        $this->assertEquals('POST', $sent->getMethod());
        $this->assertEquals('', $sent->getBody());
    }

    public function testSendsChunkedRequests()
    {
        $stream = $this->getMockBuilder('Guzzle\Stream\Stream')
            ->setConstructorArgs([fopen('php://temp', 'r+')])
            ->setMethods(['getSize'])
            ->getMock();
        $stream->expects($this->any())
            ->method('getSize')
            ->will($this->returnValue(null));
        $stream->write('foo');
        $stream->seek(0);

        self::$server->flush();
        self::$server->enqueue(["HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n"]);
        $request = new Request('PUT', self::$server->getUrl(), [], $stream);
        $t = new Transaction(new Client(), $request);
        $h = (new CurlFactory())->createHandle($t, new MessageFactory());
        curl_exec($h);
        curl_close($h);
        $sent = self::$server->getReceivedRequests(false)[0];
        $this->assertContains('PUT / HTTP/1.1', $sent);
        $this->assertContains('transfer-encoding: chunked', strtolower($sent));
        $this->assertContains("\r\n\r\nfoo", $sent);
    }

    public function testDecodesGzippedResponses()
    {
        self::$server->flush();
        self::$server->enqueue(["HTTP/1.1 200 OK\r\nContent-Length: 3\r\n\r\nfoo"]);
        $request = new Request('GET', self::$server->getUrl(), ['Accept-Encoding' => '']);
        $t = new Transaction(new Client(), $request);
        $h = (new CurlFactory())->createHandle($t, new MessageFactory());
        curl_exec($h);
        curl_close($h);
        $this->assertEquals('foo', $t->getResponse()->getBody());
        $sent = self::$server->getReceivedRequests(true)[0];
        $this->assertContains('gzip', (string) $sent->getHeader('Accept-Encoding'));
    }
}
