<?php

declare(strict_types=1);

namespace GuzzleHttp\Test\Handler;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Handler\CurlShare;
use GuzzleHttp\Handler\CurlShareHandleState;
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

    public function testShareOptionAppliesCurlShare(): void
    {
        self::skipIfCurlShareIsUnavailable();

        $_SERVER['curl_test'] = true;
        unset($_SERVER['_curl'], $_SERVER['_curl_share'], $_SERVER['_curl_share_init_count']);
        Server::flush();
        Server::enqueue([new Response(200)]);

        try {
            $handler = new CurlHandler([
                'share' => CurlShare::HANDLER,
            ]);

            $handler(new Request('GET', Server::$url), [])->wait();

            self::assertArrayHasKey(\CURLOPT_SHARE, $_SERVER['_curl']);
            self::assertSame(1, $_SERVER['_curl_share_init_count']);
            self::assertSame([
                \CURL_LOCK_DATA_DNS,
                \CURL_LOCK_DATA_SSL_SESSION,
            ], $_SERVER['_curl_share'][\CURLSHOPT_SHARE]);
        } finally {
            unset($_SERVER['curl_test'], $_SERVER['_curl'], $_SERVER['_curl_share'], $_SERVER['_curl_share_init_count']);
        }
    }

    public function testPersistentPreferShareOptionAppliesCurlShare(): void
    {
        self::skipIfCurlShareIsUnavailable();

        $_SERVER['curl_test'] = true;
        unset(
            $_SERVER['_curl'],
            $_SERVER['_curl_share'],
            $_SERVER['_curl_share_init_count'],
            $_SERVER['_curl_share_init_persistent_count'],
            $_SERVER['_curl_share_persistent_options']
        );
        Server::flush();
        Server::enqueue([new Response(200)]);

        try {
            $handler = new CurlHandler([
                'share' => CurlShare::PERSISTENT_PREFER,
            ]);

            $handler(new Request('GET', Server::$url), [])->wait();

            self::assertArrayHasKey(\CURLOPT_SHARE, $_SERVER['_curl']);
            self::assertPersistentPreferShareWasCreated();
        } finally {
            unset(
                $_SERVER['curl_test'],
                $_SERVER['_curl'],
                $_SERVER['_curl_share'],
                $_SERVER['_curl_share_init_count'],
                $_SERVER['_curl_share_init_persistent_count'],
                $_SERVER['_curl_share_persistent_options']
            );
        }
    }

    /**
     * @dataProvider enabledShareModeProvider
     */
    public function testShareOptionCannotBeUsedWithCustomFactory(string $shareMode): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('handle_factory');

        new CurlHandler([
            'handle_factory' => new CurlFactory(0),
            'share' => $shareMode,
        ]);
    }

    public static function enabledShareModeProvider(): iterable
    {
        yield 'handler' => [CurlShare::HANDLER];
        yield 'persistent prefer' => [CurlShare::PERSISTENT_PREFER];
        yield 'persistent require' => [CurlShare::PERSISTENT_REQUIRE];
    }

    public function testDisabledShareOptionCanBeUsedWithCustomFactory(): void
    {
        $handler = new CurlHandler([
            'handle_factory' => new CurlFactory(0),
            'share' => CurlShare::NONE,
        ]);

        self::assertInstanceOf(CurlHandler::class, $handler);
    }

    public function testCloseReleasesShareHandleState(): void
    {
        self::skipIfCurlShareIsUnavailable();

        $handler = new CurlHandler([
            'share' => CurlShare::HANDLER,
        ]);

        self::assertNotNull(self::readShareHandleState($handler));

        $handler->close();

        self::assertNull(self::readShareHandleState($handler));
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

    private static function readShareHandleState(CurlHandler $handler): ?CurlShareHandleState
    {
        $readShareHandleState = \Closure::bind(static function (CurlHandler $handler): ?CurlShareHandleState {
            return $handler->shareHandleState;
        }, null, CurlHandler::class);

        return $readShareHandleState($handler);
    }

    private static function skipIfCurlShareIsUnavailable(): void
    {
        if (
            !\function_exists('curl_share_init')
            || !\function_exists('curl_share_setopt')
            || !\defined('CURLOPT_SHARE')
        ) {
            self::markTestSkipped('cURL share handles are unavailable.');
        }
    }

    private static function assertPersistentPreferShareWasCreated(): void
    {
        if (
            \function_exists('curl_share_init_persistent')
            && \class_exists('CurlSharePersistentHandle')
            && \defined('CURL_LOCK_DATA_DNS')
            && \defined('CURL_LOCK_DATA_CONNECT')
            && \defined('CURL_LOCK_DATA_SSL_SESSION')
        ) {
            self::assertSame(1, $_SERVER['_curl_share_init_persistent_count']);
            self::assertSame([
                \CURL_LOCK_DATA_DNS,
                \CURL_LOCK_DATA_CONNECT,
                \CURL_LOCK_DATA_SSL_SESSION,
            ], $_SERVER['_curl_share_persistent_options']);

            return;
        }

        self::assertSame(1, $_SERVER['_curl_share_init_count']);
        self::assertSame([
            \CURL_LOCK_DATA_DNS,
            \CURL_LOCK_DATA_SSL_SESSION,
        ], $_SERVER['_curl_share'][\CURLSHOPT_SHARE]);
    }
}
