<?php
namespace GuzzleHttp\Tests\Handler;

use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Tests\Server;

class CurlMultiHandlerTest extends \PHPUnit_Framework_TestCase
{
    public function testSendsRequest()
    {
        Server::enqueue([new Response()]);
        $a = new CurlMultiHandler();
        $request = new Request('GET', Server::$url);
        $response = $a($request, [])->wait();
        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * @expectedException \GuzzleHttp\Exception\ConnectException
     * @expectedExceptionMessage cURL error
     */
    public function testCreatesExceptions()
    {
        $a = new CurlMultiHandler();
        $a(new Request('GET', 'http://localhost:123'), [])->wait();
    }

    public function testCanSetMaxHandles()
    {
        $a = new CurlMultiHandler(['max_handles' => 2]);
        $this->assertEquals(2, $this->readAttribute($a, 'maxHandles'));
    }

    public function testCanSetSelectTimeout()
    {
        $a = new CurlMultiHandler(['select_timeout' => 2]);
        $this->assertEquals(2, $this->readAttribute($a, 'selectTimeout'));
    }

    public function testSendsWhenMaxHandlesIsReached()
    {
        $request = new Request('PUT', Server::$url);
        $response = new Response();
        Server::flush();
        Server::enqueue([$response, $response, $response]);
        $a = new CurlMultiHandler(['max_handles' => 3]);
        for ($i = 0; $i < 5; $i++) {
            $responses[] = $a($request, ['delay' => 0.1]);
        }
        $this->assertCount(3, Server::received());
        $responses[3]->cancel();
        $responses[4]->cancel();
    }

    public function testCanCancel()
    {
        Server::flush();
        $response = new Response(200);
        Server::enqueue(array_fill_keys(range(0, 10), $response));
        $a = new CurlMultiHandler();
        $responses = [];
        for ($i = 0; $i < 10; $i++) {
            $response = $a(new Request('GET', Server::$url), []);
            $response->cancel();
            $responses[] = $response;
        }
        $this->assertNotCount(10, Server::received());
    }

    public function testCannotCancelFinished()
    {
        Server::flush();
        Server::enqueue([new Response(200)]);
        $a = new CurlMultiHandler();
        $response = $a(new Request('GET', Server::$url), []);
        $response->wait();
        $response->cancel();
    }

    public function testDelaysConcurrently()
    {
        Server::flush();
        Server::enqueue([new Response()]);
        $a = new CurlMultiHandler();
        $expected = microtime(true) + (100 / 1000);
        $response = $a(new Request('GET', Server::$url), ['delay' => 100]);
        $response->wait();
        $this->assertGreaterThanOrEqual($expected, microtime(true));
    }

    /**
     * @expectedException \BadMethodCallException
     */
    public function throwsWhenAccessingInvalidProperty()
    {
        $h = new CurlMultiHandler();
        $h->foo;
    }
}
