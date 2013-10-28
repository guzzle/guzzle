<?php

namespace Guzzle\Tests\Http\Pool;

require_once __DIR__ . '/../Server.php';

use Guzzle\Http\Adapter\StreamAdapter;
use Guzzle\Http\Adapter\Transaction;
use Guzzle\Http\Client;
use Guzzle\Http\ClientInterface;
use Guzzle\Http\Event\RequestErrorEvent;
use Guzzle\Http\Message\FutureResponse;
use Guzzle\Http\Message\MessageFactory;
use Guzzle\Http\Message\Request;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Pool\ExceptionHandlerPool;
use Guzzle\Http\Pool\Pool;
use Guzzle\Tests\Http\Server;

/**
 * @covers Guzzle\Http\Pool\ExceptionHandlerPool
 */
class ExceptionHandlerPoolTest extends \PHPUnit_Framework_TestCase
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

    public function testYieldsGoodResponses()
    {
        self::$server->flush();
        self::$server->enqueue([
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 201 OK\r\nContent-Length: 0\r\n\r\n"
        ]);
        $client = new Client(['base_url' => self::$server->getUrl()]);
        $pool = new ExceptionHandlerPool(new Pool($client, 2), function () {});
        $gen = function (ClientInterface $client) {
            for ($i = 0; $i < 2; $i++) {
                yield $client->createRequest('GET', '/' . $i);
            }
        };
        foreach ($pool->send($gen($client)) as $request => $response) {
            $this->assertInstanceOf('Guzzle\Http\Message\RequestInterface', $request);
            $this->assertInstanceOf('Guzzle\Http\Message\ResponseInterface', $response);
        }

        $this->assertCount(2, self::$server->getReceivedRequests());
    }

    public function testEmitsErrorEvents()
    {
        self::$server->flush();
        self::$server->enqueue([
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 201 OK\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 404 Not Found\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 201 OK\r\nContent-Length: 0\r\n\r\n"
        ]);
        $client = new Client(['base_url' => self::$server->getUrl()]);
        $ev = null;
        $pool = new ExceptionHandlerPool(new Pool($client), function (RequestErrorEvent $e) use (&$ev) {
            $ev = $e;
        });

        $requests = [
            $client->createRequest('GET', '/'),
            $client->createRequest('GET', '/'),
            $client->createRequest('GET', '/'),
            $client->createRequest('GET', '/')
        ];

        foreach ($pool->send($requests) as $request => $response) {
            $this->assertInstanceOf('Guzzle\Http\Message\RequestInterface', $request);
            $this->assertInstanceOf('Guzzle\Http\Message\ResponseInterface', $response);
        }

        $this->assertInstanceOf('Guzzle\Http\Event\RequestErrorEvent', $ev);
        $this->assertCount(4, self::$server->getReceivedRequests());
    }
}
