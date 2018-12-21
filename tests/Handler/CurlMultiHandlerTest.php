<?php
namespace GuzzleHttp\Tests\Handler;

use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Tests\Server;
use PHPUnit\Framework\TestCase;

class CurlMultiHandlerTest extends TestCase
{
    public function testSendsRequest()
    {
        Server::enqueue([new Response()]);
        $a = new CurlMultiHandler();
        $request = new Request('GET', Server::$url);
        $response = $a($request, [])->wait();
        $this->assertSame(200, $response->getStatusCode());
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

    public function testCanSetSelectTimeout()
    {
        $a = new CurlMultiHandler(['select_timeout' => 2]);
        $this->assertEquals(2, $this->readAttribute($a, 'selectTimeout'));
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

        foreach($responses as $r) {
            $this->assertSame('rejected', $response->getState());
        }
    }

    public function testCannotCancelFinished()
    {
        Server::flush();
        Server::enqueue([new Response(200)]);
        $a = new CurlMultiHandler();
        $response = $a(new Request('GET', Server::$url), []);
        $response->wait();
        $response->cancel();
        $this->assertSame('fulfilled', $response->getState());
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

    public function testUsesTimeoutEnvironmentVariables()
    {
        $a = new CurlMultiHandler();

        //default if no options are given and no environment variable is set
        $this->assertEquals(1, $this->readAttribute($a, 'selectTimeout'));

        putenv("GUZZLE_CURL_SELECT_TIMEOUT=3");
        $a = new CurlMultiHandler();
        $selectTimeout = getenv('GUZZLE_CURL_SELECT_TIMEOUT');
        //Handler reads from the environment if no options are given
        $this->assertEquals($selectTimeout, $this->readAttribute($a, 'selectTimeout'));
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
