<?php

namespace Guzzle\Tests;

use \Guzzle;
use Guzzle\Plugin\History\HistoryPlugin;

/**
 * @group server
 * @covers \Guzzle
 */
class GuzzleTest extends \Guzzle\Tests\GuzzleTestCase
{
    public function setUp()
    {
        $this->getServer()->flush();
        $this->getServer()->enqueue(array("HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n"));
    }

    public function httpMethodProvider()
    {
        return array_map(function ($method) { return array($method); }, array(
            'get', 'head', 'put', 'post', 'delete', 'options', 'patch'
        ));
    }

    /**
     * @dataProvider httpMethodProvider
     */
    public function testSendsHttpRequestsWithMethod($method)
    {
        Guzzle::$method($this->getServer()->getUrl());
        $requests = $this->getServer()->getReceivedRequests(true);
        $this->assertEquals(strtoupper($method), $requests[0]->getMethod());
    }

    public function testCanDisableRedirects()
    {
        $this->getServer()->enqueue(array(
            "HTTP/1.1 307\r\nLocation: " . $this->getServer()->getUrl() . "\r\nContent-Length: 0\r\n\r\n"
        ));
        $response = Guzzle::get($this->getServer()->getUrl(), array('allow_redirects' => false));
        $this->assertEquals(307, $response->getStatusCode());
    }

    public function testCanAddCookies()
    {
        Guzzle::get($this->getServer()->getUrl(), array('cookies' => array('Foo' => 'Bar')));
        $requests = $this->getServer()->getReceivedRequests(true);
        $this->assertEquals('Bar', $requests[0]->getCookie('Foo'));
    }

    public function testCanAddQueryString()
    {
        Guzzle::get($this->getServer()->getUrl(), array('query' => array('Foo' => 'Bar')));
        $requests = $this->getServer()->getReceivedRequests(true);
        $this->assertEquals('Bar', $requests[0]->getQuery()->get('Foo'));
    }

    public function testCanAddCurl()
    {
        Guzzle::get($this->getServer()->getUrl(), array('curl' => array(CURLOPT_ENCODING => '*')));
        $requests = $this->getServer()->getReceivedRequests(true);
        $this->assertEquals('*/*', (string) $requests[0]->getHeader('Accept'));
    }

    public function testCanAddAuth()
    {
        Guzzle::get($this->getServer()->getUrl(), array('auth' => array('michael', 'test')));
        $requests = $this->getServer()->getReceivedRequests(true);
        $this->assertEquals('Basic bWljaGFlbDp0ZXN0', (string) $requests[0]->getHeader('Authorization'));
    }

    public function testCanAddEvents()
    {
        $foo = null;
        Guzzle::get($this->getServer()->getUrl(), array(
            'events' => array(
                'request.complete' => function () use (&$foo) { $foo = true; }
            )
        ));
        $this->assertTrue($foo);
    }

    public function testCanAddPlugins()
    {
        $history = new HistoryPlugin();
        Guzzle::get($this->getServer()->getUrl(), array('plugins' => array($history)));
        $this->assertEquals(1, count($history));
    }

    public function testCanCreateStreams()
    {
        $response = Guzzle::get($this->getServer()->getUrl(), array('stream' => true));
        $this->assertInstanceOf('Guzzle\Stream\StreamInterface', $response);
    }

    public function testCanCreateStreamsWithCustomFactory()
    {
        $f = $this->getMockBuilder('Guzzle\Stream\StreamRequestFactoryInterface')
            ->setMethods(array('fromRequest'))
            ->getMock();
        $f->expects($this->once())
            ->method('fromRequest');
        Guzzle::get($this->getServer()->getUrl(), array('stream' => $f));
    }
}
