<?php

namespace Guzzle\Tests;

require_once __DIR__ . '/Http/Server.php';

use Guzzle\Http\Message\Response;
use Guzzle\Tests\Http\Server;

class FunctionsTest extends \PHPUnit_Framework_TestCase
{
    /** @var \Guzzle\Tests\Http\Server */
    public static $server;

    public static function setupBeforeClass()
    {
        self::$server = new Server();
        self::$server->start();
        self::$server->flush();
    }

    public static function tearDownAfterClass()
    {
        self::$server->stop();
    }

    public function testExpandsTemplate()
    {
        $this->assertEquals('foo/123', \Guzzle\uriTemplate('foo/{bar}', ['bar' => '123']));
    }

    public function noBodyProvider()
    {
        return [['get'], ['head'], ['delete']];
    }

    /**
     * @dataProvider noBodyProvider
     */
    public function testSendsNoBody($method)
    {
        self::$server->flush();
        self::$server->enqueue([new Response(200)]);
        call_user_func("Guzzle\\{$method}", self::$server->getUrl(), [
            'headers' => ['foo' => 'bar'],
            'query' => ['a' => '1']
        ]);
        $sent = self::$server->getReceivedRequests(true)[0];
        $this->assertEquals(strtoupper($method), $sent->getMethod());
        $this->assertEquals('/?a=1', $sent->getResource());
        $this->assertEquals('bar', $sent->getHeader('foo'));
    }

    public function testSendsOptionsRequest()
    {
        self::$server->flush();
        self::$server->enqueue([new Response(200)]);
        \Guzzle\options(self::$server->getUrl(), ['headers' => ['foo' => 'bar']]);
        $sent = self::$server->getReceivedRequests(true)[0];
        $this->assertEquals('OPTIONS', $sent->getMethod());
        $this->assertEquals('/', $sent->getResource());
        $this->assertEquals('bar', $sent->getHeader('foo'));
    }

    public function hasBodyProvider()
    {
        return [['put'], ['post'], ['patch']];
    }

    /**
     * @dataProvider hasBodyProvider
     */
    public function testSendsWithBody($method)
    {
        self::$server->flush();
        self::$server->enqueue([new Response(200)]);
        call_user_func("Guzzle\\{$method}", self::$server->getUrl(), [
            'headers' => ['foo' => 'bar'],
            'body'    => 'test',
            'query'   => ['a' => '1']
        ]);
        $sent = self::$server->getReceivedRequests(true)[0];
        $this->assertEquals(strtoupper($method), $sent->getMethod());
        $this->assertEquals('/?a=1', $sent->getResource());
        $this->assertEquals('bar', $sent->getHeader('foo'));
        $this->assertEquals('test', $sent->getBody());
    }
}
