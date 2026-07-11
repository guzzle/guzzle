<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests\Handler;

use GuzzleHttp\Exception\InvalidArgumentException;
use GuzzleHttp\Exception\NetworkException;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Handler\CurlFactoryInterface;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Handler\CurlShareHandleState;
use GuzzleHttp\Handler\CurlVersion;
use GuzzleHttp\Handler\EasyHandle;
use GuzzleHttp\Multiplexing;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\Server\Server;
use GuzzleHttp\Tests\Psr17SpyFactory;
use GuzzleHttp\Tests\SpyResponse;
use GuzzleHttp\Tests\SpyStream;
use GuzzleHttp\Tests\StrictReadableResourceStreamFactory;
use GuzzleHttp\TransportSharing;
use GuzzleHttp\Utils;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * @covers \GuzzleHttp\Handler\CurlHandler
 */
class CurlHandlerTest extends TestCase
{
    protected function getHandler(array $options = []): CurlHandler
    {
        return new CurlHandler($options);
    }

    public function testAllowsMultiplexNoneRequests(): void
    {
        Server::flush();
        Server::enqueue([new Response()]);
        $handler = new CurlHandler();
        $response = $handler(new Request('GET', Server::$url, [], null, '1.1'), ['multiplex' => Multiplexing::NONE])->wait();

        self::assertSame(200, $response->getStatusCode());
    }

    public function testAllowsMultiplexNoneRequestsForHttp2(): void
    {
        if (!CurlVersion::supportsHttp2()) {
            self::markTestSkipped('HTTP/2 support is unavailable.');
        }

        // One half of the default stack's sync/async fork: CurlHandler
        // satisfies Multiplexing::NONE for any protocol version, while
        // CurlMultiHandlerTest pins the asynchronous HTTP/2 rejection.
        Server::flush();
        Server::enqueue([new Response()]);
        $handler = new CurlHandler();
        $response = $handler(new Request('GET', Server::$url, [], null, '2.0'), ['multiplex' => Multiplexing::NONE])->wait();

        self::assertSame(200, $response->getStatusCode());
    }

    public function testAllowsMultiplexNoneRequestsWithPersistentTransportSharing(): void
    {
        self::skipIfCurlShareIsUnavailable();

        // A blocking transfer runs alone, and even under persistent sharing
        // an in-use connection cannot be joined from another multi handle.
        Server::flush();
        Server::enqueue([new Response()]);
        $handler = new CurlHandler([
            'transport_sharing' => TransportSharing::PERSISTENT_PREFER,
        ]);
        $response = $handler(new Request('GET', Server::$url, [], null, '1.1'), ['multiplex' => Multiplexing::NONE])->wait();

        self::assertSame(200, $response->getStatusCode());
    }

