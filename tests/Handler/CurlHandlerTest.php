<?php

declare(strict_types=1);

namespace GuzzleHttp\Test\Handler;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Server\Server;
use GuzzleHttp\Utils;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GuzzleHttp\Handler\CurlHandler
 */
class CurlHandlerTest extends TestCase
{
    protected function getHandler(array $options = []): CurlHandler
    {
        return new CurlHandler($options);
    }

    public function testCreatesCurlErrors(): void
    {
        $handler = new CurlHandler();
        $request = new Request('GET', 'http://localhost:123');

        $this->expectException(ConnectException::class);
        $this->expectExceptionMessage('cURL');
        $handler($request, ['timeout' => 0.001, 'connect_timeout' => 0.001])->wait();
    }

    public function testRedactsUserInfoInErrors(): void
    {
        $handler = new CurlHandler();
        $request = new Request('GET', 'http://my_user:secretPass@localhost:123');

        try {
            $handler($request, ['timeout' => 0.001, 'connect_timeout' => 0.001])->wait();
            $this->fail('Must throw an Exception.');
        } catch (\Throwable $e) {
            $this->assertStringNotContainsString('secretPass', $e->getMessage());
        }
    }

    public function testReusesHandles(): void
    {
        Server::flush();
        $response = new Response(200);
        Server::enqueue([$response, $response]);
        $a = new CurlHandler();
        $request = new Request('GET', Server::$url);
        self::assertInstanceOf(FulfilledPromise::class, $a($request, []));
        self::assertInstanceOf(FulfilledPromise::class, $a($request, []));
    }

    public function testClosePreventsReuse(): void
    {
        $handler = new CurlHandler();
        $handler->close();

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Cannot use the cURL handler after it has been closed.');

        $handler(new Request('GET', Server::$url), []);
    }

    public function testCloseClosesInternallyCreatedFactory(): void
    {
        $handler = new CurlHandler();
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
        $handler = new CurlHandler(['handle_factory' => $factory]);

        $handler->close();

        self::assertFalse($factory->closeCalled);
    }

    public function testDoesSleep(): void
    {
        $response = new Response(200);
        Server::enqueue([$response]);
        $a = new CurlHandler();
        $request = new Request('GET', Server::$url);
        $s = Utils::currentTime();
        $a($request, ['delay' => 0.1])->wait();
        self::assertGreaterThan(0.0001, Utils::currentTime() - $s);
    }

    public function testCreatesCurlErrorsWithContext(): void
    {
        $handler = new CurlHandler();
        $request = new Request('GET', 'http://localhost:123');
        $called = false;
        $p = $handler($request, ['timeout' => 0.001, 'connect_timeout' => 0.001])
            ->otherwise(static function (ConnectException $e) use (&$called): void {
                $called = true;
                self::assertArrayHasKey('errno', $e->getHandlerContext());
            });
        $p->wait();
        self::assertTrue($called);
    }

    public function testUsesContentLengthWhenOverInMemorySize(): void
    {
        Server::flush();
        Server::enqueue([new Response()]);
        $stream = Psr7\Utils::streamFor(\str_repeat('.', 1000000));
        $handler = new CurlHandler();
        $request = new Request(
            'PUT',
            Server::$url,
            ['Content-Length' => '1000000'],
            $stream
        );
        $handler($request, [])->wait();
        $received = Server::received()[0];
        self::assertEquals(1000000, $received->getHeaderLine('Content-Length'));
        self::assertFalse($received->hasHeader('Transfer-Encoding'));
    }

    private static function readFactory(CurlHandler $handler): CurlFactory
    {
        $readFactory = \Closure::bind(static function (CurlHandler $handler) {
            return $handler->factory;
        }, null, CurlHandler::class);

        $factory = $readFactory($handler);
        self::assertInstanceOf(CurlFactory::class, $factory);

        return $factory;
    }
}
