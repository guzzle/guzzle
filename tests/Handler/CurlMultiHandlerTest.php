<?php
namespace GuzzleHttp\Tests\Handler;

use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Tests\Server;
use GuzzleHttp\Utils;
use PHPUnit\Framework\TestCase;

class CurlMultiHandlerTest extends TestCase
{
    public function setUp()
    {
        $_SERVER['curl_test'] = true;
        unset($_SERVER['_curl_multi']);
    }

    public function tearDown()
    {
        unset($_SERVER['_curl_multi'], $_SERVER['curl_test']);
    }

    public function testCanAddCustomCurlOptions()
    {
        Server::flush();
        Server::enqueue([new Response()]);
        $a = new CurlMultiHandler(['options' => [
            CURLMOPT_MAXCONNECTS => 5,
        ]]);
        $request = new Request('GET', Server::$url);
        $a($request, []);
        self::assertEquals(5, $_SERVER['_curl_multi'][CURLMOPT_MAXCONNECTS]);
    }

    public function testSendsRequest()
    {
        Server::enqueue([new Response()]);
        $a = new CurlMultiHandler();
        $request = new Request('GET', Server::$url);
        $response = $a($request, [])->wait();
        self::assertSame(200, $response->getStatusCode());
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
        self::assertEquals(2, self::readAttribute($a, 'selectTimeout'));
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

        foreach ($responses as $r) {
            self::assertSame('rejected', $response->getState());
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
        self::assertSame('fulfilled', $response->getState());
    }

    public function testDelaysConcurrently()
    {
        Server::flush();
        Server::enqueue([new Response()]);
        $a = new CurlMultiHandler();
        $expected = Utils::currentTime() + (100 / 1000);
        $response = $a(new Request('GET', Server::$url), ['delay' => 100]);
        $response->wait();
        self::assertGreaterThanOrEqual($expected, Utils::currentTime());
    }

    public function testUsesTimeoutEnvironmentVariables()
    {
        $a = new CurlMultiHandler();

        //default if no options are given and no environment variable is set
        self::assertEquals(1, self::readAttribute($a, 'selectTimeout'));

        putenv("GUZZLE_CURL_SELECT_TIMEOUT=3");
        $a = new CurlMultiHandler();
        $selectTimeout = getenv('GUZZLE_CURL_SELECT_TIMEOUT');
        //Handler reads from the environment if no options are given
        self::assertEquals($selectTimeout, self::readAttribute($a, 'selectTimeout'));
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
