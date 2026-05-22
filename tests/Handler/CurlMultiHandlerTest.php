<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests\Handler;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\HandlerClosedException;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Promise as P;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Server\Server;
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

    public function testCanAddCustomCurlOptions(): void
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

    public function testSendsRequest(): void
    {
        Server::enqueue([new Response()]);
        $a = new CurlMultiHandler();
        $request = new Request('GET', Server::$url);
        $response = $a($request, [])->wait();
        self::assertSame(200, $response->getStatusCode());
    }

    public function testCreatesExceptions(): void
    {
        $a = new CurlMultiHandler();

        $this->expectException(ConnectException::class);
        $this->expectExceptionMessage('cURL error');
        $a(new Request('GET', 'http://localhost:123'), [])->wait();
    }

    public function testCanSetSelectTimeout(): void
    {
        $a = new CurlMultiHandler(['select_timeout' => 2]);
        self::assertEquals(2, self::readSelectTimeout($a));
    }

    public function testDestructorDoesNotThrowWhenCurlMultiCloseFails(): void
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

    public function testCloseRejectsActiveTransfer(): void
    {
        $handler = new CurlMultiHandler();
        $promise = $handler(new Request('GET', Server::$url), []);

        $handler->close();

        self::assertTrue(P\Is::rejected($promise));

        $this->expectException(HandlerClosedException::class);
        $this->expectExceptionMessage('The cURL multi handler was closed before the transfer completed.');

        $promise->wait();
    }

    public function testCloseRejectsDelayedTransferWithoutInitializingMultiHandle(): void
    {
        $handler = new CurlMultiHandler();
        $promise = $handler(new Request('GET', Server::$url), ['delay' => 10000]);

        self::assertFalse(self::hasMultiHandle($handler));

        $handler->close();

        self::assertFalse(self::hasMultiHandle($handler));
        self::assertTrue(P\Is::rejected($promise));
    }

    public function testCloseDoesNotRunPromiseQueue(): void
    {
        $handler = new CurlMultiHandler();
        $called = false;

        $promise = $handler(new Request('GET', Server::$url), []);
        $promise->otherwise(static function () use (&$called): void {
            $called = true;
        });

        try {
            $handler->close();

            self::assertTrue(P\Is::rejected($promise));
            self::assertFalse($called);
        } finally {
            P\Utils::queue()->run();
        }
    }

    public function testDestructorDoesNotRejectPendingPromise(): void
    {
        $handler = new CurlMultiHandler();
        $promise = $handler(new Request('GET', Server::$url), ['delay' => 10000]);

        $handler->__destruct();

        self::assertTrue(P\Is::pending($promise));
    }

    public function testClosePreventsReuse(): void
    {
        $handler = new CurlMultiHandler();
        $handler->close();

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Cannot use the cURL multi handler after it has been closed.');

        $handler(new Request('GET', Server::$url), []);
    }

    public function testTickAfterCloseThrows(): void
    {
        $handler = new CurlMultiHandler();
        $handler->close();

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Cannot use the cURL multi handler after it has been closed.');

        $handler->tick();
    }

    public function testExecuteAfterCloseThrows(): void
    {
        $handler = new CurlMultiHandler();
        $handler->close();

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Cannot use the cURL multi handler after it has been closed.');

        $handler->execute();
    }

    public function testCloseIsIdempotent(): void
    {
        $handler = new CurlMultiHandler();

        $handler->close();
        $handler->close();

        self::assertFalse(self::hasMultiHandle($handler));
    }

    public function testCloseClosesInternallyCreatedFactory(): void
    {
        $handler = new CurlMultiHandler();
        $factory = self::readFactory($handler);

        $handler->close();

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Cannot use the cURL factory after it has been closed.');

        $factory->create(new Request('GET', Server::$url), []);
    }

    public function testCloseDoesNotCloseInjectedFactory(): void
    {
        $factory = new class(3) extends CurlFactory {
            /** @var bool */
            public $closeCalled = false;

            public function close(): void
            {
                $this->closeCalled = true;

                parent::close();
            }
        };
        $handler = new CurlMultiHandler(['handle_factory' => $factory]);

        $handler->close();

        self::assertFalse($factory->closeCalled);
    }

    public function testClosePendingTransferLeavesResourceSinkOpen(): void
    {
        $sink = \fopen('php://temp', 'w+');
        self::assertIsResource($sink);

        $handler = new CurlMultiHandler();
        $promise = $handler(new Request('GET', Server::$url), [
            'delay' => 10000,
            'sink' => $sink,
        ]);

        try {
            $handler->close();

            self::assertTrue(P\Is::rejected($promise));
            self::assertIsResource($sink);
            self::assertNotFalse(\fwrite($sink, 'still open'));
        } finally {
            if (\is_resource($sink)) {
                \fclose($sink);
            }
        }
    }

    public function testCloseActiveTransferLeavesResourceSinkOpen(): void
    {
        $sink = \fopen('php://temp', 'w+');
        self::assertIsResource($sink);

        $handler = new CurlMultiHandler();
        $promise = $handler(new Request('GET', Server::$url), ['sink' => $sink]);

        try {
            $handler->close();

            self::assertTrue(P\Is::rejected($promise));
            self::assertIsResource($sink);
            self::assertNotFalse(\fwrite($sink, 'still open'));
        } finally {
            if (\is_resource($sink)) {
                \fclose($sink);
            }
        }
    }

    public function testCanCancel(): void
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

    public function testCannotCancelFinished(): void
    {
        Server::flush();
        Server::enqueue([new Response(200)]);
        $a = new CurlMultiHandler();
        $response = $a(new Request('GET', Server::$url), []);
        $response->wait();
        $response->cancel();
        self::assertTrue(P\Is::fulfilled($response));
    }

    public function testDelaysConcurrently(): void
    {
        Server::flush();
        Server::enqueue([new Response()]);
        $a = new CurlMultiHandler();
        $expected = Utils::currentTime() + (100 / 1000);
        $response = $a(new Request('GET', Server::$url), ['delay' => 100]);
        $response->wait();
        self::assertGreaterThanOrEqual($expected, Utils::currentTime());
    }

    public function throwsWhenAccessingInvalidProperty(): void
    {
        $h = new CurlMultiHandler();

        $this->expectException(\BadMethodCallException::class);
        $h->foo;
    }

    private static function readSelectTimeout(CurlMultiHandler $handler)
    {
        $readSelectTimeout = \Closure::bind(static function (CurlMultiHandler $handler) {
            return $handler->selectTimeout;
        }, null, CurlMultiHandler::class);

        return $readSelectTimeout($handler);
    }

    private static function hasMultiHandle(CurlMultiHandler $handler): bool
    {
        $hasMultiHandle = \Closure::bind(static function (CurlMultiHandler $handler): bool {
            return isset($handler->_mh);
        }, null, CurlMultiHandler::class);

        return $hasMultiHandle($handler);
    }

    private static function readFactory(CurlMultiHandler $handler): CurlFactory
    {
        $readFactory = \Closure::bind(static function (CurlMultiHandler $handler) {
            return $handler->factory;
        }, null, CurlMultiHandler::class);

        $factory = $readFactory($handler);
        self::assertInstanceOf(CurlFactory::class, $factory);

        return $factory;
    }
}