    public function testCreatesCurlErrors(): void
    {
        $handler = new CurlHandler();
        $request = new Request('GET', 'http://localhost:123');

        $this->expectException(NetworkException::class);
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

    public function testResponseMessageAndBodyAreBuiltViaConfiguredFactories(): void
    {
        Server::flush();
        Server::enqueue([new Response(200, ['Foo' => 'Bar'], 'hi there')]);
        $handler = new CurlHandler();
        $factory = new Psr17SpyFactory();

        $response = $handler(new Request('GET', Server::$url), [
            RequestOptions::STREAM_FACTORY => $factory,
            RequestOptions::RESPONSE_FACTORY => $factory,
        ])->wait();

        self::assertInstanceOf(SpyResponse::class, $response);
        self::assertInstanceOf(SpyStream::class, $response->getBody());
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Bar', $response->getHeaderLine('Foo'));
        self::assertSame('hi there', (string) $response->getBody());
        self::assertSame(1, $factory->createResponseCalls);
        self::assertGreaterThanOrEqual(1, $factory->createStreamFromResourceCalls);
    }

    public function testCallerOwnedWriteOnlyResourceSinkDoesNotUseStreamFactory(): void
    {
        $tmpfname = \tempnam(\sys_get_temp_dir(), 'guzzle-sink');
        self::assertIsString($tmpfname);
        $sink = null;

        try {
            Server::flush();
            Server::enqueue([new Response(200, [], 'hi there')]);
            $handler = new CurlHandler();
            $factory = new StrictReadableResourceStreamFactory();
            $sink = Psr7\Utils::tryFopen($tmpfname, 'w');

            $response = $handler(new Request('GET', Server::$url), [
                RequestOptions::SINK => $sink,
                RequestOptions::STREAM_FACTORY => $factory,
            ])->wait();

            self::assertSame(200, $response->getStatusCode());
            self::assertSame(0, $factory->createStreamFromResourceCalls);
            $response->getBody()->close();
            self::assertIsResource($sink);
            \fclose($sink);
            $sink = null;
            self::assertSame('hi there', \file_get_contents($tmpfname));
        } finally {
            if (\is_resource($sink)) {
                \fclose($sink);
            }
            @\unlink($tmpfname);
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

    public function testRejectsUnknownConstructorOption(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid CurlHandler constructor option "unknown".');

        new CurlHandler(['unknown' => true]);
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
        $factory = new class implements CurlFactoryInterface {
            /** @var bool */
            public $closeCalled = false;

            public function create(RequestInterface $request, array $options): EasyHandle
            {
                throw new \BadMethodCallException('Unexpected create call.');
            }

            public function release(EasyHandle $easy): void
            {
                throw new \BadMethodCallException('Unexpected release call.');
            }

            public function close(): void
            {
                $this->closeCalled = true;
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

    public function testTransportSharingOptionAppliesCurlShare(): void
    {
        self::skipIfCurlShareIsUnavailable();

        $_SERVER['curl_test'] = true;
        unset($_SERVER['_curl'], $_SERVER['_curl_share'], $_SERVER['_curl_share_init_count']);
        Server::flush();
        Server::enqueue([new Response(200)]);

        try {
            $handler = new CurlHandler([
                'transport_sharing' => TransportSharing::HANDLER_PREFER,
            ]);

            $handler(new Request('GET', Server::$url), [])->wait();

            self::assertArrayHasKey(\CURLOPT_SHARE, $_SERVER['_curl']);
            self::assertHandlerShareWasCreated();
        } finally {
            unset($_SERVER['curl_test'], $_SERVER['_curl'], $_SERVER['_curl_share'], $_SERVER['_curl_share_init_count']);
        }
    }

    public function testPersistentPreferTransportSharingOptionAppliesCurlShare(): void
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
                'transport_sharing' => TransportSharing::PERSISTENT_PREFER,
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
     * @dataProvider preferredTransportSharingModeProvider
     */
    public function testPreferredTransportSharingCanBeUsedWithCustomFactory(string $transportSharing): void
    {
        $handler = new CurlHandler([
            'handle_factory' => new CurlFactory(0),
            'transport_sharing' => $transportSharing,
        ]);

        self::assertInstanceOf(CurlHandler::class, $handler);
    }

    public static function preferredTransportSharingModeProvider(): iterable
    {
        yield 'handler prefer' => [TransportSharing::HANDLER_PREFER];
        yield 'persistent prefer' => [TransportSharing::PERSISTENT_PREFER];
    }

    /**
     * @dataProvider strictTransportSharingModeProvider
     */
    public function testRequiredTransportSharingCannotBeUsedWithCustomFactory(string $transportSharing): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('handle_factory');

        new CurlHandler([
            'handle_factory' => new CurlFactory(0),
            'transport_sharing' => $transportSharing,
        ]);
    }

    public static function strictTransportSharingModeProvider(): iterable
    {
        yield 'handler require' => [TransportSharing::HANDLER_REQUIRE];
        yield 'persistent require' => [TransportSharing::PERSISTENT_REQUIRE];
    }

    public function testDisabledTransportSharingCanBeUsedWithCustomFactory(): void
    {
        $handler = new CurlHandler([
            'handle_factory' => new CurlFactory(0),
            'transport_sharing' => TransportSharing::NONE,
        ]);

        self::assertInstanceOf(CurlHandler::class, $handler);
    }

    public function testCloseReleasesShareHandleState(): void
    {
        self::skipIfCurlShareIsUnavailable();

        $handler = new CurlHandler([
            'transport_sharing' => TransportSharing::HANDLER_PREFER,
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
        $readFactory = \Closure::bind(static function (CurlHandler $handler): CurlFactory {
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
            || !CurlVersion::supportsCurlHandler()
            || !CurlVersion::supportsHandlerSharing()
        ) {
            self::markTestSkipped('cURL share handles are unavailable.');
        }
    }

    private static function assertPersistentPreferShareWasCreated(): void
    {
        if (
            CurlVersion::supportsConnectionSharing()
            && CurlVersion::supportsSslSessionSharing()
            && \function_exists('curl_share_init_persistent')
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

        self::assertHandlerShareWasCreated();
    }

    public function testRejectsNativePhpUnserialization(): void
    {
        $class = CurlHandler::class;

        try {
            \unserialize(\sprintf('O:%d:"%s":0:{}', \strlen($class), $class), ['allowed_classes' => [$class]]);
            self::fail('Expected unserialization to fail.');
        } catch (\LogicException $e) {
            self::assertSame($class.' should never be unserialized', $e->getMessage());
        }
    }

    private static function assertHandlerShareWasCreated(): void
    {
        $locks = [\CURL_LOCK_DATA_DNS];
        if (CurlVersion::supportsSslSessionSharing()) {
            $locks[] = \CURL_LOCK_DATA_SSL_SESSION;
        }

        self::assertSame(1, $_SERVER['_curl_share_init_count']);
        self::assertSame($locks, $_SERVER['_curl_share'][\CURLSHOPT_SHARE]);
    }
}
