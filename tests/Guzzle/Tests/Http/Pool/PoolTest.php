<?php

namespace Guzzle\Tests\Http\Pool;

require_once __DIR__ . '/../Server.php';

use Guzzle\Http\Adapter\StreamAdapter;
use Guzzle\Http\Adapter\Transaction;
use Guzzle\Http\Client;
use Guzzle\Http\ClientInterface;
use Guzzle\Http\Exception\RequestException;
use Guzzle\Http\Message\FutureResponse;
use Guzzle\Http\Message\MessageFactory;
use Guzzle\Http\Message\Request;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Pool\Pool;
use Guzzle\Tests\Http\Server;

/**
 * @covers Guzzle\Http\Pool\Pool
 */
class PoolTest extends \PHPUnit_Framework_TestCase
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

    public function testYieldsImmediatelyForNonFutureResponses()
    {
        $request = new Request('GET', '/');
        $response = new Response(200);
        $client = $this->getMockBuilder('Guzzle\Http\ClientInterface')
            ->setMethods(['send'])
            ->getMockForAbstractClass();
        $client->expects($this->once())
            ->method('send')
            ->with($request)
            ->will($this->returnValue($response));
        $pool = new Pool($client);
        $results = $pool->send([$request]);
        $this->assertEquals([$response], iterator_to_array($results, false));
    }

    public function testYieldsImmediatelyForNonBatchableAdapters()
    {
        $request = new Request('GET', '/');
        $client = $this->getMockBuilder('Guzzle\Http\ClientInterface')
            ->setMethods(['send'])
            ->getMockForAbstractClass();
        $response = new FutureResponse(new Transaction($client, $request), new StreamAdapter(new MessageFactory()));
        $client->expects($this->once())
            ->method('send')
            ->with($request)
            ->will($this->returnValue($response));
        $pool = new Pool($client);
        $results = $pool->send([$request]);
        $this->assertEquals([$response], iterator_to_array($results, false));
    }

    public function testYieldsResponsesAsTheyComplete()
    {
        self::$server->flush();
        self::$server->enqueue([
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 201 OK\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 202 OK\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 203 OK\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 203 OK\r\nContent-Length: 0\r\n\r\n"
        ]);
        $client = new Client(['base_url' => self::$server->getUrl()]);
        $pool = new Pool($client, 2);
        $gen = function (ClientInterface $client) {
            for ($i = 0; $i < 4; $i++) {
                yield $client->createRequest('GET', '/' . $i);
            }
        };
        foreach ($pool->send($gen($client)) as $request => $response) {
            $this->assertInstanceOf('Guzzle\Http\Message\RequestInterface', $request);
            $this->assertInstanceOf('Guzzle\Http\Message\ResponseInterface', $response);
        }
    }

    public function testThrowsExceptionsImmediately()
    {
        self::$server->flush();
        self::$server->enqueue([
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 404 Not Found\r\nContent-Length: 0\r\n\r\n"
        ]);

        $client = new Client(['base_url' => self::$server->getUrl()]);
        $pool = new Pool($client, 2);

        $requests = [
            $client->createRequest('GET', '/'),
            $client->createRequest('GET', '/'),
            $client->createRequest('GET', '/')
        ];

        try {
            iterator_to_array($pool->send($requests), false);
            $this->fail('Did not throw');
        } catch (RequestException $e) {
            $this->assertCount(2, self::$server->getReceivedRequests());
        }
    }
}
