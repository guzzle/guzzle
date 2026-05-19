<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests\Handler;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Promise as P;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Server\Server;
use GuzzleHttp\Tests\Helpers;
use GuzzleHttp\Utils;
use PHPUnit\Framework\TestCase;

class CurlMultiHandlerTest extends TestCase
{
    public function setUp(): void
    {
        $_SERVER['curl_test'] = true;
        unset($_SERVER['_curl_multi']);
    }

    public function tearDown(): void
    {
        unset($_SERVER['_curl_multi'], $_SERVER['curl_test']);
    }

    public function testCanAddCustomCurlOptions()
    {
        Server::flush();
        Server::enqueue([new Response()]);
        $a = new CurlMultiHandler(['options' => [
            \CURLMOPT_MAXCONNECTS => 5,
        ]]);
        $request = new Request('GET', Server::$url);
        $a($request, []);
        self::assertEquals(5, $_SERVER['_curl_multi'][\CURLMOPT_MAXCONNECTS]);
    }

    public function testSendsRequest()
    {
        Server::enqueue([new Response()]);
        $a = new CurlMultiHandler();
        $request = new Request('GET', Server::$url);
        $response = $a($request, [])->wait();
        self::assertSame(200, $response->getStatusCode());
    }

    public function testCreatesExceptions()
    {
        $a = new CurlMultiHandler();

        $this->expectException(ConnectException::class);
        $this->expectExceptionMessage('cURL error');
        $a(new Request('GET', 'http://localhost:123'), [])->wait();
    }

    public function testCanSetSelectTimeout()
    {
        $a = new CurlMultiHandler(['select_timeout' => 2]);
        self::assertEquals(2, Helpers::readObjectAttribute($a, 'selectTimeout'));
    }

    public function testDestructorDoesNotThrowWhenCurlMultiCloseFails()
    {
        $handler = new CurlMultiHandler();

        $setMultiHandle = \Closure::bind(static function (CurlMultiHandler $handler): void {
            $handler->_mh = new \stdClass();
        }, null, CurlMultiHandler::class);
        $hasMultiHandle = \Closure::bind(static function (CurlMultiHandler $handler): bool {
            return isset($handler->_mh);
        }, null, CurlMultiHandler::class);

        $setMultiHandle($handler);
        \set_error_handler(static function (int $severity, string $message, string $file, int $line): void {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            $handler->__destruct();
        } finally {
            \restore_error_handler();
        }

        self::assertFalse($hasMultiHandle($handler));
    }

    public function testCanCancel()
    {
        Server::flush();
        $response = new Response(200);
        Server::enqueue(\array_fill_keys(\range(0, 10), $response));
        $a = new CurlMultiHandler();
        $responses = [];
        for ($i = 0; $i < 10; ++$i) {
            $response = $a(new Request('GET', Server::$url), []);
            $response->cancel();
            $responses[] = $response;
        }

        foreach ($responses as $r) {
            self::assertTrue(P\Is::rejected($r));
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
        self::assertTrue(P\Is::fulfilled($response));
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

    public function throwsWhenAccessingInvalidProperty()
    {
        $h = new CurlMultiHandler();

        $this->expectException(\BadMethodCallException::class);
        $h->foo;
    }
}
