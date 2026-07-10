<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests\Handler;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\HandlerClosedException;
use GuzzleHttp\Exception\InvalidArgumentException;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Handler\CurlFactoryInterface;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Handler\CurlShareHandleState;
use GuzzleHttp\Handler\CurlVersion;
use GuzzleHttp\Handler\EasyHandle;
use GuzzleHttp\Multiplexing;
use GuzzleHttp\Promise as P;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
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
            $_SERVER['_curl_share_persistent_options'],
            $_SERVER['curl_multi_setopt_fail'],
            $_SERVER['curl_multi_setopt_throw']
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
            $_SERVER['curl_multi_setopt_fail'],
            $_SERVER['curl_multi_setopt_throw'],
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

    public function testRejectsNonCallableOnTrailersBeforeTransfer(): void
    {
        $handler = new CurlMultiHandler();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('on_trailers must be callable');

        $handler(new Request('GET', Server::$url), ['on_trailers' => 'not-a-function']);
    }

    public function testTimeToNextDoesNotTruncateSubSecondDelay(): void
    {
        $handler = new CurlMultiHandler();

        $delays = new \ReflectionProperty(CurlMultiHandler::class, 'delays');
        if (\PHP_VERSION_ID < 80100) {
            $delays->setAccessible(true);
        }
        $delays->setValue($handler, [1 => Utils::currentTime() + 0.5]);

        $timeToNext = new \ReflectionMethod(CurlMultiHandler::class, 'timeToNext');
        if (\PHP_VERSION_ID < 80100) {
            $timeToNext->setAccessible(true);
        }

        self::assertGreaterThan(100000, $timeToNext->invoke($handler));
    }

    public function testTimeToNextClampsOversizedDelays(): void
    {
        $handler = new CurlMultiHandler();

        $delays = new \ReflectionProperty(CurlMultiHandler::class, 'delays');
        if (\PHP_VERSION_ID < 80100) {
            $delays->setAccessible(true);
        }
        $delays->setValue($handler, [1 => Utils::currentTime() + 1.0e15]);

        $timeToNext = new \ReflectionMethod(CurlMultiHandler::class, 'timeToNext');
        if (\PHP_VERSION_ID < 80100) {
            $timeToNext->setAccessible(true);
        }

        self::assertSame(\PHP_INT_MAX, $timeToNext->invoke($handler));
    }

    public function testCanAddConnectionCapOptions(): void
    {
        self::skipIfConnectionCapCurlMultiOptionsUnavailable();

        $handler = new CurlMultiHandler([
            'max_host_connections' => 2,
            'max_total_connections' => 5,
        ]);

        self::initMultiHandle($handler);

        self::assertSame(2, $_SERVER['_curl_multi'][\constant('CURLMOPT_MAX_HOST_CONNECTIONS')]);
        self::assertSame(5, $_SERVER['_curl_multi'][\constant('CURLMOPT_MAX_TOTAL_CONNECTIONS')]);
    }

    public function testSynchronousRequestsDoNotWaitForOtherTransfers(): void
    {
        self::skipIfConnectionCapCurlMultiOptionsUnavailable();

        Server::flush();
        Server::enqueue([new Response(200)]);

        $handler = new CurlMultiHandler(['max_host_connections' => 2]);

        $delayed = $handler(new Request('GET', Server::$url), ['delay' => 2000]);
        $immediate = $handler(new Request('GET', Server::$url), [RequestOptions::SYNCHRONOUS => true]);

        $response = $immediate->wait();

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue(P\Is::pending($delayed));

        $delayed->cancel();
    }

    public function testSynchronousWaitDoesNotFollowReusedHandleFromCompletionCallback(): void
    {
        self::skipIfConnectionCapCurlMultiOptionsUnavailable();

        Server::flush();
        Server::enqueue([new Response(200), new Response(200)]);

        $handler = new CurlMultiHandler(['max_host_connections' => 2]);
        $spawned = null;

        $response = $handler(new Request('GET', Server::$url), [
            RequestOptions::SYNCHRONOUS => true,
            'on_trailers' => static function () use ($handler, &$spawned): void {
                $spawned = $handler(new Request('GET', Server::$url), ['delay' => 2000]);
            },
        ])->wait();

        self::assertSame(200, $response->getStatusCode());
        self::assertInstanceOf(P\PromiseInterface::class, $spawned);
        self::assertTrue(P\Is::pending($spawned));

        $spawned->cancel();
    }

    public function testSynchronousWaitDoesNotBlockOnSiblingAfterTargetCompletion(): void
    {
        Server::flush();
        Server::enqueue([new Response(200)]);

        $handler = new CurlMultiHandler(['select_timeout' => 5]);

        $sibling = $handler(new Request('GET', Server::$url.'guzzle-server/read-timeout'), []);
        $target = $handler(new Request('GET', Server::$url), [RequestOptions::SYNCHRONOUS => true]);

        try {
            // Drive nonblocking native work until the target's completion
            // message is staged and the sibling is the only running transfer.
            self::driveUntilActiveTransferCount($handler, 1);

            $start = \microtime(true);
            $response = $target->wait();
            $elapsed = \microtime(true) - $start;

            self::assertSame(200, $response->getStatusCode());
            self::assertLessThan(2.5, $elapsed, 'The synchronous wait blocked on an unrelated transfer after the target had completed.');
            self::assertTrue(P\Is::pending($sibling));
        } finally {
            $sibling->cancel();
            $handler->close();
            Server::flush();
        }
    }

    public function testSynchronousWaitStopsAfterTargetCancellationFromTaskQueue(): void
    {
        Server::flush();

        $handler = new CurlMultiHandler(['select_timeout' => 5]);

        $sibling = $handler(new Request('GET', Server::$url.'guzzle-server/read-timeout'), []);
        $target = $handler(new Request('GET', Server::$url), [RequestOptions::SYNCHRONOUS => true]);

        P\Utils::queue()->add(static function () use ($target): void {
            $target->cancel();
        });

        try {
            $start = \microtime(true);

            try {
                $target->wait();
                self::fail('Expected the canceled target to reject.');
            } catch (P\CancellationException $e) {
                $elapsed = \microtime(true) - $start;
            }

            self::assertLessThan(2.5, $elapsed, 'The synchronous wait selected for an unrelated transfer after the target had been canceled.');
            self::assertTrue(P\Is::rejected($target));
            self::assertSame(1, self::readMultiProperty($handler, 'active'));
            self::assertTrue(P\Is::pending($sibling));
        } finally {
            $sibling->cancel();
            $handler->close();
            Server::flush();
        }
    }

    public function testDelayedSynchronousWaitIsNotBoundToSiblingSelectTimeout(): void
    {
        Server::flush();
        Server::enqueue([new Response(200)]);

        $handler = new CurlMultiHandler(['select_timeout' => 5]);

        $sibling = $handler(new Request('GET', Server::$url.'guzzle-server/read-timeout'), []);

        try {
            self::driveUntilActiveTransferCount($handler, 1);

            $target = $handler(new Request('GET', Server::$url), [
                RequestOptions::SYNCHRONOUS => true,
                'delay' => 100,
            ]);

            $start = \microtime(true);
            $response = $target->wait();
            $elapsed = \microtime(true) - $start;

            self::assertSame(200, $response->getStatusCode());
            self::assertLessThan(2.5, $elapsed, 'The delayed synchronous target waited for an unrelated transfer before attaching.');
            self::assertTrue(P\Is::pending($sibling));
        } finally {
            $sibling->cancel();
            $handler->close();
            Server::flush();
        }
    }

    public function testDelayedRequestAttachesBeforeSiblingSelectTimeoutWhenTicking(): void
    {
        Server::flush();
        Server::enqueue([new Response(200)]);

        $handler = new CurlMultiHandler(['select_timeout' => 5]);

        $sibling = $handler(new Request('GET', Server::$url.'guzzle-server/read-timeout'), []);

        try {
            self::driveUntilActiveTransferCount($handler, 1);

            $delayed = $handler(new Request('GET', Server::$url), ['delay' => 100]);

            $start = \microtime(true);
            $deadline = $start + 10;
            while (P\Is::pending($delayed) && \microtime(true) < $deadline) {
                $handler->tick();
            }
            $elapsed = \microtime(true) - $start;

            self::assertTrue(P\Is::fulfilled($delayed));
            self::assertSame(200, $delayed->wait()->getStatusCode());
            self::assertLessThan(2.5, $elapsed, 'The delayed request waited for an unrelated transfer before attaching.');
            self::assertTrue(P\Is::pending($sibling));
        } finally {
            $sibling->cancel();
            $handler->close();
            Server::flush();
        }
    }

    public function testDelayedRequestAttachesBeforeSiblingSelectTimeoutWhenExecuting(): void
    {
        Server::flush();
        Server::enqueue([new Response(200)]);

        $handler = new CurlMultiHandler(['select_timeout' => 5]);

        $sibling = $handler(new Request('GET', Server::$url.'guzzle-server/read-timeout'), []);

        try {
            self::driveUntilActiveTransferCount($handler, 1);

            $delayed = $handler(new Request('GET', Server::$url), ['delay' => 100]);
            $delayed->then(static function () use ($sibling): void {
                $sibling->cancel();
            });

            $start = \microtime(true);
            $handler->execute();
            $elapsed = \microtime(true) - $start;

            self::assertTrue(P\Is::fulfilled($delayed));
            self::assertLessThan(2.5, $elapsed, 'The delayed request waited for an unrelated transfer while executing.');
        } finally {
            $sibling->cancel();
            $handler->close();
            Server::flush();
        }
    }

    public function testStalePromiseCancellationDoesNotCancelReplacementRequest(): void
    {
        Server::flush();

        $handler = new CurlMultiHandler(['select_timeout' => 2]);
        $promise = $handler(new Request('GET', Server::$url), ['delay' => 2000]);

        $handles = self::readMultiProperty($handler, 'handles');
        self::assertCount(1, $handles);
        $id = (int) \key($handles);

        // Simulate the native handle ID having been reused by a replacement
        // request created after this promise's transfer left the handler.
        $handles[$id]['wait_token'] = new \stdClass();
        $handles[$id]['deferred'] = new P\Promise();
        self::setMultiProperty($handler, 'handles', $handles);

        $promise->cancel();

        self::assertTrue(P\Is::rejected($promise));
        self::assertArrayHasKey($id, self::readMultiProperty($handler, 'handles'));

        $handler->close();
    }

    public function testSynchronousWaitStopsAfterCancellationFromSiblingCompletion(): void
    {
        Server::flush();
        Server::enqueue([new Response(200)]);

        $handler = new CurlMultiHandler(['select_timeout' => 5]);

        // The target is quiescent, so only the sibling's completion
        // continuation can end the wait early.
        $target = $handler(new Request('GET', Server::$url.'guzzle-server/read-timeout'), [RequestOptions::SYNCHRONOUS => true]);
        $sibling = $handler(new Request('GET', Server::$url), []);
        $sibling->then(static function () use ($target): void {
            $target->cancel();
        });

        try {
            self::driveUntilActiveTransferCount($handler, 1);

            $start = \microtime(true);

            try {
                $target->wait();
                self::fail('Expected the canceled target to reject.');
            } catch (P\CancellationException $e) {
                $elapsed = \microtime(true) - $start;
            }

            self::assertLessThan(2.5, $elapsed, 'The wait selected on the quiescent target before running the sibling completion continuation.');
            self::assertTrue(P\Is::fulfilled($sibling));
        } finally {
            $target->cancel();
            $handler->close();
            Server::flush();
        }
    }

    public function testCompletionCallbackCancellationOfOriginalDoesNotDoubleSettle(): void
    {
        Server::flush();
        Server::enqueue([new Response(200), new Response(200)]);

        $handler = new CurlMultiHandler(['select_timeout' => 5]);
        $original = null;
        $spawned = null;

        $original = $handler(new Request('GET', Server::$url), [
            'on_trailers' => static function () use ($handler, &$original, &$spawned): void {
                $spawned = $handler(new Request('GET', Server::$url), ['delay' => 2000]);
                $original->cancel();
            },
        ]);

        try {
            $deadline = \microtime(true) + 5;
            while (P\Is::pending($original) && \microtime(true) < $deadline) {
                $handler->tick();
            }

            self::assertTrue(P\Is::rejected($original));
            self::assertInstanceOf(P\PromiseInterface::class, $spawned);
            self::assertTrue(P\Is::pending($spawned));
        } finally {
            if ($spawned !== null) {
                $spawned->cancel();
            }
            $handler->close();
            Server::flush();
        }
    }

    public function testDelayedSynchronousWaitRunsQueuedCancellationBeforeSleeping(): void
    {
        Server::flush();

        $handler = new CurlMultiHandler(['select_timeout' => 5]);
        $target = $handler(new Request('GET', Server::$url), [
            RequestOptions::SYNCHRONOUS => true,
            'delay' => 5000,
        ]);

        P\Utils::queue()->add(static function () use ($target): void {
            $target->cancel();
        });

        try {
            $start = \microtime(true);

            try {
                $target->wait();
                self::fail('Expected the canceled target to reject.');
            } catch (P\CancellationException $e) {
            }

            self::assertLessThan(2.5, \microtime(true) - $start, 'The delayed wait slept over a queued cancellation.');
        } finally {
            $handler->close();
            Server::flush();
        }
    }

    public function testExecuteRunsQueuedCancellationBeforeSleepingForDelays(): void
    {
        Server::flush();

        $handler = new CurlMultiHandler(['select_timeout' => 5]);
        $delayed = $handler(new Request('GET', Server::$url), ['delay' => 5000]);

        P\Utils::queue()->add(static function () use ($delayed): void {
            $delayed->cancel();
        });

        try {
            $start = \microtime(true);
            $handler->execute();

            self::assertTrue(P\Is::rejected($delayed));
            self::assertLessThan(2.5, \microtime(true) - $start, 'execute() slept over a queued cancellation.');
        } finally {
            $handler->close();
            Server::flush();
        }
    }

    /**
     * @dataProvider invalidConnectionCapOptionProvider
     *
     * @param mixed $value
     */
    public function testRejectsInvalidConnectionCapOptions(string $option, $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($option.' must be a positive integer.');

        new CurlMultiHandler([$option => $value]);
    }

    /**
     * @dataProvider connectionCapOptionProvider
     */
    public function testRejectsRawConnectionCapCurlMultiOptions(string $option, string $constant): void
    {
        self::skipIfConnectionCapCurlMultiOptionsUnavailable();

        try {
            new CurlMultiHandler(['options' => [\constant($constant) => 2]]);
            self::fail('Expected the raw cURL multi connection cap option to be rejected.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('Passing '.$constant, $e->getMessage());
            self::assertStringContainsString('Use the "'.$option.'" client option or cURL multi handler option instead.', $e->getMessage());
        }
    }

    public function testRejectsRawConnectionCapCurlMultiOptionsBeforeCreatingShareState(): void
    {
        self::skipIfConnectionCapCurlMultiOptionsUnavailable();

        try {
            new CurlMultiHandler([
                'transport_sharing' => TransportSharing::PERSISTENT_REQUIRE,
                'options' => [\constant('CURLMOPT_MAX_HOST_CONNECTIONS') => 5],
            ]);
            self::fail('Expected the raw cURL multi connection cap option to be rejected.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('Passing CURLMOPT_MAX_HOST_CONNECTIONS', $e->getMessage());
        }

        self::assertArrayNotHasKey('_curl_share_init_count', $_SERVER);
        self::assertArrayNotHasKey('_curl_share_init_persistent_count', $_SERVER);
    }

    public function testThrowsWhenCurlMultiOptionCannotBeApplied(): void
    {
        $handler = new CurlMultiHandler(['options' => [
            \CURLMOPT_MAXCONNECTS => 5,
        ]]);
        $_SERVER['curl_multi_setopt_fail'] = \CURLMOPT_MAXCONNECTS;

        try {
            self::initMultiHandle($handler);
            self::fail('Expected InvalidArgumentException.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('Unable to apply the cURL multi option CURLMOPT_MAXCONNECTS', $e->getMessage());
        }

        self::assertFalse(self::hasMultiHandle($handler));

        unset($_SERVER['curl_multi_setopt_fail']);
        self::initMultiHandle($handler);
        self::assertTrue(self::hasMultiHandle($handler));
    }

    public function testWrapsCurlMultiOptionThrowable(): void
    {
        $handler = new CurlMultiHandler(['options' => [
            \CURLMOPT_MAXCONNECTS => 5,
        ]]);
        $_SERVER['curl_multi_setopt_throw'] = \CURLMOPT_MAXCONNECTS;

        try {
            self::initMultiHandle($handler);
            self::fail('Expected InvalidArgumentException.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('Unable to apply the cURL multi option CURLMOPT_MAXCONNECTS', $e->getMessage());
            self::assertInstanceOf(\RuntimeException::class, $e->getPrevious());
        }

        self::assertFalse(self::hasMultiHandle($handler));
    }

    public function testPublicRequestCleansUpWhenCurlMultiOptionCannotBeApplied(): void
    {
        Server::flush();
        Server::enqueue([new Response()]);

        $handler = new CurlMultiHandler(['options' => [
            \CURLMOPT_MAXCONNECTS => 5,
        ]]);
        $_SERVER['curl_multi_setopt_fail'] = \CURLMOPT_MAXCONNECTS;

        try {
            $handler(new Request('GET', Server::$url), []);
            self::fail('Expected InvalidArgumentException.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('Unable to apply the cURL multi option CURLMOPT_MAXCONNECTS', $e->getMessage());
        }

        self::assertSame([], self::readMultiProperty($handler, 'handles'));
        self::assertSame([], self::readMultiProperty($handler, 'delays'));
        self::assertFalse(self::hasMultiHandle($handler));

        unset($_SERVER['curl_multi_setopt_fail']);
        Server::flush();
        Server::enqueue([new Response()]);

        $response = $handler(new Request('GET', Server::$url), [])->wait();
        self::assertSame(200, $response->getStatusCode());
    }

    public function testDelayedRequestCleansUpWhenCurlMultiOptionCannotBeApplied(): void
    {
        $handler = new CurlMultiHandler(['options' => [
            \CURLMOPT_MAXCONNECTS => 5,
        ]]);
        $_SERVER['curl_multi_setopt_fail'] = \CURLMOPT_MAXCONNECTS;

        $promise = $handler(new Request('GET', Server::$url), ['delay' => 1]);
        $handles = self::readMultiProperty($handler, 'handles');
        $id = \array_key_first($handles);
        self::assertIsInt($id);

        self::setMultiProperty($handler, 'delays', [$id => Utils::currentTime() - 1]);
        $handler->tick();

        self::assertSame([], self::readMultiProperty($handler, 'handles'));
        self::assertSame([], self::readMultiProperty($handler, 'delays'));
        self::assertFalse(self::hasMultiHandle($handler));
        self::assertTrue(P\Is::rejected($promise));
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

    public function testRejectsUnknownConstructorOption(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid CurlMultiHandler constructor option "unknown".');

        new CurlMultiHandler(['unknown' => true]);
    }

    public static function connectionCapOptionProvider(): iterable
    {
        yield 'max host connections' => ['max_host_connections', 'CURLMOPT_MAX_HOST_CONNECTIONS'];
        yield 'max total connections' => ['max_total_connections', 'CURLMOPT_MAX_TOTAL_CONNECTIONS'];
    }

    public static function invalidConnectionCapOptionProvider(): iterable
    {
        foreach (['max_host_connections', 'max_total_connections'] as $option) {
            yield $option.' zero' => [$option, 0];
            yield $option.' negative' => [$option, -1];
            yield $option.' float' => [$option, 1.0];
            yield $option.' string' => [$option, '1'];
        }
    }

    public function testRejectsExplicitMultiplexWhenPipeliningIsDisabled(): void
    {
        if (!CurlVersion::supportsHttp2() || !CurlVersion::supportsMultiplex()) {
            self::markTestSkipped('HTTP/2 or multiplex support is unavailable.');
        }

        $a = new CurlMultiHandler(['options' => [
            \CURLMOPT_PIPELINING => \CURLPIPE_NOTHING,
        ]]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The "multiplex" request option cannot be combined with a CurlMultiHandler CURLMOPT_PIPELINING option that disables multiplexing; set CURLMOPT_PIPELINING to CURLPIPE_MULTIPLEX, remove the option, or set the "multiplex" option to "eager".');
        $a(new Request('GET', Server::$url, [], null, '2.0'), ['multiplex' => Multiplexing::WAIT]);
    }

    public function testRejectsExplicitMultiplexWhenPipeliningIsHttp1Only(): void
    {
        if (!CurlVersion::supportsHttp2() || !CurlVersion::supportsMultiplex()) {
            self::markTestSkipped('HTTP/2 or multiplex support is unavailable.');
        }

        // CURLPIPE_HTTP1 has been a no-op since libcurl 7.62.0 but still lacks
        // the CURLPIPE_MULTIPLEX bit, so it silently disables multiplexing.
        $a = new CurlMultiHandler(['options' => [
            \CURLMOPT_PIPELINING => \CURLPIPE_HTTP1,
        ]]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The "multiplex" request option cannot be combined with a CurlMultiHandler CURLMOPT_PIPELINING option that disables multiplexing; set CURLMOPT_PIPELINING to CURLPIPE_MULTIPLEX, remove the option, or set the "multiplex" option to "eager".');
        $a(new Request('GET', Server::$url, [], null, '2.0'), ['multiplex' => Multiplexing::WAIT]);
    }

    public function testRejectsRequireWaitWhenPipeliningIsDisabled(): void
    {
        if (!\defined('CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE') || !\defined('CURLOPT_PIPEWAIT') || !\defined('CURL_VERSION_HTTP2')) {
            self::markTestSkipped('CURLOPT_PIPEWAIT or HTTP/2 cURL constants are unavailable.');
        }

        $previousVersionInfo = self::setCurlVersionInfo([
            'version' => '8.14.0',
            'features' => self::curlSslFeature() | \CURL_VERSION_HTTP2,
        ]);

        try {
            $a = new CurlMultiHandler(['options' => [
                \CURLMOPT_PIPELINING => \CURLPIPE_NOTHING,
            ]]);

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('The "multiplex" request option cannot be combined with a CurlMultiHandler CURLMOPT_PIPELINING option that disables multiplexing; set CURLMOPT_PIPELINING to CURLPIPE_MULTIPLEX, remove the option, or set the "multiplex" option to "eager".');
            $a(new Request('GET', Server::$url, [], null, '2.0'), ['multiplex' => Multiplexing::REQUIRE_WAIT]);
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
        }
    }

    public function testRejectsRequireEagerWhenPipeliningIsDisabled(): void
    {
        if (!\defined('CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE') || !\defined('CURLOPT_PIPEWAIT') || !\defined('CURL_VERSION_HTTP2')) {
            self::markTestSkipped('CURLOPT_PIPEWAIT or HTTP/2 cURL constants are unavailable.');
        }

        $previousVersionInfo = self::setCurlVersionInfo([
            'version' => '8.14.0',
            'features' => self::curlSslFeature() | \CURL_VERSION_HTTP2,
        ]);

        try {
            // REQUIRE_EAGER never sets CURLOPT_PIPEWAIT, so this pins the
            // marker-independent required-family arm of the guard.
            $a = new CurlMultiHandler(['options' => [
                \CURLMOPT_PIPELINING => \CURLPIPE_NOTHING,
            ]]);

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('The "multiplex" request option cannot be combined with a CurlMultiHandler CURLMOPT_PIPELINING option that disables multiplexing; set CURLMOPT_PIPELINING to CURLPIPE_MULTIPLEX, remove the option, or set the "multiplex" option to "eager".');
            $a(new Request('GET', Server::$url, [], null, '2.0'), ['multiplex' => Multiplexing::REQUIRE_EAGER]);
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
        }
    }

    public static function nonScalarPipeliningProvider(): iterable
    {
        yield 'empty array with wait' => [Multiplexing::WAIT, []];
        yield 'non-empty array with wait' => [Multiplexing::WAIT, [1]];
        yield 'object with wait' => [Multiplexing::WAIT, new \stdClass()];
        yield 'empty array with require_eager' => [Multiplexing::REQUIRE_EAGER, []];
        yield 'non-empty array with require_wait' => [Multiplexing::REQUIRE_WAIT, [1]];
        yield 'object with require_eager' => [Multiplexing::REQUIRE_EAGER, new \stdClass()];
    }

    /**
     * @dataProvider nonScalarPipeliningProvider
     *
     * @param mixed $pipelining
     */
    public function testRejectsNonScalarPipeliningWithExplicitMultiplex(string $multiplex, $pipelining): void
    {
        if (!\defined('CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE') || !\defined('CURLOPT_PIPEWAIT') || !\defined('CURL_VERSION_HTTP2')) {
            self::markTestSkipped('CURLOPT_PIPEWAIT or HTTP/2 cURL constants are unavailable.');
        }

        $previousVersionInfo = self::setCurlVersionInfo([
            'version' => '8.14.0',
            'features' => self::curlSslFeature() | \CURL_VERSION_HTTP2,
        ]);

        try {
            // ext-curl derives the integer mask from non-scalar values with
            // type-dependent zval semantics, so they are rejected as an
            // invalid type instead of bypassing the guard.
            $a = new CurlMultiHandler(['options' => [
                \CURLMOPT_PIPELINING => $pipelining,
            ]]);

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('The CurlMultiHandler CURLMOPT_PIPELINING option must be an integer when combined with the "multiplex" request option.');
            $a(new Request('GET', Server::$url, [], null, '2.0'), ['multiplex' => $multiplex]);
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
        }
    }

    public function testAllowsExplicitMultiplexWhenPipeliningIncludesMultiplexBit(): void
    {
        if (!CurlVersion::supportsHttp2() || !CurlVersion::supportsMultiplex()) {
            self::markTestSkipped('HTTP/2 or multiplex support is unavailable.');
        }

        Server::flush();
        Server::enqueue([new Response()]);
        $a = new CurlMultiHandler(['options' => [
            \CURLMOPT_PIPELINING => \CURLPIPE_MULTIPLEX,
        ]]);
        $response = $a(new Request('GET', Server::$url, [], null, '2.0'), ['multiplex' => Multiplexing::WAIT])->wait();
        self::assertSame(200, $response->getStatusCode());
    }

    public function testAllowsExplicitMultiplexWithCombinedPipeliningMask(): void
    {
        if (!CurlVersion::supportsHttp2() || !CurlVersion::supportsMultiplex()) {
            self::markTestSkipped('HTTP/2 or multiplex support is unavailable.');
        }

        Server::flush();
        Server::enqueue([new Response()]);
        $a = new CurlMultiHandler(['options' => [
            \CURLMOPT_PIPELINING => \CURLPIPE_HTTP1 | \CURLPIPE_MULTIPLEX,
        ]]);
        $response = $a(new Request('GET', Server::$url, [], null, '2.0'), ['multiplex' => Multiplexing::WAIT])->wait();
        self::assertSame(200, $response->getStatusCode());
    }

    public function testAllowsDisabledPipeliningWhenMultiplexIsEager(): void
    {
        if (!CurlVersion::supportsMultiplex()) {
            self::markTestSkipped('Multiplex support is unavailable.');
        }

        Server::flush();
        Server::enqueue([new Response()]);
        $a = new CurlMultiHandler(['options' => [
            \CURLMOPT_PIPELINING => \CURLPIPE_NOTHING,
        ]]);
        $response = $a(new Request('GET', Server::$url), ['multiplex' => Multiplexing::EAGER])->wait();
        self::assertSame(200, $response->getStatusCode());
    }

    public function testAllowsExplicitPreferForHttp11WhenPipeliningIsDisabled(): void
    {
        if (!CurlVersion::supportsMultiplex()) {
            self::markTestSkipped('Multiplex support is unavailable.');
        }

        Server::flush();
        Server::enqueue([new Response()]);
        $a = new CurlMultiHandler(['options' => [
            \CURLMOPT_PIPELINING => \CURLPIPE_NOTHING,
        ]]);
        $response = $a(new Request('GET', Server::$url), ['multiplex' => Multiplexing::WAIT])->wait();
        self::assertSame(200, $response->getStatusCode());
    }

    public function testDefaultMultiplexDoesNotThrowWhenPipeliningIsDisabled(): void
    {
        if (!CurlVersion::supportsMultiplex()) {
            self::markTestSkipped('Multiplex support is unavailable.');
        }
        if (!CurlVersion::supportsHttp2()) {
            self::markTestSkipped('HTTP/2 support is unavailable.');
        }

        // The default (key absent) never conflicts with disabled pipelining: an
        // explicit wait/require-family option is required for the guard.
        Server::flush();
        Server::enqueue([new Response()]);
        $a = new CurlMultiHandler(['options' => [
            \CURLMOPT_PIPELINING => \CURLPIPE_NOTHING,
        ]]);
        $response = $a(new Request('GET', Server::$url, [], null, '2.0'), [])->wait();

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($_SERVER['_curl'][(int) \constant('CURLOPT_PIPEWAIT')]);
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

    public function testAllowsZeroSelectTimeout(): void
    {
        $a = new CurlMultiHandler(['select_timeout' => 0]);
        self::assertSame(0.0, self::readSelectTimeout($a));
    }

    public function testRejectsNonNumericSelectTimeout(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('select_timeout must be a number of seconds');

        new CurlMultiHandler(['select_timeout' => []]);
    }

    /**
     * @dataProvider invalidSelectTimeoutRangeProvider
     *
     * @param mixed $selectTimeout
     */
    public function testRejectsInvalidSelectTimeoutRange($selectTimeout): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('select_timeout must be 0 or greater than or equal to 0.001 seconds');

        new CurlMultiHandler(['select_timeout' => $selectTimeout]);
    }

    public static function invalidSelectTimeoutRangeProvider(): iterable
    {
        yield 'negative' => [-1];
        yield 'positive infinity' => [\INF];
        yield 'negative infinity' => [-\INF];
        yield 'not a number' => [\NAN];
        yield 'positive sub-millisecond' => [0.0005];
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
        self::assertHandlerShareWasCreated();
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

    /**
     * @dataProvider connectionCapOptionProvider
     */
    public function testRejectsConnectionCapOptionsWithRequiredPersistentTransportSharing(string $option, string $_constant): void
    {
        try {
            new CurlMultiHandler([
                'transport_sharing' => TransportSharing::PERSISTENT_REQUIRE,
                $option => 1,
            ]);
            self::fail('Expected the connection cap option to conflict with persistent transport sharing.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString($option.' cannot be combined with persistent transport sharing', $e->getMessage());
        }

        self::assertArrayNotHasKey('_curl_share_init_count', $_SERVER);
        self::assertArrayNotHasKey('_curl_share_init_persistent_count', $_SERVER);
    }

    public function testRejectsInvalidConnectionCapValuesBeforePersistentSharingConflicts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('max_host_connections must be a positive integer.');

        new CurlMultiHandler([
            'transport_sharing' => TransportSharing::PERSISTENT_REQUIRE,
            'max_host_connections' => 0,
        ]);
    }

    public function testDegradesPersistentPreferTransportSharingWithConnectionCaps(): void
    {
        self::skipIfCurlShareIsUnavailable();
        self::skipIfConnectionCapCurlMultiOptionsUnavailable();

        Server::flush();
        Server::enqueue([new Response(200)]);

        $handler = new CurlMultiHandler([
            'transport_sharing' => TransportSharing::PERSISTENT_PREFER,
            'max_host_connections' => 2,
        ]);

        $handler(new Request('GET', Server::$url), [])->wait();

        self::assertArrayHasKey(\CURLOPT_SHARE, $_SERVER['_curl']);
        self::assertArrayNotHasKey('_curl_share_init_persistent_count', $_SERVER);
        self::assertHandlerShareWasCreated();
        self::assertSame(2, $_SERVER['_curl_multi'][\constant('CURLMOPT_MAX_HOST_CONNECTIONS')]);
    }

    /**
     * @dataProvider preferredTransportSharingModeProvider
     */
    public function testPreferredTransportSharingCanBeUsedWithCustomFactory(string $transportSharing): void
    {
        $handler = new CurlMultiHandler([
            'handle_factory' => new CurlFactory(0),
            'transport_sharing' => $transportSharing,
        ]);

        self::assertInstanceOf(CurlMultiHandler::class, $handler);
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

        new CurlMultiHandler([
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
        $request = new Request('GET', Server::$url);
        $promise = $handler($request, [
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
        $curl = [];
        $prereqOption = null;

        if (\defined('CURLOPT_PREREQFUNCTION') && \defined('CURL_PREREQFUNC_OK')) {
            $prereqOption = (int) \constant('CURLOPT_PREREQFUNCTION');
            $curl[$prereqOption] = static function (): int {
                return (int) \constant('CURL_PREREQFUNC_OK');
            };
        }

        $handler = new CurlMultiHandler();
        $promise = $handler(new Request('GET', Server::$url), [
            'progress' => static function (): void {
            },
            'curl' => $curl,
        ]);

        self::assertArrayHasKey(self::progressCallbackOption(), $_SERVER['_curl']);
        if ($prereqOption !== null) {
            self::assertArrayHasKey($prereqOption, $_SERVER['_curl']);
        }

        $handler->close();

        self::assertTrue(P\Is::rejected($promise));
        self::assertArrayNotHasKey(\CURLOPT_PROGRESSFUNCTION, $_SERVER['_curl']);
        if (\defined('CURLOPT_XFERINFOFUNCTION')) {
            self::assertArrayNotHasKey((int) \constant('CURLOPT_XFERINFOFUNCTION'), $_SERVER['_curl']);
        }
        if ($prereqOption !== null) {
            self::assertArrayNotHasKey($prereqOption, $_SERVER['_curl']);
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
        $curl = [];
        $prereqOption = null;

        if (\defined('CURLOPT_PREREQFUNCTION') && \defined('CURL_PREREQFUNC_OK')) {
            $prereqOption = (int) \constant('CURLOPT_PREREQFUNCTION');
            $curl[$prereqOption] = static function (): int {
                return (int) \constant('CURL_PREREQFUNC_OK');
            };
        }

        $handler = new CurlMultiHandler();
        $promise = $handler(new Request('GET', Server::$url), [
            'progress' => static function (): void {
            },
            'curl' => $curl,
        ]);

        self::assertArrayHasKey(self::progressCallbackOption(), $_SERVER['_curl']);
        if ($prereqOption !== null) {
            self::assertArrayHasKey($prereqOption, $_SERVER['_curl']);
        }

        $promise->cancel();

        self::assertTrue(P\Is::rejected($promise));
        self::assertArrayNotHasKey(\CURLOPT_PROGRESSFUNCTION, $_SERVER['_curl']);
        if (\defined('CURLOPT_XFERINFOFUNCTION')) {
            self::assertArrayNotHasKey((int) \constant('CURLOPT_XFERINFOFUNCTION'), $_SERVER['_curl']);
        }
        if ($prereqOption !== null) {
            self::assertArrayNotHasKey($prereqOption, $_SERVER['_curl']);
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

    public function testCanCancelFromProgressCallbackAfterNestedTick(): void
    {
        Server::flush();
        Server::enqueue([
            new Response(200, ['Content-Length' => '1048576'], \str_repeat('x', 1048576)),
            new Response(200),
        ]);

        $handler = new CurlMultiHandler(['select_timeout' => 0]);
        $promise = null;
        $cancelled = false;

        $promise = $handler(new Request('GET', Server::$url), [
            'timeout' => 5,
            'progress' => static function () use ($handler, &$promise, &$cancelled): void {
                if (!$cancelled) {
                    $cancelled = true;
                    // Re-enter the handler before cancelling; the nested tick
                    // must not clear the outer exec's re-entrancy guard.
                    $handler->tick();
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

            self::assertTrue($cancelled);
            self::assertTrue(P\Is::rejected($promise));

            // The handler stays usable after the deferred cancel.
            self::assertSame(200, $handler(new Request('GET', Server::$url), ['timeout' => 5])->wait()->getStatusCode());
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

        $request = new Request('GET', Server::$url);
        $promise = $handler($request, [
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
                self::assertSame($request, $e->getRequest());
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

    public function testCanCloseFromProgressCallbackAfterNestedTick(): void
    {
        Server::flush();
        Server::enqueue([
            new Response(200, ['Content-Length' => '1048576'], \str_repeat('x', 1048576)),
        ]);

        $handler = new CurlMultiHandler(['select_timeout' => 0]);
        $closed = false;

        $request = new Request('GET', Server::$url);
        $promise = $handler($request, [
            'timeout' => 5,
            'progress' => static function () use ($handler, &$closed): void {
                if (!$closed) {
                    $closed = true;
                    // Re-enter the handler before closing; the nested tick
                    // must not clear the outer exec's re-entrancy guard.
                    $handler->tick();
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

            self::assertTrue($closed);
            self::assertTrue(P\Is::rejected($promise));

            try {
                $promise->wait();
                self::fail('Expected HandlerClosedException.');
            } catch (HandlerClosedException $e) {
                self::assertSame('The cURL multi handler was closed before the transfer completed.', $e->getMessage());
                self::assertSame($request, $e->getRequest());
            }
        } finally {
            $handler->close();
            Server::flush();
        }
    }

    public function testCanCloseFromOnHeadersCallbackAfterNestedTick(): void
    {
        Server::flush();
        Server::enqueue([
            new Response(200, ['Content-Length' => '1048576'], \str_repeat('x', 1048576)),
        ]);

        // A close applied while cURL is still delivering the response would
        // reset the easy handle's write callback, dumping the remaining body
        // to the default output stream.
        $this->expectOutputString('');

        $handler = new CurlMultiHandler(['select_timeout' => 0]);
        $closed = false;

        $request = new Request('GET', Server::$url);
        $promise = $handler($request, [
            'timeout' => 5,
            'on_headers' => static function () use ($handler, &$closed): void {
                // Re-enter the handler before closing; the nested tick must
                // not clear the outer exec's re-entrancy guard.
                $handler->tick();
                $closed = true;
                $handler->close();
            },
        ]);

        try {
            $deadline = \microtime(true) + 5;

            while (P\Is::pending($promise)) {
                if (\microtime(true) >= $deadline) {
                    self::fail('Timed out waiting for cURL header close.');
                }

                $handler->tick();
            }

            self::assertTrue($closed);
            self::assertTrue(P\Is::rejected($promise));

            try {
                $promise->wait();
                self::fail('Expected HandlerClosedException.');
            } catch (HandlerClosedException $e) {
                self::assertSame('The cURL multi handler was closed before the transfer completed.', $e->getMessage());
                self::assertSame($request, $e->getRequest());
            }
        } finally {
            $handler->close();
            Server::flush();
        }
    }

    public function testCanCloseFromOnStatsCallback(): void
    {
        Server::flush();
        Server::enqueue([new Response(200)]);

        $handler = new CurlMultiHandler(['select_timeout' => 0]);
        $closed = false;

        $request = new Request('GET', Server::$url);
        $promise = $handler($request, [
            'timeout' => 5,
            'on_stats' => static function () use ($handler, &$closed): void {
                $closed = true;
                $handler->close();
            },
        ]);

        try {
            // The close is deferred until message processing finishes, so the
            // fulfilled response is delivered rather than being replaced by a
            // BadMethodCallException.
            self::assertSame(200, $promise->wait()->getStatusCode());
            self::assertTrue($closed);

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

    public function testCanCloseFromOnStatsCallbackAfterNestedTick(): void
    {
        Server::flush();
        Server::enqueue([new Response(200)]);

        $handler = new CurlMultiHandler(['select_timeout' => 0]);
        $closed = false;

        $request = new Request('GET', Server::$url);
        $promise = $handler($request, [
            'timeout' => 5,
            'on_stats' => static function () use ($handler, &$closed): void {
                // Re-enter the handler before closing; the nested tick must
                // not clear the outer message loop's re-entrancy guard.
                $handler->tick();
                $closed = true;
                $handler->close();
            },
        ]);

        try {
            self::assertSame(200, $promise->wait()->getStatusCode());
            self::assertTrue($closed);

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

    public function testCanCloseFromNestedProgressCallbackDuringOnStats(): void
    {
        Server::flush();
        Server::enqueue([
            new Response(200),
            new Response(200, ['Content-Length' => '1048576'], \str_repeat('x', 1048576)),
        ]);

        $handler = new CurlMultiHandler(['select_timeout' => 0]);
        $closed = false;
        $spawned = null;
        $spawnedRequest = null;

        $promise = $handler(new Request('GET', Server::$url), [
            'timeout' => 5,
            'on_stats' => static function () use ($handler, &$closed, &$spawned, &$spawnedRequest): void {
                $spawnedRequest = new Request('GET', Server::$url);
                $spawned = $handler($spawnedRequest, [
                    'timeout' => 5,
                    'progress' => static function () use ($handler, &$closed): void {
                        if (!$closed) {
                            $closed = true;
                            $handler->close();
                        }
                    },
                ]);

                $deadline = \microtime(true) + 5;

                while (!$closed) {
                    if (\microtime(true) >= $deadline) {
                        self::fail('Timed out waiting for the nested progress close.');
                    }

                    $handler->tick();
                }
            },
        ]);

        try {
            self::assertSame(200, $promise->wait()->getStatusCode());
            self::assertTrue($closed);
            self::assertInstanceOf(P\PromiseInterface::class, $spawned);
            self::assertTrue(P\Is::rejected($spawned));

            try {
                $spawned->wait();
                self::fail('Expected HandlerClosedException.');
            } catch (HandlerClosedException $e) {
                self::assertSame('The cURL multi handler was closed before the transfer completed.', $e->getMessage());
                self::assertSame($spawnedRequest, $e->getRequest());
            }
        } finally {
            $handler->close();
            Server::flush();
        }
    }

    public function testCanCloseFromOnTrailersCallback(): void
    {
        Server::flush();
        Server::enqueueRawBytes(
            "HTTP/1.1 200 OK\r\n"
            ."Transfer-Encoding: chunked\r\n"
            ."Trailer: X-Checksum\r\n"
            ."\r\n"
            ."3\r\nabc\r\n"
            ."0\r\n"
            ."X-Checksum: abc123\r\n"
            ."\r\n"
        );

        $handler = new CurlMultiHandler(['select_timeout' => 0]);
        $closed = false;
        $trailers = null;

        $request = new Request('GET', Server::$url);
        $promise = $handler($request, [
            'timeout' => 5,
            'on_trailers' => static function (array $receivedTrailers) use ($handler, &$closed, &$trailers): void {
                $trailers = $receivedTrailers;
                $closed = true;
                $handler->close();
            },
        ]);

        try {
            // on_trailers runs inside CurlFactory::finish before on_stats;
            // the close is deferred until message processing finishes, so
            // the fulfilled response is still delivered.
            $response = $promise->wait();
            self::assertSame(200, $response->getStatusCode());
            self::assertSame('abc', (string) $response->getBody());
            self::assertSame(['x-checksum' => ['abc123']], $trailers);
            self::assertTrue($closed);

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

    public function testCloseFromOnStatsCallbackRejectsOtherInFlightTransfers(): void
    {
        Server::flush();
        Server::enqueue([new Response(200)]);

        $handler = new CurlMultiHandler(['select_timeout' => 0]);

        $request = new Request('GET', Server::$url);
        $promise = $handler($request, [
            'timeout' => 5,
            'on_stats' => static function () use ($handler): void {
                $handler->close();
            },
        ]);

        $delayed = new Request('GET', Server::$url);
        $delayedPromise = $handler($delayed, [
            'delay' => 10000,
        ]);

        try {
            $deadline = \microtime(true) + 5;

            while (P\Is::pending($promise)) {
                if (\microtime(true) >= $deadline) {
                    self::fail('Timed out waiting for the on_stats close.');
                }

                $handler->tick();
            }

            self::assertSame(200, $promise->wait()->getStatusCode());
            self::assertTrue(P\Is::rejected($delayedPromise));

            try {
                $delayedPromise->wait();
                self::fail('Expected HandlerClosedException.');
            } catch (HandlerClosedException $e) {
                self::assertSame('The cURL multi handler was closed before the transfer completed.', $e->getMessage());
                self::assertSame($delayed, $e->getRequest());
            }
        } finally {
            $handler->close();
            Server::flush();
        }
    }

    public function testCloseFromOnStatsCallbackRejectsAttachedSiblingTransfer(): void
    {
        self::skipIfConnectionCapCurlMultiOptionsUnavailable();

        Server::flush();
        Server::enqueue([new Response(200)]);

        $handler = new CurlMultiHandler(['select_timeout' => 0, 'max_host_connections' => 1]);

        $request = new Request('GET', Server::$url);
        $promise = $handler($request, [
            'timeout' => 5,
            'on_stats' => static function () use ($handler): void {
                $handler->close();
            },
        ]);

        // The sibling is dispatched into the multi handle immediately (no
        // delay), but the connection cap keeps it queued behind the first
        // transfer, so it is still attached and unprocessed when the
        // on_stats close runs.
        $sibling = new Request('GET', Server::$url);
        $siblingPromise = $handler($sibling, ['timeout' => 5]);

        self::assertSame([], self::readMultiProperty($handler, 'delays'));
        self::assertCount(2, self::readMultiProperty($handler, 'handles'));

        try {
            $deadline = \microtime(true) + 5;

            while (P\Is::pending($promise)) {
                if (\microtime(true) >= $deadline) {
                    self::fail('Timed out waiting for the on_stats close.');
                }

                $handler->tick();
            }

            self::assertSame(200, $promise->wait()->getStatusCode());
            self::assertTrue(P\Is::rejected($siblingPromise));

            try {
                $siblingPromise->wait();
                self::fail('Expected HandlerClosedException.');
            } catch (HandlerClosedException $e) {
                self::assertSame('The cURL multi handler was closed before the transfer completed.', $e->getMessage());
                self::assertSame($sibling, $e->getRequest());
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

        $activeRequest = new Request('GET', Server::$url);
        $delayedRequest = new Request('GET', Server::$url);
        $activePromise = $handler($activeRequest, [
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

        $delayedPromise = $handler($delayedRequest, [
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

            foreach ([[$activePromise, $activeRequest], [$delayedPromise, $delayedRequest]] as [$promise, $request]) {
                try {
                    $promise->wait();
                    self::fail('Expected HandlerClosedException.');
                } catch (HandlerClosedException $e) {
                    self::assertSame('The cURL multi handler was closed before the transfer completed.', $e->getMessage());
                    self::assertSame($request, $e->getRequest());
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

    public function testFinishThrowDoesNotAffectSiblingTransfers(): void
    {
        Server::flush();
        Server::enqueue([new Response(200), new Response(200)]);

        $handler = new CurlMultiHandler(['select_timeout' => 0]);
        $previous = new \RuntimeException('stats failed');

        $bad = $handler(new Request('GET', Server::$url), [
            'on_stats' => static function () use ($previous): void {
                throw $previous;
            },
        ]);
        $good = $handler(new Request('GET', Server::$url), []);

        try {
            $deadline = \microtime(true) + 5;

            while (P\Is::pending($bad) || P\Is::pending($good)) {
                if (\microtime(true) >= $deadline) {
                    self::fail('Timed out waiting for cURL multi transfers.');
                }

                $handler->tick();
            }

            self::assertTrue(P\Is::fulfilled($good));
            self::assertSame(200, $good->wait()->getStatusCode());

            self::assertTrue(P\Is::rejected($bad));
            try {
                $bad->wait();
                self::fail('Expected RuntimeException');
            } catch (\RuntimeException $e) {
                self::assertSame($previous, $e);
            }
        } finally {
            $handler->close();
            Server::flush();
        }
    }

    public function testReleasesHandleWhenOnStatsThrowsDuringTick(): void
    {
        Server::flush();
        Server::enqueue([new Response(200)]);

        $events = [];
        $handler = new CurlMultiHandler([
            'select_timeout' => 0,
            'handle_factory' => self::recordingHandleFactory($events),
        ]);
        $previous = new \RuntimeException('stats failed');
        $promise = $handler(new Request('GET', Server::$url), [
            'on_stats' => static function () use (&$events, $previous): void {
                $events[] = 'on_stats';
                throw $previous;
            },
        ]);

        try {
            self::tickUntilSettled($handler, $promise);

            self::assertTrue(P\Is::rejected($promise));
            self::assertSame(['release', 'on_stats'], $events);

            foreach (['handles', 'delays'] as $map) {
                $property = new \ReflectionProperty(CurlMultiHandler::class, $map);
                if (\PHP_VERSION_ID < 80100) {
                    $property->setAccessible(true);
                }

                self::assertSame([], $property->getValue($handler));
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

    public function testFirstProxyTunnelOwnerLatchesWithoutRecreatingMultiHandle(): void
    {
        $handler = new CurlMultiHandler();
        self::initMultiHandle($handler);
        $mh = self::readMultiHandle($handler);

        self::applyProxyTunnelOwnership($handler, self::easyWithSignature('sig-a'));

        self::assertSame('sig-a', self::readMultiProperty($handler, 'proxyTunnelOwner'));
        self::assertSame($mh, self::readMultiHandle($handler), 'The first owner must not recreate the multi handle.');
    }

    public function testIdleProxyTunnelOwnerChangeRecreatesMultiHandle(): void
    {
        $handler = new CurlMultiHandler();
        self::setMultiProperty($handler, 'proxyTunnelOwner', 'sig-a');
        self::initMultiHandle($handler);

        self::applyProxyTunnelOwnership($handler, self::easyWithSignature('sig-b'));

        self::assertSame('sig-b', self::readMultiProperty($handler, 'proxyTunnelOwner'));
        self::assertNull(self::readMultiHandle($handler), 'An idle owner change must release the multi handle for lazy recreation.');
    }

    public function testBusyProxyTunnelOwnerChangeIsolatesTheTransfer(): void
    {
        $handler = new CurlMultiHandler();
        self::setMultiProperty($handler, 'proxyTunnelOwner', 'sig-a');
        self::initMultiHandle($handler);
        $mh = self::readMultiHandle($handler);
        self::setMultiProperty($handler, 'handles', [0 => ['busy']]);

        self::applyProxyTunnelOwnership($handler, self::easyWithSignature('sig-b'));

        self::assertTrue($_SERVER['_curl'][\CURLOPT_FRESH_CONNECT]);
        self::assertTrue($_SERVER['_curl'][\CURLOPT_FORBID_REUSE]);
        self::assertSame('sig-a', self::readMultiProperty($handler, 'proxyTunnelOwner'), 'A busy owner change must not move the owner.');
        self::assertSame($mh, self::readMultiHandle($handler), 'A busy owner change must not recreate the multi handle.');
    }

    public function testProcessingMessagesGuardPreventsMultiRecreation(): void
    {
        $handler = new CurlMultiHandler();
        self::setMultiProperty($handler, 'proxyTunnelOwner', 'sig-a');
        self::initMultiHandle($handler);
        $mh = self::readMultiHandle($handler);
        self::setMultiProperty($handler, 'messageProcessingDepth', 1);

        self::applyProxyTunnelOwnership($handler, self::easyWithSignature('sig-b'));

        self::assertSame($mh, self::readMultiHandle($handler), 'Recreating the multi handle mid-iteration would corrupt the read loop.');
        self::assertTrue($_SERVER['_curl'][\CURLOPT_FRESH_CONNECT]);
        self::assertTrue($_SERVER['_curl'][\CURLOPT_FORBID_REUSE]);
    }

    public function testNullSignatureNeverDisturbsProxyTunnelOwnership(): void
    {
        $handler = new CurlMultiHandler();
        self::setMultiProperty($handler, 'proxyTunnelOwner', 'sig-a');
        self::initMultiHandle($handler);
        $mh = self::readMultiHandle($handler);

        self::applyProxyTunnelOwnership($handler, self::easyWithSignature(null));

        self::assertSame('sig-a', self::readMultiProperty($handler, 'proxyTunnelOwner'));
        self::assertSame($mh, self::readMultiHandle($handler));
        self::assertArrayNotHasKey(\CURLOPT_FRESH_CONNECT, $_SERVER['_curl'] ?? []);
    }

    public function testActiveForeignProxyTunnelForcesOwnerTransferIsolation(): void
    {
        $handler = new CurlMultiHandler();
        self::setMultiProperty($handler, 'proxyTunnelOwner', 'sig-a');
        self::setMultiProperty($handler, 'activeProxyTunnelSignatures', ['sig-a' => 1, 'sig-b' => 1]);

        $easy = self::easyWithSignature('sig-a');
        $isolate = \Closure::bind(static function (CurlMultiHandler $handler, EasyHandle $easy): void {
            $handler->isolateFromForeignActiveProxyTunnel($easy);
        }, null, CurlMultiHandler::class);
        $isolate($handler, $easy);

        self::assertTrue($_SERVER['_curl'][\CURLOPT_FRESH_CONNECT]);
        self::assertTrue($_SERVER['_curl'][\CURLOPT_FORBID_REUSE]);
        self::assertSame('sig-a', self::readMultiProperty($handler, 'proxyTunnelOwner'), 'Isolation must not move the scalar owner.');
    }

    public function testOwnerMatchingTransferIsNotIsolatedWhenNoForeignSignatureIsActive(): void
    {
        $handler = new CurlMultiHandler();
        self::setMultiProperty($handler, 'activeProxyTunnelSignatures', ['sig-a' => 1]);
        unset($_SERVER['_curl']);

        $easy = self::easyWithSignature('sig-a');
        $isolate = \Closure::bind(static function (CurlMultiHandler $handler, EasyHandle $easy): void {
            $handler->isolateFromForeignActiveProxyTunnel($easy);
        }, null, CurlMultiHandler::class);
        $isolate($handler, $easy);

        self::assertArrayNotHasKey(\CURLOPT_FRESH_CONNECT, $_SERVER['_curl'] ?? []);
    }

    public function testForeignTransferIsIsolatedWhenOwnerIsActive(): void
    {
        $handler = new CurlMultiHandler();
        self::setMultiProperty($handler, 'activeProxyTunnelSignatures', ['sig-a' => 1]);

        $easy = self::easyWithSignature('sig-b');
        $isolate = \Closure::bind(static function (CurlMultiHandler $handler, EasyHandle $easy): void {
            $handler->isolateFromForeignActiveProxyTunnel($easy);
        }, null, CurlMultiHandler::class);
        $isolate($handler, $easy);

        self::assertTrue($_SERVER['_curl'][\CURLOPT_FRESH_CONNECT]);
        self::assertTrue($_SERVER['_curl'][\CURLOPT_FORBID_REUSE]);
    }

    public function testActiveProxyTunnelSignatureCountsAreReferenceCounted(): void
    {
        $handler = new CurlMultiHandler();
        $first = self::easyWithSignature('sig-b');
        $second = self::easyWithSignature('sig-b');

        $mark = \Closure::bind(static function (CurlMultiHandler $handler, int $id, EasyHandle $easy): void {
            $handler->markProxyTunnelActive($id, $easy);
        }, null, CurlMultiHandler::class);
        $unmarkById = \Closure::bind(static function (CurlMultiHandler $handler, int $id): void {
            $handler->unmarkProxyTunnelActiveById($id);
        }, null, CurlMultiHandler::class);

        $mark($handler, (int) $first->handle, $first);
        $mark($handler, (int) $second->handle, $second);
        self::assertSame(['sig-b' => 2], self::readMultiProperty($handler, 'activeProxyTunnelSignatures'));

        $unmarkById($handler, (int) $first->handle);
        self::assertSame(['sig-b' => 1], self::readMultiProperty($handler, 'activeProxyTunnelSignatures'));

        $unmarkById($handler, (int) $second->handle);
        self::assertSame([], self::readMultiProperty($handler, 'activeProxyTunnelSignatures'));
        self::assertSame([], self::readMultiProperty($handler, 'activeProxyTunnelHandles'));
    }

    public function testDelayedTransferIsNotActiveUntilAddedToMultiHandle(): void
    {
        $handler = new CurlMultiHandler();
        $easy = self::easyWithSignature('sig-a');
        $easy->options = ['delay' => 10000];

        $addRequest = \Closure::bind(static function (CurlMultiHandler $handler, array $entry): void {
            $handler->addRequest($entry);
        }, null, CurlMultiHandler::class);
        $addHandle = \Closure::bind(static function (CurlMultiHandler $handler, int $id, EasyHandle $easy): void {
            $handler->addHandleToMulti($id, $easy);
        }, null, CurlMultiHandler::class);

        $addRequest($handler, ['easy' => $easy, 'deferred' => new P\Promise()]);
        self::assertSame([], self::readMultiProperty($handler, 'activeProxyTunnelSignatures'), 'A delayed transfer must not be counted before it attaches.');

        $addHandle($handler, (int) $easy->handle, $easy);
        self::assertSame(['sig-a' => 1], self::readMultiProperty($handler, 'activeProxyTunnelSignatures'), 'The transfer must be counted only once attached.');
    }

    public function testDeferredCancelDoesNotDoubleDecrementActiveSignature(): void
    {
        $handler = new CurlMultiHandler();
        $easy = self::easyWithSignature('sig-a');
        $id = (int) $easy->handle;

        $mark = \Closure::bind(static function (CurlMultiHandler $handler, int $id, EasyHandle $easy): void {
            $handler->markProxyTunnelActive($id, $easy);
        }, null, CurlMultiHandler::class);
        $unmarkById = \Closure::bind(static function (CurlMultiHandler $handler, int $id): void {
            $handler->unmarkProxyTunnelActiveById($id);
        }, null, CurlMultiHandler::class);

        $mark($handler, $id, $easy);
        self::assertSame(['sig-a' => 1], self::readMultiProperty($handler, 'activeProxyTunnelSignatures'));

        $unmarkById($handler, $id);
        $unmarkById($handler, $id);

        self::assertSame([], self::readMultiProperty($handler, 'activeProxyTunnelSignatures'));
        self::assertSame([], self::readMultiProperty($handler, 'activeProxyTunnelHandles'));
    }

    public function testCloseClearsActiveProxyTunnelState(): void
    {
        $handler = new CurlMultiHandler();
        $easy = self::easyWithSignature('sig-a');
        $mark = \Closure::bind(static function (CurlMultiHandler $handler, int $id, EasyHandle $easy): void {
            $handler->markProxyTunnelActive($id, $easy);
        }, null, CurlMultiHandler::class);
        $mark($handler, (int) $easy->handle, $easy);
        self::assertSame(['sig-a' => 1], self::readMultiProperty($handler, 'activeProxyTunnelSignatures'));

        $handler->close();

        self::assertSame([], self::readMultiProperty($handler, 'activeProxyTunnelSignatures'));
        self::assertSame([], self::readMultiProperty($handler, 'activeProxyTunnelHandles'));
    }

    public function testCompletionUnmarksBeforeFinishCanReenter(): void
    {
        $handler = new CurlMultiHandler();
        self::initMultiHandle($handler);
        $easy = self::easyWithSignature('sig-a');
        $id = (int) $easy->handle;

        $mark = \Closure::bind(static function (CurlMultiHandler $handler, int $id, EasyHandle $easy): void {
            $handler->markProxyTunnelActive($id, $easy);
        }, null, CurlMultiHandler::class);
        $remove = \Closure::bind(static function (CurlMultiHandler $handler, int $id, $handle): void {
            $handler->removeHandleFromMulti($id, $handle);
        }, null, CurlMultiHandler::class);

        $mark($handler, $id, $easy);
        self::assertSame(['sig-a' => 1], self::readMultiProperty($handler, 'activeProxyTunnelSignatures'));
        self::assertSame([$id => 'sig-a'], self::readMultiProperty($handler, 'activeProxyTunnelHandles'));

        $remove($handler, $id, $easy->handle);

        self::assertSame([], self::readMultiProperty($handler, 'activeProxyTunnelSignatures'));
        self::assertSame([], self::readMultiProperty($handler, 'activeProxyTunnelHandles'));
    }

    public function testNoDelayAddRequestIsolatesAndMarksThroughTheWrapper(): void
    {
        $handler = new CurlMultiHandler();
        self::setMultiProperty($handler, 'proxyTunnelOwner', 'sig-a');
        self::setMultiProperty($handler, 'activeProxyTunnelSignatures', ['sig-b' => 1]);
        self::setMultiProperty($handler, 'activeProxyTunnelHandles', [-1 => 'sig-b']);

        $addRequest = \Closure::bind(static function (CurlMultiHandler $handler, array $entry): void {
            $handler->addRequest($entry);
        }, null, CurlMultiHandler::class);

        $easy = self::easyWithSignature('sig-a');
        $easy->options = [];
        $addRequest($handler, ['easy' => $easy, 'deferred' => new P\Promise()]);

        self::assertTrue($_SERVER['_curl'][\CURLOPT_FRESH_CONNECT]);
        self::assertTrue($_SERVER['_curl'][\CURLOPT_FORBID_REUSE]);
        self::assertSame(1, self::readMultiProperty($handler, 'activeProxyTunnelSignatures')['sig-a'] ?? 0, 'The no-delay transfer must be marked active.');

        unset($_SERVER['_curl']);
        $nullEasy = self::easyWithSignature(null);
        $nullEasy->options = [];
        $addRequest($handler, ['easy' => $nullEasy, 'deferred' => new P\Promise()]);

        self::assertArrayNotHasKey(\CURLOPT_FRESH_CONNECT, $_SERVER['_curl'] ?? []);
        self::assertArrayNotHasKey((int) $nullEasy->handle, self::readMultiProperty($handler, 'activeProxyTunnelHandles'));
    }

    public function testNullSignatureNeverEntersActiveMaps(): void
    {
        $handler = new CurlMultiHandler();
        $nullEasy = self::easyWithSignature(null);

        $isolate = \Closure::bind(static function (CurlMultiHandler $handler, EasyHandle $easy): void {
            $handler->isolateFromForeignActiveProxyTunnel($easy);
        }, null, CurlMultiHandler::class);
        $mark = \Closure::bind(static function (CurlMultiHandler $handler, int $id, EasyHandle $easy): void {
            $handler->markProxyTunnelActive($id, $easy);
        }, null, CurlMultiHandler::class);

        self::setMultiProperty($handler, 'activeProxyTunnelSignatures', ['sig-b' => 1]);
        unset($_SERVER['_curl']);
        $isolate($handler, $nullEasy);
        self::assertArrayNotHasKey(\CURLOPT_FRESH_CONNECT, $_SERVER['_curl'] ?? []);

        self::setMultiProperty($handler, 'activeProxyTunnelSignatures', []);
        $mark($handler, (int) $nullEasy->handle, $nullEasy);
        self::assertSame([], self::readMultiProperty($handler, 'activeProxyTunnelSignatures'));
        self::assertSame([], self::readMultiProperty($handler, 'activeProxyTunnelHandles'));
    }

    private static function easyWithSignature(?string $signature): EasyHandle
    {
        $easy = new EasyHandle();
        $easy->request = new Request('GET', 'https://example.com');
        $easy->handle = \curl_init();
        $easy->proxyTunnelSignature = $signature;

        return $easy;
    }

    private static function applyProxyTunnelOwnership(CurlMultiHandler $handler, EasyHandle $easy): void
    {
        $invoke = \Closure::bind(static function (CurlMultiHandler $handler, EasyHandle $easy): void {
            $handler->applyProxyTunnelOwnership($easy);
        }, null, CurlMultiHandler::class);

        $invoke($handler, $easy);
    }

    private static function initMultiHandle(CurlMultiHandler $handler): void
    {
        $init = \Closure::bind(static function (CurlMultiHandler $handler): void {
            $handler->getMultiHandle();
        }, null, CurlMultiHandler::class);

        $init($handler);
    }

    private static function skipIfConnectionCapCurlMultiOptionsUnavailable(): void
    {
        if (!CurlVersion::supportsCurlHandler()) {
            self::markTestSkipped('cURL multi connection cap options are unavailable.');
        }
    }

    /**
     * @return resource|\CurlMultiHandle|null
     */
    private static function readMultiHandle(CurlMultiHandler $handler)
    {
        $get = \Closure::bind(static function (CurlMultiHandler $handler) {
            return $handler->multiHandle;
        }, null, CurlMultiHandler::class);

        return $get($handler);
    }

    /**
     * @param mixed $value
     */
    private static function setMultiProperty(CurlMultiHandler $handler, string $name, $value): void
    {
        $set = \Closure::bind(static function (CurlMultiHandler $handler) use ($name, $value): void {
            $handler->{$name} = $value;
        }, null, CurlMultiHandler::class);

        $set($handler);
    }

    /**
     * @return mixed
     */
    private static function readMultiProperty(CurlMultiHandler $handler, string $name)
    {
        $get = \Closure::bind(static function (CurlMultiHandler $handler) use ($name) {
            return $handler->{$name};
        }, null, CurlMultiHandler::class);

        return $get($handler);
    }

    /**
     * Repeatedly runs the nonblocking native execution step until the given
     * number of transfers remains running, without selecting or processing
     * completion messages.
     */
    private static function driveUntilActiveTransferCount(CurlMultiHandler $handler, int $count): void
    {
        $tickInQueue = new \ReflectionMethod(CurlMultiHandler::class, 'tickInQueue');
        if (\PHP_VERSION_ID < 80100) {
            $tickInQueue->setAccessible(true);
        }

        $deadline = \microtime(true) + 5;

        do {
            $tickInQueue->invoke($handler);
            \usleep(5000);
        } while (self::readMultiProperty($handler, 'active') !== $count && \microtime(true) < $deadline);

        self::assertSame($count, self::readMultiProperty($handler, 'active'), 'Timed out waiting for the expected number of running transfers.');
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

    /**
     * @param array<int, string> $events
     */
    private static function recordingHandleFactory(array &$events): CurlFactoryInterface
    {
        return new class($events) implements CurlFactoryInterface {
            /** @var array<int, string> */
            private $events;

            /** @var CurlFactory */
            private $factory;

            public function __construct(array &$events)
            {
                $this->events = &$events;
                $this->factory = new CurlFactory(1);
            }

            public function create(RequestInterface $request, array $options): EasyHandle
            {
                return $this->factory->create($request, $options);
            }

            public function release(EasyHandle $easy): void
            {
                $this->events[] = 'release';
                $this->factory->release($easy);
            }
        };
    }

    private static function tickUntilSettled(CurlMultiHandler $handler, P\PromiseInterface $promise): void
    {
        $deadline = \microtime(true) + 5;
        while (P\Is::pending($promise) && \microtime(true) < $deadline) {
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

    private static function assertHandlerShareWasCreated(): void
    {
        $locks = [\CURL_LOCK_DATA_DNS];
        if (CurlVersion::supportsSslSessionSharing()) {
            $locks[] = \CURL_LOCK_DATA_SSL_SESSION;
        }

        self::assertSame(1, $_SERVER['_curl_share_init_count']);
        self::assertSame($locks, $_SERVER['_curl_share'][\CURLSHOPT_SHARE]);
    }

    private static function curlSslFeature(): int
    {
        if (!\defined('CURL_VERSION_SSL')) {
            self::markTestSkipped('CURL_VERSION_SSL is not available.');
        }

        return \CURL_VERSION_SSL;
    }

    /**
     * @param array{version: string, features: int}|false|null $versionInfo
     *
     * @return array{version: string, features: int}|false|null
     */
    private static function setCurlVersionInfo($versionInfo)
    {
        $property = new \ReflectionProperty(CurlVersion::class, 'versionInfo');
        if (\PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }

        $previousVersionInfo = $property->getValue();

        $property->setValue(null, $versionInfo);

        return $previousVersionInfo;
    }

    public function testRejectsNativePhpUnserialization(): void
    {
        $class = CurlMultiHandler::class;

        try {
            \unserialize(\sprintf('O:%d:"%s":0:{}', \strlen($class), $class), ['allowed_classes' => [$class]]);
            self::fail('Expected unserialization to fail.');
        } catch (\LogicException $e) {
            self::assertSame($class.' should never be unserialized', $e->getMessage());
        }
    }
}
