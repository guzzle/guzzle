<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests\Handler;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\HandlerClosedException;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Handler\CurlFactoryInterface;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Handler\CurlShareHandleState;
use GuzzleHttp\Handler\EasyHandle;
use GuzzleHttp\Promise as P;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Server\Server;
use GuzzleHttp\TransportSharing;
use GuzzleHttp\Utils;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

class CurlMultiHandlerTest extends TestCase
{
    public function setUp(): void
    {
        $_SERVER['curl_test'] = true;
        unset(
            $_SERVER['_curl'],
            $_SERVER['_curl_multi'],
            $_SERVER['_curl_share'],
            $_SERVER['_curl_share_init_count'],
            $_SERVER['_curl_share_init_persistent_count'],
            $_SERVER['_curl_share_persistent_options']
        );
    }

    public function tearDown(): void
    {
        unset(
            $_SERVER['_curl'],
            $_SERVER['_curl_multi'],
            $_SERVER['_curl_share'],
            $_SERVER['_curl_share_init_count'],
            $_SERVER['_curl_share_init_persistent_count'],
            $_SERVER['_curl_share_persistent_options'],
            $_SERVER['curl_test']
        );
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

    public function testThrowsWhenCurlMultiOptionNameIsInvalid(): void
    {
        Server::flush();
        Server::enqueue([new Response()]);
        $a = new CurlMultiHandler(['options' => [
            'not-a-curlmopt-option' => true,
        ]]);
        $request = new Request('GET', Server::$url);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid cURL multi option "not-a-curlmopt-option".');
        $a($request, []);
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

    public function testCanSetNumericStringSelectTimeout(): void
    {
        $a = new CurlMultiHandler(['select_timeout' => '0.5']);
        self::assertSame(0.5, self::readSelectTimeout($a));
    }

    public function testTransportSharingOptionAppliesCurlShare(): void
    {
        self::skipIfCurlShareIsUnavailable();

        Server::flush();
        Server::enqueue([new Response(200)]);

        $handler = new CurlMultiHandler([
            'transport_sharing' => TransportSharing::HANDLER_PREFER,
        ]);

        $handler(new Request('GET', Server::$url), [])->wait();

        self::assertArrayHasKey(\CURLOPT_SHARE, $_SERVER['_curl']);
        self::assertSame(1, $_SERVER['_curl_share_init_count']);
        self::assertSame([
            \CURL_LOCK_DATA_DNS,
            \CURL_LOCK_DATA_SSL_SESSION,
        ], $_SERVER['_curl_share'][\CURLSHOPT_SHARE]);
    }

    public function testPersistentPreferTransportSharingOptionAppliesCurlShare(): void
    {
        self::skipIfCurlShareIsUnavailable();

        Server::flush();
        Server::enqueue([new Response(200)]);

        $handler = new CurlMultiHandler([
            'transport_sharing' => TransportSharing::PERSISTENT_PREFER,
        ]);

        $handler(new Request('GET', Server::$url), [])->wait();

        self::assertArrayHasKey(\CURLOPT_SHARE, $_SERVER['_curl']);
        self::assertPersistentPreferShareWasCreated();
    }

    public function testPreferredTransportSharingCanBeUsedWithCustomFactory(): void
    {
        $handler = new CurlMultiHandler([
            'handle_factory' => new CurlFactory(0),
            'transport_sharing' => TransportSharing::HANDLER_PREFER,
        ]);

        self::assertInstanceOf(CurlMultiHandler::class, $handler);
    }

    /**
     * @dataProvider strictTransportSharingModeProvider
     */
    public function testRequiredTransportSharingCannotBeUsedWithCustomFactory(string $transportSharing): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('handle_factory');

        new CurlMultiHandler([
            'handle_factory' => new CurlFactory(0),
            'transport_sharing' => $transportSharing,
        ]);
    }

    public static function strictTransportSharingModeProvider(): iterable
    {
        yield 'handler require' => [TransportSharing::HANDLER_REQUIRE];
        yield 'persistent prefer' => [TransportSharing::PERSISTENT_PREFER];
        yield 'persistent require' => [TransportSharing::PERSISTENT_REQUIRE];
    }

    public function testDisabledTransportSharingCanBeUsedWithCustomFactory(): void
    {
        $handler = new CurlMultiHandler([
            'handle_factory' => new CurlFactory(0),
            'transport_sharing' => TransportSharing::NONE,
        ]);

        self::assertInstanceOf(CurlMultiHandler::class, $handler);
    }

    public function testCloseReleasesShareHandleState(): void
    {
        self::skipIfCurlShareIsUnavailable();

        $handler = new CurlMultiHandler([
            'transport_sharing' => TransportSharing::HANDLER_PREFER,
        ]);

        self::assertNotNull(self::readShareHandleState($handler));

        $handler->close();

        self::assertNull(self::readShareHandleState($handler));
    }

    public function testDestructorDoesNotThrowWhenCurlMultiCloseFails(): void
    {
        $handler = new CurlMultiHandler();

        $setMultiHandle = \Closure::bind(static function (CurlMultiHandler $handler): void {
            $handler->multiHandle = new \stdClass();
        }, null, CurlMultiHandler::class);
        $hasMultiHandle = \Closure::bind(static function (CurlMultiHandler $handler): bool {
            return $handler->multiHandle !== null;
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

    public function testCloseActiveTransferClearsProgressCallbacks(): void
    {
        $handler = new CurlMultiHandler();
        $promise = $handler(new Request('GET', Server::$url), [
            'progress' => static function (): void {
            },
        ]);

        self::assertArrayHasKey(self::progressCallbackOption(), $_SERVER['_curl']);

        $handler->close();

        self::assertTrue(P\Is::rejected($promise));
        self::assertArrayNotHasKey(\CURLOPT_PROGRESSFUNCTION, $_SERVER['_curl']);
        if (\defined('CURLOPT_XFERINFOFUNCTION')) {
            self::assertArrayNotHasKey((int) \constant('CURLOPT_XFERINFOFUNCTION'), $_SERVER['_curl']);
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

    public function testCancelClearsProgressCallbacks(): void
    {
        $handler = new CurlMultiHandler();
        $promise = $handler(new Request('GET', Server::$url), [
            'progress' => static function (): void {
            },
        ]);

        self::assertArrayHasKey(self::progressCallbackOption(), $_SERVER['_curl']);

        $promise->cancel();

        self::assertTrue(P\Is::rejected($promise));
        self::assertArrayNotHasKey(\CURLOPT_PROGRESSFUNCTION, $_SERVER['_curl']);
        if (\defined('CURLOPT_XFERINFOFUNCTION')) {
            self::assertArrayNotHasKey((int) \constant('CURLOPT_XFERINFOFUNCTION'), $_SERVER['_curl']);
        }
    }

    public function testCanCancelFromProgressCallback(): void
    {
        Server::flush();
        Server::enqueue([
            new Response(200, ['Content-Length' => '1048576'], \str_repeat('x', 1048576)),
        ]);

        $handler = new CurlMultiHandler(['select_timeout' => 0]);
        $promise = null;
        $progressCalls = 0;
        $cancelled = false;

        $promise = $handler(new Request('GET', Server::$url), [
            'timeout' => 5,
            'progress' => static function (
                $downloadSize,
                $downloaded,
                $uploadSize,
                $uploaded
            ) use (&$promise, &$progressCalls, &$cancelled): void {
                ++$progressCalls;

                if (!$cancelled) {
                    $cancelled = true;
                    $promise->cancel();
                }
            },
        ]);

        try {
            $deadline = \microtime(true) + 5;

            while (P\Is::pending($promise)) {
                if (\microtime(true) >= $deadline) {
                    self::fail('Timed out waiting for cURL progress cancellation.');
                }

                $handler->tick();
            }

            self::assertGreaterThan(0, $progressCalls);
            self::assertTrue($cancelled);
            self::assertTrue(P\Is::rejected($promise));
        } finally {
            $handler->close();
            Server::flush();
        }
    }

    public function testCanCloseFromProgressCallback(): void
    {
        Server::flush();
        Server::enqueue([
            new Response(200, ['Content-Length' => '1048576'], \str_repeat('x', 1048576)),
        ]);

        $handler = new CurlMultiHandler(['select_timeout' => 0]);
        $progressCalls = 0;
        $closed = false;

        $promise = $handler(new Request('GET', Server::$url), [
            'timeout' => 5,
            'progress' => static function (
                $downloadSize,
                $downloaded,
                $uploadSize,
                $uploaded
            ) use ($handler, &$progressCalls, &$closed): void {
                ++$progressCalls;

                if (!$closed) {
                    $closed = true;
                    $handler->close();
                }
            },
        ]);

        try {
            $deadline = \microtime(true) + 5;

            while (P\Is::pending($promise)) {
                if (\microtime(true) >= $deadline) {
                    self::fail('Timed out waiting for cURL progress close.');
                }

                $handler->tick();
            }

            self::assertGreaterThan(0, $progressCalls);
            self::assertTrue($closed);
            self::assertTrue(P\Is::rejected($promise));

            try {
                $promise->wait();
                self::fail('Expected HandlerClosedException.');
            } catch (HandlerClosedException $e) {
                self::assertSame('The cURL multi handler was closed before the transfer completed.', $e->getMessage());
            }

            try {
                $handler->tick();
                self::fail('Expected BadMethodCallException.');
            } catch (\BadMethodCallException $e) {
                self::assertSame('Cannot use the cURL multi handler after it has been closed.', $e->getMessage());
            }
        } finally {
            $handler->close();
            Server::flush();
        }
    }

    public function testCanCloseFromProgressCallbackWithDelayedTransfer(): void
    {
        Server::flush();
        Server::enqueue([
            new Response(200, ['Content-Length' => '1048576'], \str_repeat('x', 1048576)),
        ]);

        $handler = new CurlMultiHandler(['select_timeout' => 0]);
        $progressCalls = 0;
        $closed = false;

        $activePromise = $handler(new Request('GET', Server::$url), [
            'timeout' => 5,
            'progress' => static function (
                $downloadSize,
                $downloaded,
                $uploadSize,
                $uploaded
            ) use ($handler, &$progressCalls, &$closed): void {
                ++$progressCalls;

                if (!$closed) {
                    $closed = true;
                    $handler->close();
                }
            },
        ]);

        $delayedPromise = $handler(new Request('GET', Server::$url), [
            'delay' => 10000,
        ]);

        try {
            $deadline = \microtime(true) + 5;

            while (P\Is::pending($activePromise)) {
                if (\microtime(true) >= $deadline) {
                    self::fail('Timed out waiting for cURL progress close.');
                }

                $handler->tick();
            }

            self::assertGreaterThan(0, $progressCalls);
            self::assertTrue($closed);
            self::assertTrue(P\Is::rejected($activePromise));
            self::assertTrue(P\Is::rejected($delayedPromise));

            foreach ([$activePromise, $delayedPromise] as $promise) {
                try {
                    $promise->wait();
                    self::fail('Expected HandlerClosedException.');
                } catch (HandlerClosedException $e) {
                    self::assertSame('The cURL multi handler was closed before the transfer completed.', $e->getMessage());
                }
            }
        } finally {
            $handler->close();
            Server::flush();
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

    public function testManualTickRejectsPromiseWhenFinishThrows(): void
    {
        Server::flush();
        Server::enqueue([new Response(200)]);

        $handler = new CurlMultiHandler(['select_timeout' => 0]);
        $previous = new \RuntimeException('stats failed');
        $promise = $handler(new Request('GET', Server::$url), [
            'on_stats' => static function () use ($previous): void {
                throw $previous;
            },
        ]);

        try {
            self::tickUntilSettled($handler, $promise);

            self::assertTrue(P\Is::rejected($promise));

            try {
                $promise->wait();
                self::fail('Expected RuntimeException');
            } catch (\RuntimeException $e) {
                self::assertSame($previous, $e);
            }
        } finally {
            $handler->close();
            Server::flush();
        }
    }

    public function testWaitFalseRejectsPromiseWhenFinishThrows(): void
    {
        Server::flush();
        Server::enqueue([new Response(200)]);

        $handler = new CurlMultiHandler(['select_timeout' => 0]);
        $previous = new \RuntimeException('stats failed');
        $promise = $handler(new Request('GET', Server::$url), [
            'on_stats' => static function () use ($previous): void {
                throw $previous;
            },
        ]);

        try {
            $promise->wait(false);

            self::assertTrue(P\Is::rejected($promise));

            try {
                $promise->wait();
                self::fail('Expected RuntimeException');
            } catch (\RuntimeException $e) {
                self::assertSame($previous, $e);
            }
        } finally {
            $handler->close();
            Server::flush();
        }
    }

    private static function readSelectTimeout(CurlMultiHandler $handler): float
    {
        $readSelectTimeout = \Closure::bind(static function (CurlMultiHandler $handler): float {
            return $handler->selectTimeout;
        }, null, CurlMultiHandler::class);

        return $readSelectTimeout($handler);
    }

    private static function hasMultiHandle(CurlMultiHandler $handler): bool
    {
        $hasMultiHandle = \Closure::bind(static function (CurlMultiHandler $handler): bool {
            return $handler->multiHandle !== null;
        }, null, CurlMultiHandler::class);

        return $hasMultiHandle($handler);
    }

    private static function readFactory(CurlMultiHandler $handler): CurlFactory
    {
        $readFactory = \Closure::bind(static function (CurlMultiHandler $handler): CurlFactory {
            return $handler->factory;
        }, null, CurlMultiHandler::class);

        $factory = $readFactory($handler);
        self::assertInstanceOf(CurlFactory::class, $factory);

        return $factory;
    }

    private static function readShareHandleState(CurlMultiHandler $handler): ?CurlShareHandleState
    {
        $readShareHandleState = \Closure::bind(static function (CurlMultiHandler $handler): ?CurlShareHandleState {
            return $handler->shareHandleState;
        }, null, CurlMultiHandler::class);

        return $readShareHandleState($handler);
    }

    private static function tickUntilSettled(CurlMultiHandler $handler, P\PromiseInterface $promise): void
    {
        for ($i = 0; $i < 1000 && P\Is::pending($promise); ++$i) {
            $handler->tick();
        }

        self::assertFalse(P\Is::pending($promise), 'Promise was not settled after ticking the handler.');
    }

    private static function progressCallbackOption(): int
    {
        if (\defined('CURLOPT_XFERINFOFUNCTION')) {
            return (int) \constant('CURLOPT_XFERINFOFUNCTION');
        }

        return \CURLOPT_PROGRESSFUNCTION;
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
