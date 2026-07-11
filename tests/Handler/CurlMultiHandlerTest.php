<?php

namespace GuzzleHttp\Tests\Handler;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Handler\CurlFactoryInterface;
use GuzzleHttp\Handler\CurlMultiHandler;
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
        unset($_SERVER['_curl'], $_SERVER['_curl_multi'], $_SERVER['_curl_share'], $_SERVER['_curl_share_init_count'], $_SERVER['curl_multi_setopt_fail'], $_SERVER['curl_setopt_fail']);
    }

    public function tearDown(): void
    {
        unset($_SERVER['_curl'], $_SERVER['_curl_multi'], $_SERVER['_curl_share'], $_SERVER['_curl_share_init_count'], $_SERVER['curl_multi_setopt_fail'], $_SERVER['curl_setopt_fail'], $_SERVER['curl_test']);
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

    public function testRejectsNonCallableOnTrailersBeforeTransfer()
    {
        $handler = new CurlMultiHandler();

        $this->expectException(\InvalidArgumentException::class);
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

        self::readMultiProperty($handler, '_mh');

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

        $start = \microtime(true);

        try {
            $target->wait();
            self::fail('Expected the canceled target to reject.');
        } catch (P\CancellationException $e) {
        }

        self::assertLessThan(2.5, \microtime(true) - $start, 'The delayed wait slept over a queued cancellation.');
    }

    public function testExecuteRunsQueuedCancellationBeforeSleepingForDelays(): void
    {
        Server::flush();

        $handler = new CurlMultiHandler(['select_timeout' => 5]);
        $delayed = $handler(new Request('GET', Server::$url), ['delay' => 5000]);

        P\Utils::queue()->add(static function () use ($delayed): void {
            $delayed->cancel();
        });

        $start = \microtime(true);
        $handler->execute();

        self::assertTrue(P\Is::rejected($delayed));
        self::assertLessThan(2.5, \microtime(true) - $start, 'execute() slept over a queued cancellation.');
    }

    /**
     * @dataProvider invalidConnectionCapOptionProvider
     *
     * @param mixed $value
     */
    public function testRejectsInvalidConnectionCapOptions(string $option, $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($option.' must be a positive integer.');

        new CurlMultiHandler([$option => $value]);
    }

    public function testRejectsConnectionCapOptionsWhenLibcurlDoesNotSupportThem(): void
    {
        if (!\defined('CURLMOPT_MAX_HOST_CONNECTIONS') || !\defined('CURLMOPT_MAX_TOTAL_CONNECTIONS')) {
            self::markTestSkipped('cURL multi connection cap options are unavailable.');
        }

        $previousVersionInfo = self::setCurlVersionInfo(['version' => '7.29.0', 'features' => 0]);

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('requires PHP cURL support for CURLMOPT_MAX_HOST_CONNECTIONS');

            new CurlMultiHandler(['max_host_connections' => 1]);
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
        }
    }

    /**
     * @dataProvider connectionCapOptionProvider
     */
    public function testRejectsNamedAndRawConnectionCapOptions(string $option, string $constant): void
    {
        self::skipIfConnectionCapCurlMultiOptionsUnavailable();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($option.' conflicts with a '.$constant.' entry in the "options" array.');

        new CurlMultiHandler([
            $option => 1,
            'options' => [\constant($constant) => 2],
        ]);
    }

    /**
     * @dataProvider connectionCapOptionProvider
     */
    public function testDeprecatesRawConnectionCapCurlMultiOptions(string $_option, string $constant): void
    {
        self::skipIfConnectionCapCurlMultiOptionsUnavailable();

        $deprecation = self::captureDeprecation(static function () use ($constant): void {
            new CurlMultiHandler(['options' => [\constant($constant) => 2]]);
        });

        self::assertNotNull($deprecation, 'Expected a deprecation for the raw cURL multi connection cap option.');
        self::assertStringContainsString('Passing '.$constant, $deprecation);
        self::assertStringContainsString('Use the "'.$_option.'" client option or cURL multi handler option instead.', $deprecation);
    }

    public function testWarnsWhenCurlMultiOptionCannotBeApplied()
    {
        $handler = new CurlMultiHandler(['options' => [
            \CURLMOPT_MAXCONNECTS => 5,
        ]]);
        $_SERVER['curl_multi_setopt_fail'] = \CURLMOPT_MAXCONNECTS;

        $warning = null;
        \set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            if ($severity !== \E_USER_WARNING) {
                return false;
            }

            $warning = $message;

            return true;
        }, \E_USER_WARNING);

        try {
            self::readMultiProperty($handler, '_mh');
        } finally {
            \restore_error_handler();
        }

        self::assertNotNull($warning, 'Expected a warning for the rejected cURL multi option.');
        self::assertStringContainsString('Unable to apply the cURL multi option CURLMOPT_MAXCONNECTS', $warning);
        self::assertStringContainsString('ignored by the runtime libcurl', $warning);
    }

    public function testDeprecatesUnknownConstructorOption()
    {
        $deprecation = self::captureDeprecation(static function (): void {
            new CurlMultiHandler(['unknown' => true]);
        });

        self::assertNotNull($deprecation, 'Expected a deprecation for the unknown constructor option.');
        self::assertStringContainsString('The "unknown" CurlMultiHandler constructor option is unknown', $deprecation);
    }

    public function testRejectsExplicitMultiplexWhenPipeliningIsDisabled()
    {
        if (!CurlVersion::supportsHttp2() || !CurlVersion::supportsMultiplex()) {
            self::markTestSkipped('HTTP/2 or multiplex support is unavailable.');
        }

        $a = new CurlMultiHandler(['options' => [
            \CURLMOPT_PIPELINING => \CURLPIPE_NOTHING,
        ]]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The "multiplex" request option cannot be combined with a CurlMultiHandler CURLMOPT_PIPELINING option that disables multiplexing');
        $a(new Request('GET', Server::$url, [], null, '2.0'), ['multiplex' => Multiplexing::WAIT]);
    }

    public function testRejectsExplicitMultiplexWhenPipeliningIsHttp1Only()
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
        $this->expectExceptionMessage('The "multiplex" request option cannot be combined with a CurlMultiHandler CURLMOPT_PIPELINING option that disables multiplexing');
        $a(new Request('GET', Server::$url, [], null, '2.0'), ['multiplex' => Multiplexing::WAIT]);
    }

    public function testRejectsRequireWaitWhenPipeliningIsDisabled()
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
            $this->expectExceptionMessage('set the "multiplex" option to "eager"');
            $a(new Request('GET', 'https://example.com', [], null, '2.0'), ['multiplex' => Multiplexing::REQUIRE_WAIT]);
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
        }
    }

    public function testRejectsRequireEagerWhenPipeliningIsDisabled()
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
            $this->expectExceptionMessage('set the "multiplex" option to "eager"');
            $a(new Request('GET', 'https://example.com', [], null, '2.0'), ['multiplex' => Multiplexing::REQUIRE_EAGER]);
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
        }
    }

    public function testDefaultMultiplexDoesNotThrowWhenPipeliningIsDisabled()
    {
        if (!CurlVersion::supportsHttp2() || !CurlVersion::supportsMultiplex()) {
            self::markTestSkipped('HTTP/2 or multiplex support is unavailable.');
        }

        // The default (key absent) leaves multiplexing to libcurl: no PIPEWAIT
        // is written and the guard never fires - an explicit
        // wait/require-family option is required for the conflict.
        Server::flush();
        Server::enqueue([new Response()]);
        $a = new CurlMultiHandler(['options' => [
            \CURLMOPT_PIPELINING => \CURLPIPE_NOTHING,
        ]]);
        $response = $a(new Request('GET', Server::$url, [], null, '2.0'), [])->wait();

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayNotHasKey((int) \constant('CURLOPT_PIPEWAIT'), $_SERVER['_curl']);
    }

    public function testAllowsExplicitMultiplexWhenPipeliningIncludesMultiplexBit()
    {
        if (!CurlVersion::supportsHttp2() || !CurlVersion::supportsMultiplex()) {
            self::markTestSkipped('HTTP/2 or multiplex support is unavailable.');
        }

        $a = new CurlMultiHandler(['options' => [
            \CURLMOPT_PIPELINING => \CURLPIPE_MULTIPLEX,
        ]]);
        $promise = $a(new Request('GET', Server::$url, [], null, '2.0'), ['multiplex' => Multiplexing::WAIT]);
        $promise->cancel();
        self::assertInstanceOf(P\PromiseInterface::class, $promise);
    }

    public function testAllowsDisabledPipeliningWhenMultiplexIsEager()
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

    public function testAllowsExplicitWaitForHttp11WhenPipeliningIsDisabled()
    {
        if (!CurlVersion::supportsMultiplex()) {
            self::markTestSkipped('Multiplex support is unavailable.');
        }

        Server::flush();
        Server::enqueue([new Response()]);
        $a = new CurlMultiHandler(['options' => [
            \CURLMOPT_PIPELINING => \CURLPIPE_NOTHING,
        ]]);
        $response = $a(new Request('GET', Server::$url, [], null, '1.1'), ['multiplex' => Multiplexing::WAIT])->wait();
        self::assertSame(200, $response->getStatusCode());
    }

    public function testAllowsDisabledPipeliningWhenMultiplexIsAbsent()
    {
        if (!CurlVersion::supportsMultiplex()) {
            self::markTestSkipped('Multiplex support is unavailable.');
        }

        Server::flush();
        Server::enqueue([new Response()]);
        $a = new CurlMultiHandler(['options' => [
            \CURLMOPT_PIPELINING => \CURLPIPE_NOTHING,
        ]]);
        $response = $a(new Request('GET', Server::$url), [])->wait();
        self::assertSame(200, $response->getStatusCode());
    }

    public static function explicitMultiplexRawPipewaitProvider(): iterable
    {
        yield 'eager with raw true' => [Multiplexing::EAGER, true];
        yield 'wait with raw false' => [Multiplexing::WAIT, false];
        yield 'require_eager with raw true' => [Multiplexing::REQUIRE_EAGER, true];
        yield 'require_wait with raw false' => [Multiplexing::REQUIRE_WAIT, false];
    }

    /**
     * @dataProvider explicitMultiplexRawPipewaitProvider
     *
     * @param mixed $rawValue
     */
    public function testRejectsRawPipewaitWithExplicitMultiplex(string $multiplex, $rawValue)
    {
        if (!\defined('CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE') || !\defined('CURLOPT_PIPEWAIT') || !\defined('CURL_VERSION_HTTP2')) {
            self::markTestSkipped('CURLOPT_PIPEWAIT or HTTP/2 cURL constants are unavailable.');
        }

        $previousVersionInfo = self::setCurlVersionInfo([
            'version' => '8.14.0',
            'features' => self::curlSslFeature() | \CURL_VERSION_HTTP2,
        ]);

        try {
            $a = new CurlMultiHandler();

            // Key presence conflicts whatever the raw value: WAIT with a raw
            // false and EAGER with a raw true are both second authorities.
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('The "multiplex" request option cannot be combined with the raw CURLOPT_PIPEWAIT cURL option on the cURL multi handler');
            $a(new Request('GET', 'https://example.com', [], null, '2.0'), [
                'multiplex' => $multiplex,
                'curl' => [(int) \constant('CURLOPT_PIPEWAIT') => $rawValue],
            ]);
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
        }
    }

    public function testAllowsRawPipewaitWithoutMultiplexOption()
    {
        if (!\defined('CURLOPT_PIPEWAIT')) {
            self::markTestSkipped('CURLOPT_PIPEWAIT is unavailable.');
        }

        Server::flush();
        Server::enqueue([new Response()]);
        $a = new CurlMultiHandler();
        $response = $a(new Request('GET', Server::$url), [
            'curl' => [(int) \constant('CURLOPT_PIPEWAIT') => true],
        ])->wait();

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($_SERVER['_curl'][(int) \constant('CURLOPT_PIPEWAIT')]);
    }

    public function testRawPipewaitRejectionLeavesHandlerUsable()
    {
        if (!\defined('CURLOPT_PIPEWAIT') || !CurlVersion::supportsHttp2() || !CurlVersion::supportsMultiplex()) {
            self::markTestSkipped('CURLOPT_PIPEWAIT, HTTP/2, or multiplex support is unavailable.');
        }

        $a = new CurlMultiHandler();

        try {
            $a(new Request('GET', Server::$url, [], null, '2.0'), [
                'multiplex' => Multiplexing::WAIT,
                'curl' => [(int) \constant('CURLOPT_PIPEWAIT') => true],
            ]);
            self::fail('Expected the raw CURLOPT_PIPEWAIT conflict to be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('CURLOPT_PIPEWAIT', $e->getMessage());
        }

        Server::flush();
        Server::enqueue([new Response()]);
        $response = $a(new Request('GET', Server::$url), [])->wait();
        self::assertSame(200, $response->getStatusCode());
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
    public function testRejectsNonScalarPipeliningWithExplicitMultiplex(string $multiplex, $pipelining)
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
            $a(new Request('GET', 'https://example.com', [], null, '2.0'), ['multiplex' => $multiplex]);
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
        }
    }

    public function testAllowsExplicitMultiplexWithCombinedPipeliningMask()
    {
        if (!CurlVersion::supportsHttp2() || !CurlVersion::supportsMultiplex()) {
            self::markTestSkipped('HTTP/2 or multiplex support is unavailable.');
        }

        $a = new CurlMultiHandler(['options' => [
            \CURLMOPT_PIPELINING => \CURLPIPE_HTTP1 | \CURLPIPE_MULTIPLEX,
        ]]);
        $promise = $a(new Request('GET', Server::$url, [], null, '2.0'), ['multiplex' => Multiplexing::WAIT]);
        $promise->cancel();
        self::assertInstanceOf(P\PromiseInterface::class, $promise);
    }

    public function testAllowsExplicitMultiplexWithNonArrayOptions()
    {
        if (!CurlVersion::supportsHttp2() || !CurlVersion::supportsMultiplex()) {
            self::markTestSkipped('HTTP/2 or multiplex support is unavailable.');
        }

        // A legacy non-array "options" value is tolerated by the constructor
        // and cannot contain CURLMOPT_PIPELINING, so probing it for the
        // conflict must not fault.
        $a = new CurlMultiHandler(['options' => new \stdClass()]);

        $promise = $a(new Request('GET', Server::$url, [], null, '2.0'), ['multiplex' => Multiplexing::WAIT]);
        $promise->cancel();
        self::assertInstanceOf(P\PromiseInterface::class, $promise);
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
        self::assertEquals(2, self::readSelectTimeout($a));
    }

    public function testDeprecatesInvalidSelectTimeout()
    {
        $deprecation = self::captureDeprecation(static function (): void {
            new CurlMultiHandler(['select_timeout' => []]);
        });

        self::assertNotNull($deprecation, 'Expected a deprecation for the invalid select_timeout option.');
        self::assertStringContainsString('Passing a non-numeric "select_timeout" CurlMultiHandler option is deprecated', $deprecation);
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

    public function testTransportSharingOptionAppliesCurlShare(): void
    {
        self::skipIfCurlShareIsUnavailable();
        $previous = self::setCurlVersionInfo(['version' => '8.6.0', 'features' => self::curlSslFeature()]);

        try {
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
        } finally {
            self::setCurlVersionInfo($previous);
        }
    }

    public function testPreferredTransportSharingCanBeUsedWithCustomFactory(): void
    {
        $handler = new CurlMultiHandler([
            'handle_factory' => new CurlFactory(0),
            'transport_sharing' => TransportSharing::HANDLER_PREFER,
        ]);

        self::assertInstanceOf(CurlMultiHandler::class, $handler);
    }

    public function testRequiredTransportSharingCannotBeUsedWithCustomFactory(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('handle_factory');

        new CurlMultiHandler([
            'handle_factory' => new CurlFactory(0),
            'transport_sharing' => TransportSharing::HANDLER_REQUIRE,
        ]);
    }

    public function testDisabledTransportSharingCanBeUsedWithCustomFactory(): void
    {
        $handler = new CurlMultiHandler([
            'handle_factory' => new CurlFactory(0),
            'transport_sharing' => TransportSharing::NONE,
        ]);

        self::assertInstanceOf(CurlMultiHandler::class, $handler);
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

    public function testCanCancelFromProgressCallback()
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
            if (\method_exists($handler, 'close')) {
                $handler->close();
            }

            Server::flush();
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

    public function testManualTickRejectsPromiseWhenFinishThrows()
    {
        Server::flush();
        Server::enqueue([new Response(200)]);

        $handler = new CurlMultiHandler(['select_timeout' => 0]);
        $previous = new \RuntimeException('stats failed');
        $promise = $handler(new Request('GET', Server::$url), [
            'on_stats' => static function () use ($previous) {
                throw $previous;
            },
        ]);

        try {
            $deadline = \microtime(true) + 5;
            while (P\Is::pending($promise) && \microtime(true) < $deadline) {
                $handler->tick();
            }

            self::assertTrue(P\Is::rejected($promise));

            try {
                $promise->wait();
                self::fail('Expected RuntimeException');
            } catch (\RuntimeException $e) {
                self::assertSame($previous, $e);
            }
        } finally {
            Server::flush();
        }
    }

    public function testFinishThrowDoesNotAffectSiblingTransfers()
    {
        Server::flush();
        Server::enqueue([new Response(200), new Response(200)]);

        $handler = new CurlMultiHandler(['select_timeout' => 0]);
        $previous = new \RuntimeException('stats failed');

        $bad = $handler(new Request('GET', Server::$url), [
            'on_stats' => static function () use ($previous) {
                throw $previous;
            },
        ]);
        $good = $handler(new Request('GET', Server::$url), []);

        try {
            $deadline = \microtime(true) + 5;
            while ((P\Is::pending($bad) || P\Is::pending($good)) && \microtime(true) < $deadline) {
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
            Server::flush();
        }
    }

    public function testReleasesHandleWhenOnStatsThrowsDuringTick()
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
            'on_stats' => static function () use (&$events, $previous) {
                $events[] = 'on_stats';
                throw $previous;
            },
        ]);

        try {
            $deadline = \microtime(true) + 5;
            while (P\Is::pending($promise) && \microtime(true) < $deadline) {
                $handler->tick();
            }

            self::assertTrue(P\Is::rejected($promise));
            self::assertSame(['on_stats', 'release'], $events);

            foreach (['handles', 'delays'] as $map) {
                $property = new \ReflectionProperty(CurlMultiHandler::class, $map);
                if (\PHP_VERSION_ID < 80100) {
                    $property->setAccessible(true);
                }

                self::assertSame([], $property->getValue($handler));
            }
        } finally {
            Server::flush();
        }
    }

    public function testFailedAttachmentRollsBackImmediateRequest(): void
    {
        $handler = new CurlMultiHandler();
        $_SERVER['curl_multi_add_handle_result'] = \CURLM_INTERNAL_ERROR;

        try {
            $handler(new Request('GET', Server::$url), []);
            self::fail('Expected RequestException.');
        } catch (RequestException $e) {
            self::assertStringContainsString('Unable to add the cURL handle', $e->getMessage());
        }

        self::assertSame([], self::readMultiProperty($handler, 'handles'));
        self::assertSame([], self::readMultiProperty($handler, 'delays'));
        self::assertSame([], self::readMultiProperty($handler, 'activeProxyTunnelHandles'));

        unset($_SERVER['curl_multi_add_handle_result']);
        Server::flush();
        Server::enqueue([new Response(200)]);

        self::assertSame(200, $handler(new Request('GET', Server::$url), [])->wait()->getStatusCode());
    }

    public function testFailedAttachmentRejectsEscapedDelayedRequest(): void
    {
        $handler = new CurlMultiHandler();
        $promise = $handler(new Request('GET', Server::$url), ['delay' => 1]);

        $handles = self::readMultiProperty($handler, 'handles');
        self::assertCount(1, $handles);
        $id = \key($handles);

        $_SERVER['curl_multi_add_handle_result'] = \CURLM_INTERNAL_ERROR;
        self::setMultiProperty($handler, 'delays', [$id => Utils::currentTime() - 1]);

        $handler->tick();

        self::assertTrue(P\Is::rejected($promise));
        self::assertSame([], self::readMultiProperty($handler, 'handles'));
        self::assertSame([], self::readMultiProperty($handler, 'delays'));

        try {
            $promise->wait();
            self::fail('Expected RequestException.');
        } catch (RequestException $e) {
            self::assertStringContainsString('Unable to add the cURL handle', $e->getMessage());
        }
    }

    public function testValidSiblingSurvivesAnotherRequestsFailedAttachment(): void
    {
        Server::flush();
        Server::enqueue([new Response(200)]);

        $handler = new CurlMultiHandler();
        $sibling = $handler(new Request('GET', Server::$url), []);

        $_SERVER['curl_multi_add_handle_result'] = \CURLM_INTERNAL_ERROR;

        try {
            $handler(new Request('GET', Server::$url), []);
            self::fail('Expected RequestException.');
        } catch (RequestException $e) {
            self::assertStringContainsString('Unable to add the cURL handle', $e->getMessage());
        }

        unset($_SERVER['curl_multi_add_handle_result']);

        self::assertCount(1, self::readMultiProperty($handler, 'handles'));
        self::assertSame(200, $sibling->wait()->getStatusCode());
    }

    public function testUsesTimeoutEnvironmentVariables()
    {
        unset($_SERVER['GUZZLE_CURL_SELECT_TIMEOUT']);
        \putenv('GUZZLE_CURL_SELECT_TIMEOUT=');

        try {
            $a = new CurlMultiHandler();
            // Default if no options are given and no environment variable is set
            self::assertEquals(1, self::readSelectTimeout($a));

            \putenv('GUZZLE_CURL_SELECT_TIMEOUT=3');
            $a = new CurlMultiHandler();
            // Handler reads from the environment if no options are given
            self::assertEquals(3, self::readSelectTimeout($a));
        } finally {
            \putenv('GUZZLE_CURL_SELECT_TIMEOUT=');
        }
    }

    public function throwsWhenAccessingInvalidProperty()
    {
        $h = new CurlMultiHandler();

        $this->expectException(\BadMethodCallException::class);
        $h->foo;
    }

    public function testFirstProxyTunnelOwnerLatchesWithoutRecreatingMultiHandle(): void
    {
        $handler = new CurlMultiHandler();

        // Initialize the multi handle so we can detect an unwanted recreation.
        $mh = self::readMultiProperty($handler, '_mh');

        self::applyProxyTunnelOwnership($handler, self::easyWithSignature('sig-a'));

        self::assertSame('sig-a', self::readMultiProperty($handler, 'proxyTunnelOwner'));
        self::assertSame($mh, self::readMultiProperty($handler, '_mh'), 'The first owner must not recreate the multi handle.');
    }

    public function testIdleProxyTunnelOwnerChangeRecreatesMultiHandle(): void
    {
        $handler = new CurlMultiHandler();
        self::setMultiProperty($handler, 'proxyTunnelOwner', 'sig-a');
        $mh = self::readMultiProperty($handler, '_mh');

        self::applyProxyTunnelOwnership($handler, self::easyWithSignature('sig-b'));

        self::assertSame('sig-b', self::readMultiProperty($handler, 'proxyTunnelOwner'));
        self::assertNotSame($mh, self::readMultiProperty($handler, '_mh'), 'An idle owner change must recreate the multi handle.');
    }

    public function testConnectionCapsAreReappliedAfterIdleProxyTunnelOwnerHandover(): void
    {
        self::skipIfConnectionCapCurlMultiOptionsUnavailable();

        $handler = new CurlMultiHandler([
            'max_host_connections' => 2,
            'max_total_connections' => 5,
        ]);
        self::setMultiProperty($handler, 'proxyTunnelOwner', 'sig-a');
        $mh = self::readMultiProperty($handler, '_mh');
        self::assertSame(2, $_SERVER['_curl_multi'][\constant('CURLMOPT_MAX_HOST_CONNECTIONS')]);

        unset($_SERVER['_curl_multi']);
        self::applyProxyTunnelOwnership($handler, self::easyWithSignature('sig-b'));

        self::assertNotSame($mh, self::readMultiProperty($handler, '_mh'), 'An idle owner change must recreate the multi handle.');
        self::assertSame(2, $_SERVER['_curl_multi'][\constant('CURLMOPT_MAX_HOST_CONNECTIONS')], 'The handover-recreated multi handle must re-apply the connection caps.');
        self::assertSame(5, $_SERVER['_curl_multi'][\constant('CURLMOPT_MAX_TOTAL_CONNECTIONS')], 'The handover-recreated multi handle must re-apply the connection caps.');
    }

    public function testBusyProxyTunnelOwnerChangeIsolatesTheTransfer(): void
    {
        $handler = new CurlMultiHandler();
        self::setMultiProperty($handler, 'proxyTunnelOwner', 'sig-a');
        $mh = self::readMultiProperty($handler, '_mh');
        // A busy multi: another transfer is tracked.
        self::setMultiProperty($handler, 'handles', [0 => ['busy']]);

        $easy = self::easyWithSignature('sig-b');
        self::applyProxyTunnelOwnership($handler, $easy);

        self::assertTrue($_SERVER['_curl'][\CURLOPT_FRESH_CONNECT]);
        self::assertTrue($_SERVER['_curl'][\CURLOPT_FORBID_REUSE]);
        self::assertSame('sig-a', self::readMultiProperty($handler, 'proxyTunnelOwner'), 'A busy owner change must not move the owner.');
        self::assertSame($mh, self::readMultiProperty($handler, '_mh'), 'A busy owner change must not recreate the multi handle.');
    }

    public static function proxyTunnelIsolationOptionProvider(): iterable
    {
        return [
            'fresh connect' => [\CURLOPT_FRESH_CONNECT, 'CURLOPT_FRESH_CONNECT'],
            'forbid reuse' => [\CURLOPT_FORBID_REUSE, 'CURLOPT_FORBID_REUSE'],
        ];
    }

    /**
     * @dataProvider proxyTunnelIsolationOptionProvider
     */
    public function testIsolationOptionFailureFailsClosedAndReleasesTheTransfer(int $option, string $name): void
    {
        $events = [];
        $handler = new CurlMultiHandler(['handle_factory' => self::recordingHandleFactory($events)]);
        self::setMultiProperty($handler, 'proxyTunnelOwner', 'sig-a');
        $mh = self::readMultiProperty($handler, '_mh');
        self::setMultiProperty($handler, 'handles', [0 => ['busy']]);

        $_SERVER['curl_setopt_fail'] = $option;

        try {
            $handler(new Request('GET', 'https://example.com'), [
                'proxy' => 'http://user:pass@proxy.example.com:8080',
            ]);
            self::fail('Expected RequestException.');
        } catch (RequestException $e) {
            self::assertStringContainsString($name, $e->getMessage());
            self::assertStringContainsString('isolate the transfer from foreign proxy tunnel connections', $e->getMessage());
        } finally {
            unset($_SERVER['curl_setopt_fail']);
        }

        self::assertSame(['release'], $events, 'The failed easy handle must be released exactly once.');
        self::assertSame([0 => ['busy']], self::readMultiProperty($handler, 'handles'), 'No transfer may be added for the failed request.');
        self::assertSame([], self::readMultiProperty($handler, 'activeProxyTunnelSignatures'), 'A failed isolation must not mark an active signature.');
        self::assertSame([], self::readMultiProperty($handler, 'activeProxyTunnelHandles'));
        self::assertSame('sig-a', self::readMultiProperty($handler, 'proxyTunnelOwner'), 'The owner must not move on a failed isolation.');
        self::assertSame($mh, self::readMultiProperty($handler, '_mh'), 'The multi handle must not be recreated.');
    }

    public function testAttachTimeIsolationFailureRollsBackThePendingRequest(): void
    {
        $events = [];
        $handler = new CurlMultiHandler(['handle_factory' => self::recordingHandleFactory($events)]);
        self::readMultiProperty($handler, '_mh');
        self::setMultiProperty($handler, 'activeProxyTunnelSignatures', ['sig-b' => 1]);
        self::setMultiProperty($handler, 'activeProxyTunnelHandles', [7 => 'sig-b']);

        $_SERVER['curl_setopt_fail'] = \CURLOPT_FRESH_CONNECT;

        try {
            $handler(new Request('GET', 'https://example.com'), [
                'proxy' => 'http://user:pass@proxy.example.com:8080',
            ]);
            self::fail('Expected RequestException.');
        } catch (RequestException $e) {
            self::assertStringContainsString('CURLOPT_FRESH_CONNECT', $e->getMessage());
            self::assertStringContainsString('isolate the transfer from foreign proxy tunnel connections', $e->getMessage());
        } finally {
            unset($_SERVER['curl_setopt_fail']);
        }

        self::assertSame(['release'], $events, 'The rolled-back easy handle must be released exactly once.');
        self::assertSame([], self::readMultiProperty($handler, 'handles'), 'The failed request must be rolled back out of the pending map.');
        self::assertSame([], self::readMultiProperty($handler, 'delays'));
        self::assertSame(['sig-b' => 1], self::readMultiProperty($handler, 'activeProxyTunnelSignatures'), 'The foreign attachment bookkeeping must be unchanged.');
        self::assertSame([7 => 'sig-b'], self::readMultiProperty($handler, 'activeProxyTunnelHandles'));
    }

    public function testProcessingMessagesGuardPreventsMultiRecreation(): void
    {
        $handler = new CurlMultiHandler();
        self::setMultiProperty($handler, 'proxyTunnelOwner', 'sig-a');
        $mh = self::readMultiProperty($handler, '_mh');
        // The multi is idle by every other measure, but a retried transfer is
        // re-invoking the handler from inside processMessages.
        self::setMultiProperty($handler, 'messageProcessingDepth', 1);

        $easy = self::easyWithSignature('sig-b');
        self::applyProxyTunnelOwnership($handler, $easy);

        self::assertSame($mh, self::readMultiProperty($handler, '_mh'), 'Recreating the multi handle mid-iteration would corrupt the read loop.');
        self::assertTrue($_SERVER['_curl'][\CURLOPT_FRESH_CONNECT]);
        self::assertTrue($_SERVER['_curl'][\CURLOPT_FORBID_REUSE]);
    }

    public function testNullSignatureNeverDisturbsProxyTunnelOwnership(): void
    {
        $handler = new CurlMultiHandler();
        self::setMultiProperty($handler, 'proxyTunnelOwner', 'sig-a');
        $mh = self::readMultiProperty($handler, '_mh');

        self::applyProxyTunnelOwnership($handler, self::easyWithSignature(null));

        self::assertSame('sig-a', self::readMultiProperty($handler, 'proxyTunnelOwner'));
        self::assertSame($mh, self::readMultiProperty($handler, '_mh'));
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
        $idFirst = (int) $first->handle;
        $idSecond = (int) $second->handle;

        $mark = \Closure::bind(static function (CurlMultiHandler $handler, EasyHandle $easy): void {
            $handler->markProxyTunnelActive($easy);
        }, null, CurlMultiHandler::class);
        $unmarkById = \Closure::bind(static function (CurlMultiHandler $handler, int $id): void {
            $handler->unmarkProxyTunnelActiveById($id);
        }, null, CurlMultiHandler::class);

        $mark($handler, $first);
        $mark($handler, $second);
        self::assertSame(['sig-b' => 2], self::readMultiProperty($handler, 'activeProxyTunnelSignatures'));

        $unmarkById($handler, $idFirst);
        self::assertSame(['sig-b' => 1], self::readMultiProperty($handler, 'activeProxyTunnelSignatures'));

        $unmarkById($handler, $idSecond);
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
        $addCurlHandle = \Closure::bind(static function (CurlMultiHandler $handler, EasyHandle $easy): void {
            $handler->addCurlHandle($easy);
        }, null, CurlMultiHandler::class);

        $addRequest($handler, ['easy' => $easy, 'deferred' => new P\Promise()]);
        self::assertSame([], self::readMultiProperty($handler, 'activeProxyTunnelSignatures'), 'A delayed transfer must not be counted before it attaches.');

        $addCurlHandle($handler, $easy);
        self::assertSame(['sig-a' => 1], self::readMultiProperty($handler, 'activeProxyTunnelSignatures'), 'The transfer must be counted only once attached.');
    }

    public function testDeferredCancelCleanupDoesNotDoubleDecrementActiveSignature(): void
    {
        $handler = new CurlMultiHandler();
        $easy = self::easyWithSignature('sig-a');
        $id = (int) $easy->handle;

        $mark = \Closure::bind(static function (CurlMultiHandler $handler, EasyHandle $easy): void {
            $handler->markProxyTunnelActive($easy);
        }, null, CurlMultiHandler::class);
        $unmarkById = \Closure::bind(static function (CurlMultiHandler $handler, int $id): void {
            $handler->unmarkProxyTunnelActiveById($id);
        }, null, CurlMultiHandler::class);
        $unmark = \Closure::bind(static function (CurlMultiHandler $handler, EasyHandle $easy): void {
            $handler->unmarkProxyTunnelActive($easy);
        }, null, CurlMultiHandler::class);

        $mark($handler, $easy);
        self::assertSame(['sig-a' => 1], self::readMultiProperty($handler, 'activeProxyTunnelSignatures'));

        $unmarkById($handler, $id);
        $unmark($handler, $easy);

        self::assertSame([], self::readMultiProperty($handler, 'activeProxyTunnelSignatures'));
        self::assertSame([], self::readMultiProperty($handler, 'activeProxyTunnelHandles'));
    }

    public function testCompletionUnmarksBeforeFinishCanReenter(): void
    {
        $handler = new CurlMultiHandler();
        $easy = self::easyWithSignature('sig-a');
        $id = (int) $easy->handle;

        $mark = \Closure::bind(static function (CurlMultiHandler $handler, EasyHandle $easy): void {
            $handler->markProxyTunnelActive($easy);
        }, null, CurlMultiHandler::class);
        $removeCompleted = \Closure::bind(static function (CurlMultiHandler $handler, int $id, $handle): void {
            $handler->removeCompletedHandleFromMulti($id, $handle);
        }, null, CurlMultiHandler::class);

        $mark($handler, $easy);
        self::assertSame(['sig-a' => 1], self::readMultiProperty($handler, 'activeProxyTunnelSignatures'));
        self::assertSame([$id => 'sig-a'], self::readMultiProperty($handler, 'activeProxyTunnelHandles'));

        $removeCompleted($handler, $id, $easy->handle);

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
        $mark = \Closure::bind(static function (CurlMultiHandler $handler, EasyHandle $easy): void {
            $handler->markProxyTunnelActive($easy);
        }, null, CurlMultiHandler::class);

        self::setMultiProperty($handler, 'activeProxyTunnelSignatures', ['sig-b' => 1]);
        unset($_SERVER['_curl']);
        $isolate($handler, $nullEasy);
        self::assertArrayNotHasKey(\CURLOPT_FRESH_CONNECT, $_SERVER['_curl'] ?? []);

        self::setMultiProperty($handler, 'activeProxyTunnelSignatures', []);
        $mark($handler, $nullEasy);
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

    private static function readSelectTimeout(CurlMultiHandler $handler)
    {
        $readSelectTimeout = \Closure::bind(static function (CurlMultiHandler $handler) {
            return $handler->selectTimeout;
        }, null, CurlMultiHandler::class);

        return $readSelectTimeout($handler);
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

    private static function captureDeprecation(callable $callback): ?string
    {
        $deprecation = null;
        \set_error_handler(static function (int $severity, string $message) use (&$deprecation): bool {
            if ($severity !== \E_USER_DEPRECATED) {
                return false;
            }

            $deprecation = $message;

            return true;
        }, \E_USER_DEPRECATED);

        try {
            $callback();
        } finally {
            \restore_error_handler();
        }

        return $deprecation;
    }

    private static function skipIfCurlShareIsUnavailable(): void
    {
        if (!\function_exists('curl_share_init') || !\function_exists('curl_share_setopt') || !\defined('CURLOPT_SHARE')) {
            self::markTestSkipped('cURL share handles are unavailable.');
        }
    }

    private static function skipIfConnectionCapCurlMultiOptionsUnavailable(): void
    {
        if (!CurlVersion::supportsConnectionCaps()) {
            self::markTestSkipped('cURL multi connection cap options are unavailable.');
        }
    }

    private static function curlSslFeature(): int
    {
        if (!\defined('CURL_VERSION_SSL')) {
            self::markTestSkipped('CURL_VERSION_SSL is unavailable.');
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
}
