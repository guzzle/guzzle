<?php

namespace GuzzleHttp\Tests\Handler;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Handler\CurlFactoryInterface;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Handler\CurlVersion;
use GuzzleHttp\Handler\EasyHandle;
use GuzzleHttp\HandlerStack;
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
        unset($_SERVER['_curl'], $_SERVER['_curl_multi'], $_SERVER['_curl_share'], $_SERVER['_curl_share_init_count'], $_SERVER['curl_multi_setopt_fail'], $_SERVER['curl_setopt_fail'], $_SERVER['curl_multi_add_handle_result']);
    }

    public function tearDown(): void
    {
        unset($_SERVER['_curl'], $_SERVER['_curl_multi'], $_SERVER['_curl_share'], $_SERVER['_curl_share_init_count'], $_SERVER['curl_multi_setopt_fail'], $_SERVER['curl_setopt_fail'], $_SERVER['curl_multi_add_handle_result'], $_SERVER['curl_test']);
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

    public function testAsynchronousWaitsDoNotWaitForOtherTransfers(): void
    {
        Server::flush();
        Server::enqueue([new Response(200)]);

        $handler = new CurlMultiHandler();

        $delayed = $handler(new Request('GET', Server::$url), ['delay' => 2000]);
        $immediate = $handler(new Request('GET', Server::$url), []);

        $response = $immediate->wait();

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue(P\Is::pending($delayed));

        $delayed->cancel();
    }

    public function testSiblingTransferCompletesWhenWaitedAfterTargetedWait(): void
    {
        Server::flush();
        Server::enqueue([new Response(200), new Response(200)]);

        $handler = new CurlMultiHandler();

        $delayed = $handler(new Request('GET', Server::$url), ['delay' => 1]);
        $immediate = $handler(new Request('GET', Server::$url), []);

        self::assertSame(200, $immediate->wait()->getStatusCode());
        self::assertSame(200, $delayed->wait()->getStatusCode());
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

    /**
     * @dataProvider connectionCapOptionProvider
     */
    public function testFailsClosedWhenNamedConnectionCapCannotBeApplied(string $option, string $constant): void
    {
        self::skipIfConnectionCapCurlMultiOptionsUnavailable();

        $handler = new CurlMultiHandler([$option => 2]);
        $_SERVER['curl_multi_setopt_fail'] = \constant($constant);

        try {
            self::readMultiProperty($handler, '_mh');
            self::fail('Expected InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Unable to apply the cURL multi option '.$constant, $e->getMessage());
            self::assertStringContainsString('rejected by the runtime libcurl', $e->getMessage());
        }

        self::assertFalse(self::multiHandleIsInitialized($handler), 'A failed initialization must not publish the multi handle.');

        // Removing the failure allows the same handler to retry.
        unset($_SERVER['curl_multi_setopt_fail']);
        self::readMultiProperty($handler, '_mh');
        self::assertTrue(self::multiHandleIsInitialized($handler));
        self::assertSame(2, $_SERVER['_curl_multi'][\constant($constant)]);
    }

    public function testEarlierOptionSuccessThenRequiredCapFailureDoesNotPublishHandle(): void
    {
        self::skipIfConnectionCapCurlMultiOptionsUnavailable();

        $handler = new CurlMultiHandler([
            'max_host_connections' => 2,
            'options' => [\CURLMOPT_MAXCONNECTS => 5],
        ]);
        $_SERVER['curl_multi_setopt_fail'] = \constant('CURLMOPT_MAX_HOST_CONNECTIONS');

        try {
            self::readMultiProperty($handler, '_mh');
            self::fail('Expected InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('rejected by the runtime libcurl', $e->getMessage());
        }

        self::assertFalse(self::multiHandleIsInitialized($handler), 'A partially configured multi handle must not be published.');
    }

    public function testThrowingWarningHandlerLeavesNoPartialState(): void
    {
        $handler = new CurlMultiHandler(['options' => [
            \CURLMOPT_MAXCONNECTS => 5,
        ]]);
        $_SERVER['curl_multi_setopt_fail'] = \CURLMOPT_MAXCONNECTS;

        \set_error_handler(static function (int $severity, string $message): bool {
            if ($severity !== \E_USER_WARNING) {
                return false;
            }

            throw new \RuntimeException($message);
        }, \E_USER_WARNING);

        try {
            $handler(new Request('GET', Server::$url), []);
            self::fail('Expected RuntimeException.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Unable to apply the cURL multi option CURLMOPT_MAXCONNECTS', $e->getMessage());
        } finally {
            \restore_error_handler();
        }

        self::assertFalse(self::multiHandleIsInitialized($handler), 'A promoted warning must not leave a partially configured handle.');
        self::assertSame([], self::readMultiProperty($handler, 'handles'));
        self::assertSame([], self::readMultiProperty($handler, 'delays'));

        unset($_SERVER['curl_multi_setopt_fail']);
        Server::flush();
        Server::enqueue([new Response(200)]);

        self::assertSame(200, $handler(new Request('GET', Server::$url), [])->wait()->getStatusCode());
    }

    public function testDelayedRequestRejectedWhenRequiredCapCannotBeApplied(): void
    {
        self::skipIfConnectionCapCurlMultiOptionsUnavailable();

        $handler = new CurlMultiHandler(['max_host_connections' => 2]);
        $_SERVER['curl_multi_setopt_fail'] = \constant('CURLMOPT_MAX_HOST_CONNECTIONS');

        $promise = $handler(new Request('GET', Server::$url), ['delay' => 1]);

        $handles = self::readMultiProperty($handler, 'handles');
        self::assertCount(1, $handles);
        $id = \key($handles);

        self::setMultiProperty($handler, 'delays', [$id => Utils::currentTime() - 1]);

        $handler->tick();

        self::assertTrue(P\Is::rejected($promise));
        self::assertSame([], self::readMultiProperty($handler, 'handles'));
        self::assertSame([], self::readMultiProperty($handler, 'delays'));
        self::assertFalse(self::multiHandleIsInitialized($handler), 'The tick must not recreate the just-failed multi handle.');

        try {
            $promise->wait();
            self::fail('Expected InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('rejected by the runtime libcurl', $e->getMessage());
        }
    }

    public function testSiblingDelayedRequestSurvivesRequiredCapFailure(): void
    {
        self::skipIfConnectionCapCurlMultiOptionsUnavailable();

        $handler = new CurlMultiHandler(['max_host_connections' => 2]);
        $_SERVER['curl_multi_setopt_fail'] = \constant('CURLMOPT_MAX_HOST_CONNECTIONS');

        $due = $handler(new Request('GET', Server::$url), ['delay' => 1]);
        $dueId = \key(self::readMultiProperty($handler, 'handles'));
        $pending = $handler(new Request('GET', Server::$url), ['delay' => 10000]);

        $delays = self::readMultiProperty($handler, 'delays');
        self::assertCount(2, $delays);
        $delays[$dueId] = Utils::currentTime() - 1;
        self::setMultiProperty($handler, 'delays', $delays);

        $handler->tick();

        self::assertTrue(P\Is::rejected($due));
        self::assertTrue(P\Is::pending($pending));

        $handles = self::readMultiProperty($handler, 'handles');
        self::assertCount(1, $handles);
        self::assertArrayNotHasKey($dueId, $handles);
        self::assertCount(1, self::readMultiProperty($handler, 'delays'));

        $pending->cancel();
    }

    public function testRejectsRequestLevelShareWithNamedConnectionCap(): void
    {
        self::skipIfConnectionCapCurlMultiOptionsUnavailable();
        self::skipIfCurlShareIsUnavailable();

        $share = \curl_share_init();
        self::assertNotFalse($share);

        $handler = new CurlMultiHandler(['max_host_connections' => 1]);

        try {
            $handler(new Request('GET', Server::$url), [
                'curl' => [\CURLOPT_SHARE => $share],
            ]);
            self::fail('Expected InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('CURLOPT_SHARE', $e->getMessage());
        } finally {
            if (\PHP_VERSION_ID < 80000 && \is_resource($share)) {
                \curl_share_close($share);
            }
        }
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

    public function testMultiplexNoneDisablesPipeliningOnTheMultiHandle()
    {
        Server::flush();
        Server::enqueue([new Response()]);
        $a = new CurlMultiHandler(['multiplex' => Multiplexing::NONE]);
        $response = $a(new Request('GET', Server::$url), [])->wait();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, $_SERVER['_curl_multi'][\CURLMOPT_PIPELINING]);
    }

    public static function invalidHandlerMultiplexProvider(): iterable
    {
        yield 'eager' => [Multiplexing::EAGER, 'The "multiplex" CurlMultiHandler option only accepts Multiplexing::NONE; the eager, wait, and required modes are request options.'];
        yield 'wait' => [Multiplexing::WAIT, 'The "multiplex" CurlMultiHandler option only accepts Multiplexing::NONE; the eager, wait, and required modes are request options.'];
        yield 'require_eager' => [Multiplexing::REQUIRE_EAGER, 'The "multiplex" CurlMultiHandler option only accepts Multiplexing::NONE; the eager, wait, and required modes are request options.'];
        yield 'require_wait' => [Multiplexing::REQUIRE_WAIT, 'The "multiplex" CurlMultiHandler option only accepts Multiplexing::NONE; the eager, wait, and required modes are request options.'];
        yield 'bool true' => [true, 'The "multiplex" CurlMultiHandler option must be null or Multiplexing::NONE; received bool.'];
        yield 'int' => [1, 'The "multiplex" CurlMultiHandler option must be null or Multiplexing::NONE; received int.'];
        yield 'unknown string' => ['never', 'The "multiplex" CurlMultiHandler option must be null or Multiplexing::NONE; received string.'];
    }

    /**
     * @dataProvider invalidHandlerMultiplexProvider
     *
     * @param mixed $value
     */
    public function testRejectsInvalidHandlerMultiplexValues($value, string $message)
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new CurlMultiHandler(['multiplex' => $value]);
    }

    public static function rawPipeliningWithMultiplexNoneProvider(): iterable
    {
        yield 'agreeing value' => [0];
        yield 'disagreeing value' => [2];
    }

    /**
     * @dataProvider rawPipeliningWithMultiplexNoneProvider
     */
    public function testRejectsMultiplexNoneWithRawPipelining(int $pipelining)
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('multiplex conflicts with a CURLMOPT_PIPELINING entry in the "options" array.');

        new CurlMultiHandler([
            'multiplex' => Multiplexing::NONE,
            'options' => [\CURLMOPT_PIPELINING => $pipelining],
        ]);
    }

    public function testRejectsMultiplexNoneWithNonArrayOptions()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('options must be an array of cURL multi options when using the "multiplex" option.');

        new CurlMultiHandler([
            'multiplex' => Multiplexing::NONE,
            'options' => 'invalid',
        ]);
    }

    public function testDeprecatesRawPipeliningCurlMultiOption()
    {
        $deprecation = self::captureDeprecation(static function (): void {
            new CurlMultiHandler(['options' => [\CURLMOPT_PIPELINING => 0]]);
        });

        self::assertNotNull($deprecation, 'Expected a deprecation for the raw CURLMOPT_PIPELINING option.');
        self::assertStringContainsString('Passing CURLMOPT_PIPELINING', $deprecation);
        self::assertStringContainsString('Use Multiplexing::NONE via the "multiplex" cURL multi handler or client option to disable multiplexing, or remove the raw option for the runtime default (multiplexing defaults on from libcurl 7.62, except 7.65.0 and 7.65.1) instead.', $deprecation);
    }

    public function testMultiplexNoneFailsClosedWhenPipeliningCannotBeApplied()
    {
        $handler = new CurlMultiHandler(['multiplex' => Multiplexing::NONE]);
        $_SERVER['curl_multi_setopt_fail'] = \CURLMOPT_PIPELINING;

        try {
            self::readMultiProperty($handler, '_mh');
            self::fail('Expected InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Unable to apply the cURL multi option CURLMOPT_PIPELINING', $e->getMessage());
            self::assertStringContainsString('rejected by the runtime libcurl', $e->getMessage());
        }

        self::assertFalse(self::multiHandleIsInitialized($handler), 'A failed initialization must not publish the multi handle.');

        // Removing the failure allows the same handler to retry.
        unset($_SERVER['curl_multi_setopt_fail']);
        self::readMultiProperty($handler, '_mh');
        self::assertTrue(self::multiHandleIsInitialized($handler));
        self::assertSame(0, $_SERVER['_curl_multi'][\CURLMOPT_PIPELINING]);
    }

    public function testRawPipeliningStillWarnsWhenItCannotBeApplied()
    {
        $_SERVER['curl_multi_setopt_fail'] = \CURLMOPT_PIPELINING;

        $warning = null;
        \set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            if ($severity !== \E_USER_WARNING) {
                return false;
            }

            $warning = $message;

            return true;
        }, \E_USER_WARNING);

        try {
            Server::flush();
            Server::enqueue([new Response()]);
            $a = new CurlMultiHandler(['options' => [\CURLMOPT_PIPELINING => 0]]);
            $response = $a(new Request('GET', Server::$url), [])->wait();

            self::assertSame(200, $response->getStatusCode());
        } finally {
            \restore_error_handler();
            unset($_SERVER['curl_multi_setopt_fail']);
        }

        self::assertNotNull($warning, 'Expected a warning for the rejected raw cURL multi option.');
        self::assertStringContainsString('CURLMOPT_PIPELINING', $warning);
    }

    public static function multiplexNoneCustomFactoryVersionProvider(): iterable
    {
        yield 'http 1.1' => ['1.1'];
        yield 'http 2.0' => ['2.0'];
    }

    /**
     * @dataProvider multiplexNoneCustomFactoryVersionProvider
     */
    public function testRejectsMultiplexNoneWithCustomHandleFactoryOnEnabledHandler(string $version)
    {
        if ('2.0' === $version && (!CurlVersion::supportsHttp2() || !CurlVersion::supportsMultiplex())) {
            self::markTestSkipped('HTTP/2 or multiplex support is unavailable.');
        }

        $events = [];
        $a = new CurlMultiHandler(['handle_factory' => self::recordingHandleFactory($events)]);

        try {
            $a(new Request('GET', Server::$url, [], null, $version), ['multiplex' => Multiplexing::NONE]);
            self::fail('Expected the custom handle factory conflict to be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('The "multiplex" request option can only be Multiplexing::NONE on a CurlMultiHandler with a custom "handle_factory" when the handler\'s own "multiplex" option is Multiplexing::NONE, because the guarantee is enforced against the native easy handle the factory controls.', $e->getMessage());
        }

        self::assertSame(['release'], $events, 'The rejected easy handle must be released.');
    }

    public function testAllowsMultiplexNoneWithCustomHandleFactoryOnMultiplexNoneHandler()
    {
        // Acceptance logic is handler-owned: the multi-level
        // CURLMOPT_PIPELINING = 0 enforces the guarantee independently of the
        // easy handles the custom factory controls.
        $events = [];
        $a = new CurlMultiHandler([
            'multiplex' => Multiplexing::NONE,
            'handle_factory' => self::recordingHandleFactory($events),
        ]);

        Server::flush();
        Server::enqueue([new Response()]);
        $response = $a(new Request('GET', Server::$url), ['multiplex' => Multiplexing::NONE])->wait();
        self::assertSame(200, $response->getStatusCode());
    }

    public function testAllowsMultiplexNoneRequestOnMultiplexNoneHandler()
    {
        Server::flush();
        Server::enqueue([new Response()]);
        $a = new CurlMultiHandler(['multiplex' => Multiplexing::NONE]);
        $response = $a(new Request('GET', Server::$url, [], null, '1.1'), ['multiplex' => Multiplexing::NONE])->wait();

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayNotHasKey(\CURLOPT_FRESH_CONNECT, $_SERVER['_curl']);
    }

    public function testAllowsMultiplexNoneRequestForHttp2OnMultiplexNoneHandler()
    {
        if (!CurlVersion::supportsHttp2() || !CurlVersion::supportsMultiplex()) {
            self::markTestSkipped('HTTP/2 or multiplex support is unavailable.');
        }

        // The handler's own multi-level guarantee covers every version, so
        // no per-request hardening is applied.
        $a = new CurlMultiHandler(['multiplex' => Multiplexing::NONE]);
        $promise = $a(new Request('GET', Server::$url, [], null, '2.0'), ['multiplex' => Multiplexing::NONE]);
        $promise->cancel();

        self::assertInstanceOf(P\PromiseInterface::class, $promise);
        self::assertArrayNotHasKey(\CURLOPT_FRESH_CONNECT, $_SERVER['_curl']);
    }

    public static function multiplexNoneMatcherSafeHttp1Provider(): iterable
    {
        yield 'http 1.0' => ['1.0'];
        yield 'http 1.1' => ['1.1'];
    }

    /**
     * @dataProvider multiplexNoneMatcherSafeHttp1Provider
     */
    public function testAllowsMultiplexNoneForHttp1WithoutHardeningOnMatcherSafeRuntimes(string $version)
    {
        $previousVersionInfo = self::setCurlVersionInfo([
            'version' => '8.13.0',
            'features' => self::curlSslFeature(),
        ]);

        try {
            Server::flush();
            Server::enqueue([new Response()]);
            $a = new CurlMultiHandler();
            $response = $a(new Request('GET', Server::$url, [], null, $version), ['multiplex' => Multiplexing::NONE])->wait();

            self::assertSame(200, $response->getStatusCode());
            self::assertArrayNotHasKey(\CURLOPT_FRESH_CONNECT, $_SERVER['_curl']);
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
        }
    }

    public static function matcherVulnerableCurlVersionProvider(): iterable
    {
        yield 'below 7.77.0' => ['7.76.0'];
        yield '8.11.0 through 8.12.1 regression window' => ['8.12.1'];
    }

    /**
     * @dataProvider matcherVulnerableCurlVersionProvider
     */
    public function testHardensMultiplexNoneForHttp1OnMatcherVulnerableRuntimes(string $curlVersion)
    {
        $previousVersionInfo = self::setCurlVersionInfo([
            'version' => $curlVersion,
            'features' => self::curlSslFeature(),
        ]);

        try {
            Server::flush();
            Server::enqueue([new Response()]);
            $a = new CurlMultiHandler();
            $response = $a(new Request('GET', Server::$url, [], null, '1.1'), ['multiplex' => Multiplexing::NONE])->wait();

            self::assertSame(200, $response->getStatusCode());
            self::assertTrue($_SERVER['_curl'][\CURLOPT_FRESH_CONNECT]);
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
        }
    }

    public function testMultiplexNoneFailsClosedWhenFreshConnectCannotBeApplied()
    {
        $previousVersionInfo = self::setCurlVersionInfo([
            'version' => '7.76.0',
            'features' => self::curlSslFeature(),
        ]);
        $_SERVER['curl_setopt_fail'] = \CURLOPT_FRESH_CONNECT;

        try {
            $a = new CurlMultiHandler();

            try {
                $a(new Request('GET', Server::$url, [], null, '1.1'), ['multiplex' => Multiplexing::NONE]);
                self::fail('Expected the hardening failure to be rejected.');
            } catch (\InvalidArgumentException $e) {
                // The hardening is the guarantee on these runtimes, so
                // failing to apply it must fail closed.
                self::assertSame('Unable to set cURL option CURLOPT_FRESH_CONNECT.', $e->getMessage());
            }
        } finally {
            unset($_SERVER['curl_setopt_fail']);
            self::setCurlVersionInfo($previousVersionInfo);
        }

        // The rejected easy handle was released and the handler stays usable.
        Server::flush();
        Server::enqueue([new Response()]);
        $response = $a(new Request('GET', Server::$url), [])->wait();
        self::assertSame(200, $response->getStatusCode());
    }

    public static function multiplexableVersionProvider(): iterable
    {
        yield 'version 2' => ['2'];
        yield 'version 2.0' => ['2.0'];
    }

    /**
     * @dataProvider multiplexableVersionProvider
     */
    public function testRejectsMultiplexNoneForHttp2OnMultiplexingHandler(string $version)
    {
        if (!CurlVersion::supportsHttp2() || !CurlVersion::supportsMultiplex()) {
            self::markTestSkipped('HTTP/2 or multiplex support is unavailable.');
        }

        // The asynchronous half of the default stack's sync/async fork; the
        // synchronous half is CurlHandlerTest's HTTP/2 acceptance.
        $a = new CurlMultiHandler();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The "multiplex" request option can only be Multiplexing::NONE for an HTTP/1.x request on a CurlMultiHandler that permits multiplexing; set the "multiplex" client or CurlMultiHandler constructor option to Multiplexing::NONE to disable multiplexing for every transfer, or send the request with its "version" option set to "1.1".');
        $a(new Request('GET', Server::$url, [], null, $version), ['multiplex' => Multiplexing::NONE]);
    }

    public static function multiplexNoneRawPipeliningHandlerProvider(): iterable
    {
        yield 'agreeing zero, http 1.1' => [0, '1.1'];
        yield 'multiplex mask, http 1.1' => [2, '1.1'];
        yield 'non-scalar, http 1.1' => [[1], '1.1'];
        yield 'agreeing zero, http 2.0' => [0, '2.0'];
        yield 'multiplex mask, http 2.0' => [2, '2.0'];
        yield 'non-scalar, http 2.0' => [[1], '2.0'];
    }

    /**
     * @dataProvider multiplexNoneRawPipeliningHandlerProvider
     *
     * @param mixed $pipelining
     */
    public function testRejectsMultiplexNoneRequestWithRawPipeliningHandlerOption($pipelining, string $version)
    {
        if ('2.0' === $version && (!CurlVersion::supportsHttp2() || !CurlVersion::supportsMultiplex())) {
            self::markTestSkipped('HTTP/2 or multiplex support is unavailable.');
        }

        // Key presence alone conflicts, even with an agreeing or non-scalar
        // value: raw multi options that fail to apply only warn, so a
        // configured zero mask cannot prove the guarantee.
        $a = new CurlMultiHandler(['options' => [\CURLMOPT_PIPELINING => $pipelining]]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The "multiplex" request option cannot be Multiplexing::NONE alongside a raw CURLMOPT_PIPELINING cURL multi option; replace the raw option with the "multiplex" cURL multi handler option.');
        $a(new Request('GET', Server::$url, [], null, $version), ['multiplex' => Multiplexing::NONE]);
    }

    public static function multiplexNoneRawCurlOptionConflictProvider(): iterable
    {
        yield 'http version' => ['CURLOPT_HTTP_VERSION', 2];
        yield 'http auth' => ['CURLOPT_HTTPAUTH', 2];
        yield 'proxy auth' => ['CURLOPT_PROXYAUTH', 1];
        yield 'follow location' => ['CURLOPT_FOLLOWLOCATION', true];
        yield 'http header' => ['CURLOPT_HTTPHEADER', ['X-Foo: bar']];
        yield 'alt svc' => ['CURLOPT_ALTSVC', 'altsvc-cache.txt'];
        yield 'alt svc ctrl' => ['CURLOPT_ALTSVC_CTRL', 8];
        yield 'proxy type' => ['CURLOPT_PROXYTYPE', 3];
    }

    /**
     * @dataProvider multiplexNoneRawCurlOptionConflictProvider
     *
     * @param mixed $value
     */
    public function testRejectsMultiplexNoneWithConflictingRawCurlOptions(string $constant, $value)
    {
        if (!\defined($constant)) {
            self::markTestSkipped(\sprintf('%s is unavailable.', $constant));
        }

        $a = new CurlMultiHandler();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(\sprintf('The "multiplex" request option cannot be Multiplexing::NONE combined with the raw %s cURL option on a CurlMultiHandler that permits multiplexing; remove the raw option, or set the "multiplex" client or CurlMultiHandler constructor option to Multiplexing::NONE.', $constant));
        $a(new Request('GET', Server::$url, [], null, '1.1'), [
            'multiplex' => Multiplexing::NONE,
            'curl' => [(int) \constant($constant) => $value],
        ]);
    }

    public static function multiplexNoneExpectHeaderProvider(): iterable
    {
        yield 'lowercase' => ['100-continue'];
        yield 'canonical case' => ['100-Continue'];
        yield 'uppercase' => ['100-CONTINUE'];
        yield 'surrounding whitespace' => [" 100-continue\t"];
        yield 'composite value' => ['foo, 100-continue'];
    }

    /**
     * @dataProvider multiplexNoneExpectHeaderProvider
     */
    public function testRejectsMultiplexNoneWithExpectContinueHeader(string $headerValue)
    {
        $a = new CurlMultiHandler();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The "multiplex" request option cannot be Multiplexing::NONE for a request carrying an "Expect: 100-continue" header on a CurlMultiHandler that permits multiplexing; remove the explicitly supplied "Expect" header, set the "expect" request option to false to prevent it being added automatically, or set the "multiplex" client or CurlMultiHandler constructor option to Multiplexing::NONE.');
        $a(new Request('GET', Server::$url, ['Expect' => $headerValue], null, '1.1'), ['multiplex' => Multiplexing::NONE]);
    }

    public function testRejectsMultiplexNoneWithRawPipewait()
    {
        if (!\defined('CURLOPT_PIPEWAIT')) {
            self::markTestSkipped('CURLOPT_PIPEWAIT is unavailable.');
        }

        // Regression-pin that NONE takes the existing PIPEWAIT-conflict
        // branch: whatever its value, a raw CURLOPT_PIPEWAIT is a second
        // wait/eager authority.
        $a = new CurlMultiHandler();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The "multiplex" request option cannot be combined with the raw CURLOPT_PIPEWAIT cURL option on the cURL multi handler; remove the raw option.');
        $a(new Request('GET', Server::$url, [], null, '1.1'), [
            'multiplex' => Multiplexing::NONE,
            'curl' => [(int) \constant('CURLOPT_PIPEWAIT') => false],
        ]);
    }

    public function testRejectsLegacyProtocolVersionsBeforeMultiplexNoneAcceptance()
    {
        // The factory's up-front version rejection surfaces, not a NONE
        // rejection: acceptance runs after create().
        $a = new CurlMultiHandler();

        $this->expectException(ConnectException::class);
        $this->expectExceptionMessage('HTTP/0.9 is not supported by the cURL handler.');
        $a(new Request('GET', Server::$url, [], null, '0.9'), ['multiplex' => Multiplexing::NONE]);
    }

    public function testMultiplexNoneAllowsEagerRequests()
    {
        if (!CurlVersion::supportsMultiplex()) {
            self::markTestSkipped('Multiplex support is unavailable.');
        }

        Server::flush();
        Server::enqueue([new Response()]);
        $a = new CurlMultiHandler(['multiplex' => Multiplexing::NONE]);
        $response = $a(new Request('GET', Server::$url), ['multiplex' => Multiplexing::EAGER])->wait();
        self::assertSame(200, $response->getStatusCode());
    }

    public function testMultiplexNoneAllowsExplicitWaitForHttp11()
    {
        if (!CurlVersion::supportsMultiplex()) {
            self::markTestSkipped('Multiplex support is unavailable.');
        }

        // An HTTP/1.1 wait request never sets the PIPEWAIT marker, so nothing
        // would wait on the disabled handle anyway.
        Server::flush();
        Server::enqueue([new Response()]);
        $a = new CurlMultiHandler(['multiplex' => Multiplexing::NONE]);
        $response = $a(new Request('GET', Server::$url, [], null, '1.1'), ['multiplex' => Multiplexing::WAIT])->wait();
        self::assertSame(200, $response->getStatusCode());
    }

    public function testRejectsExplicitWaitOnMultiplexNoneHandler()
    {
        if (!CurlVersion::supportsHttp2() || !CurlVersion::supportsMultiplex()) {
            self::markTestSkipped('HTTP/2 or multiplex support is unavailable.');
        }

        $a = new CurlMultiHandler(['multiplex' => Multiplexing::NONE]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The "multiplex" request option cannot be combined with a CurlMultiHandler whose "multiplex" option is Multiplexing::NONE; remove the handler option or set the request option to "eager".');
        $a(new Request('GET', Server::$url, [], null, '2.0'), ['multiplex' => Multiplexing::WAIT]);
    }

    public static function requiredMultiplexOnNoneHandlerProvider(): iterable
    {
        yield 'require_eager' => [Multiplexing::REQUIRE_EAGER];
        yield 'require_wait' => [Multiplexing::REQUIRE_WAIT];
    }

    /**
     * @dataProvider requiredMultiplexOnNoneHandlerProvider
     */
    public function testRejectsRequiredMultiplexOnMultiplexNoneHandler(string $multiplex)
    {
        if (!\defined('CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE') || !\defined('CURLOPT_PIPEWAIT') || !\defined('CURL_VERSION_HTTP2')) {
            self::markTestSkipped('CURLOPT_PIPEWAIT or HTTP/2 cURL constants are unavailable.');
        }

        $previousVersionInfo = self::setCurlVersionInfo([
            'version' => '8.14.0',
            'features' => self::curlSslFeature() | \CURL_VERSION_HTTP2,
        ]);

        try {
            $a = new CurlMultiHandler(['multiplex' => Multiplexing::NONE]);

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('The "multiplex" request option cannot be combined with a CurlMultiHandler whose "multiplex" option is Multiplexing::NONE; remove the handler option or set the request option to "eager".');
            $a(new Request('GET', 'https://example.com', [], null, '2.0'), ['multiplex' => $multiplex]);
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
        }
    }

    public function testMultiplexNoneRejectionLeavesHandlerUsable()
    {
        if (!CurlVersion::supportsHttp2() || !CurlVersion::supportsMultiplex()) {
            self::markTestSkipped('HTTP/2 or multiplex support is unavailable.');
        }

        $a = new CurlMultiHandler(['multiplex' => Multiplexing::NONE]);

        try {
            $a(new Request('GET', Server::$url, [], null, '2.0'), ['multiplex' => Multiplexing::WAIT]);
            self::fail('Expected the multiplex handler conflict to be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Multiplexing::NONE', $e->getMessage());
        }

        Server::flush();
        Server::enqueue([new Response()]);
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

    public function testTransportSharingPassesShareStateToFactory(): void
    {
        self::skipIfCurlShareIsUnavailable();

        $handler = new CurlMultiHandler([
            'transport_sharing' => TransportSharing::HANDLER_PREFER,
        ]);

        $factory = \Closure::bind(static function (CurlMultiHandler $handler) {
            return $handler->factory;
        }, null, CurlMultiHandler::class)($handler);

        $opaque = \Closure::bind(static function (CurlFactory $factory): bool {
            return $factory->opaqueShareConnectionCache;
        }, null, CurlFactory::class)($factory);

        self::assertFalse($opaque);
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

    public function testAttachesCallbackCreatedRequestAfterExecUnwinds(): void
    {
        Server::flush();
        Server::enqueue([new Response(200), new Response(200)]);

        $handler = new CurlMultiHandler();
        $nested = null;
        $deferredDuringCallback = null;

        $response = $handler(new Request('GET', Server::$url), [
            'on_headers' => static function () use ($handler, &$nested, &$deferredDuringCallback): void {
                $nested = $handler(new Request('GET', Server::$url), []);
                $deferredDuringCallback = self::readMultiProperty($handler, 'deferredAdds');
            },
        ])->wait();

        self::assertSame(200, $response->getStatusCode());
        self::assertInstanceOf(P\PromiseInterface::class, $nested);
        self::assertCount(1, $deferredDuringCallback, 'The callback-created request must defer its native attachment.');

        self::assertSame(200, $nested->wait()->getStatusCode());
        self::assertSame([], self::readMultiProperty($handler, 'deferredAdds'));
        self::assertSame([], self::readMultiProperty($handler, 'handles'));
    }

    public function testFailedDeferredAttachmentRejectsCallbackCreatedRequest(): void
    {
        Server::flush();
        Server::enqueue([new Response(200)]);

        $handler = new CurlMultiHandler();
        $nested = null;

        $response = $handler(new Request('GET', Server::$url), [
            'on_headers' => static function () use ($handler, &$nested): void {
                $nested = $handler(new Request('GET', Server::$url), []);
                // Fail only the deferred attachment; the outer transfer is
                // already attached.
                $_SERVER['curl_multi_add_handle_result'] = \CURLM_INTERNAL_ERROR;
            },
        ])->wait();

        unset($_SERVER['curl_multi_add_handle_result']);

        self::assertSame(200, $response->getStatusCode());
        self::assertInstanceOf(P\PromiseInterface::class, $nested);
        self::assertTrue(P\Is::rejected($nested));
        self::assertSame([], self::readMultiProperty($handler, 'deferredAdds'));
        self::assertSame([], self::readMultiProperty($handler, 'handles'));

        try {
            $nested->wait();
            self::fail('Expected RequestException.');
        } catch (RequestException $e) {
            self::assertStringContainsString('Unable to add the cURL handle', $e->getMessage());
        }
    }

    public function testRequestCreatedDuringPrematureRemovalIsDeferred(): void
    {
        Server::flush();
        Server::enqueue([
            new Response(200, ['Content-Length' => '1048576'], \str_repeat('x', 1048576)),
            new Response(200),
        ]);

        $handler = new CurlMultiHandler(['select_timeout' => 0]);
        $nested = null;
        $deferredDuringRemoval = null;
        $depthDuringRemoval = null;
        $canceling = false;

        $promise = $handler(new Request('GET', Server::$url), [
            'timeout' => 5,
            'progress' => static function () use ($handler, &$nested, &$deferredDuringRemoval, &$depthDuringRemoval, &$canceling): void {
                if ($canceling && $nested === null) {
                    $depthDuringRemoval = self::readMultiProperty($handler, 'multiExecDepth');
                    $nested = $handler(new Request('GET', Server::$url), []);
                    $deferredDuringRemoval = self::readMultiProperty($handler, 'deferredAdds');
                }
            },
        ]);

        try {
            $deadline = \microtime(true) + 5;
            while (self::readMultiProperty($handler, 'active') === 0) {
                if (\microtime(true) >= $deadline) {
                    self::fail('Timed out waiting for the transfer to start.');
                }

                $handler->tick();
            }

            $canceling = true;
            $promise->cancel();
            $canceling = false;

            if ($nested === null) {
                self::markTestSkipped('libcurl did not run a final progress update on premature removal.');
            }

            self::assertSame(1, $depthDuringRemoval, 'Premature removal must run under the native operation guard.');
            self::assertCount(1, $deferredDuringRemoval, 'A request created during removal must defer its attachment.');
            self::assertSame(200, $nested->wait()->getStatusCode());
        } finally {
            Server::flush();
        }
    }

    public function testCancelChainedFromPrematureRemovalIsDrained(): void
    {
        Server::flush();
        Server::enqueue([
            new Response(200, ['Content-Length' => '1048576'], \str_repeat('x', 1048576)),
            new Response(200, ['Content-Length' => '1048576'], \str_repeat('x', 1048576)),
        ]);

        $handler = new CurlMultiHandler(['select_timeout' => 0]);
        $first = null;
        $second = null;
        $cancelStarted = false;
        $chained = false;

        $first = $handler(new Request('GET', Server::$url), [
            'timeout' => 5,
            'progress' => static function () use ($handler, &$first, &$second, &$cancelStarted, &$chained): void {
                if (!$cancelStarted) {
                    $cancelStarted = true;
                    $first->cancel();

                    return;
                }

                if (!$chained && self::readMultiProperty($handler, 'finishingDeferredWork')) {
                    // Final update while the deferred cancel flush removes
                    // this transfer: cancel the sibling, chaining a deferred
                    // cancel the flush snapshot cannot see.
                    $chained = true;
                    $second->cancel();
                }
            },
        ]);

        $second = $handler(new Request('GET', Server::$url), ['timeout' => 5]);

        try {
            $deadline = \microtime(true) + 5;
            while (!$cancelStarted) {
                if (\microtime(true) >= $deadline) {
                    self::fail('Timed out waiting for the transfer to start.');
                }

                $handler->tick();
            }

            if (!$chained) {
                self::markTestSkipped('libcurl did not run a final progress update on premature removal.');
            }

            self::assertSame([], self::readMultiProperty($handler, 'deferredCancels'), 'A cancel chained from a removal callback must be drained.');
            self::assertTrue(P\Is::rejected($second));
        } finally {
            Server::flush();
        }
    }

    public function testThrowingRemovalProgressCallbackStillCleansRemainingCancels(): void
    {
        Server::flush();
        Server::enqueue([
            new Response(200, ['Content-Length' => '1048576'], \str_repeat('x', 1048576)),
            new Response(200, ['Content-Length' => '1048576'], \str_repeat('x', 1048576)),
        ]);

        $handler = new CurlMultiHandler(['select_timeout' => 0]);
        $first = null;
        $second = null;
        $cancelStarted = false;
        $threw = false;
        $secondFinalized = false;

        $first = $handler(new Request('GET', Server::$url), [
            'timeout' => 5,
            'progress' => static function () use ($handler, &$first, &$second, &$cancelStarted, &$threw): void {
                if (!$cancelStarted) {
                    // Queue both transfers as deferred cancels; the flush
                    // must clean the second even though the first throws.
                    $cancelStarted = true;
                    $first->cancel();
                    $second->cancel();

                    return;
                }

                if (!$threw && self::readMultiProperty($handler, 'finishingDeferredWork')) {
                    $threw = true;

                    throw new \RuntimeException('Final progress failure.');
                }
            },
        ]);

        $second = $handler(new Request('GET', Server::$url), [
            'timeout' => 5,
            'progress' => static function () use ($handler, &$secondFinalized): void {
                if (self::readMultiProperty($handler, 'finishingDeferredWork')) {
                    $secondFinalized = true;
                }
            },
        ]);

        // Seed proxy tunnel bookkeeping for the throwing entry; only its own
        // finalization can clear it, as the sibling has a different id.
        $handles = self::readMultiProperty($handler, 'handles');
        $firstId = (int) \key($handles);
        self::setMultiProperty($handler, 'activeProxyTunnelSignatures', ['synthetic-signature' => 1]);
        self::setMultiProperty($handler, 'activeProxyTunnelHandles', [$firstId => 'synthetic-signature']);

        try {
            $caught = null;
            $deadline = \microtime(true) + 5;
            while (!$cancelStarted) {
                if (\microtime(true) >= $deadline) {
                    self::fail('Timed out waiting for the transfer to start.');
                }

                try {
                    $handler->tick();
                } catch (\RuntimeException $e) {
                    $caught = $e;
                }
            }

            if (!$threw) {
                self::markTestSkipped('libcurl did not run a final progress update on premature removal.');
            }

            self::assertNotNull($caught);
            self::assertSame('Final progress failure.', $caught->getMessage());
            self::assertTrue($secondFinalized, 'Entries after a throwing cleanup must still be cleaned.');
            self::assertSame([], self::readMultiProperty($handler, 'deferredCancels'));
            self::assertSame([], self::readMultiProperty($handler, 'activeProxyTunnelSignatures'), 'The throwing entry itself must still be finalized.');
            self::assertSame([], self::readMultiProperty($handler, 'activeProxyTunnelHandles'));
        } finally {
            Server::flush();
        }
    }

    public function testSettledDeferredAddDoesNotStrandSiblings(): void
    {
        Server::flush();
        Server::enqueue([new Response(200)]);

        $handler = new CurlMultiHandler();
        $first = null;
        $second = null;

        $response = $handler(new Request('GET', Server::$url), [
            'on_headers' => static function () use ($handler, &$first, &$second): void {
                $first = $handler(new Request('GET', Server::$url), []);
                $second = $handler(new Request('GET', Server::$url), []);
                // Settle the first promise directly, then fail every deferred
                // attachment; the settled promise must not abort the flush.
                $first->resolve(new Response(299));
                $_SERVER['curl_multi_add_handle_result'] = \CURLM_INTERNAL_ERROR;
            },
        ])->wait();

        unset($_SERVER['curl_multi_add_handle_result']);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue(P\Is::fulfilled($first));
        self::assertSame(299, $first->wait()->getStatusCode());
        self::assertTrue(P\Is::rejected($second));
        self::assertSame([], self::readMultiProperty($handler, 'deferredAdds'));
        self::assertSame([], self::readMultiProperty($handler, 'handles'));

        try {
            $second->wait();
            self::fail('Expected RequestException.');
        } catch (RequestException $e) {
            self::assertStringContainsString('Unable to add the cURL handle', $e->getMessage());
        }
    }

    public function testCompletionCallbackCanDriveAndAwaitNestedCallbackRequests(): void
    {
        Server::flush();
        Server::enqueue([new Response(200), new Response(200), new Response(200)]);

        $handler = new CurlMultiHandler(['select_timeout' => 0]);
        $inner = null;
        $nested = null;
        $failure = null;

        $outer = $handler(new Request('GET', Server::$url), [
            'on_stats' => static function () use ($handler, &$inner, &$nested, &$failure): void {
                try {
                    $inner = $handler(new Request('GET', Server::$url), [
                        'on_headers' => static function () use ($handler, &$nested): void {
                            $nested = $handler(new Request('GET', Server::$url), []);
                        },
                    ]);

                    $deadline = \microtime(true) + 5;
                    while (P\Is::pending($inner)) {
                        if (\microtime(true) >= $deadline) {
                            throw new \RuntimeException('Timed out driving the inner transfer.');
                        }

                        $handler->tick();
                    }

                    self::assertSame([], self::readMultiProperty($handler, 'deferredAdds'), 'The nested request must attach once native execution unwinds.');
                } catch (\Throwable $e) {
                    $failure = $e;
                }
            },
        ]);

        self::assertSame(200, $outer->wait()->getStatusCode());
        self::assertNull($failure);
        self::assertSame(200, $inner->wait()->getStatusCode());
        self::assertInstanceOf(P\PromiseInterface::class, $nested);
        self::assertSame(200, $nested->wait()->getStatusCode());
    }

    public function testCancelingCallbackCreatedRequestNeverAttachesIt(): void
    {
        Server::flush();
        Server::enqueue([new Response(200)]);

        $handler = new CurlMultiHandler();
        $nested = null;

        $response = $handler(new Request('GET', Server::$url), [
            'on_headers' => static function () use ($handler, &$nested): void {
                $nested = $handler(new Request('GET', Server::$url), []);
                $nested->cancel();
            },
        ])->wait();

        self::assertSame(200, $response->getStatusCode());
        self::assertInstanceOf(P\PromiseInterface::class, $nested);
        self::assertTrue(P\Is::rejected($nested));
        self::assertSame([], self::readMultiProperty($handler, 'deferredAdds'));
        self::assertSame([], self::readMultiProperty($handler, 'deferredCancels'));
        self::assertSame([], self::readMultiProperty($handler, 'handles'));
        self::assertSame([], self::readMultiProperty($handler, 'activeProxyTunnelHandles'));
    }

    public function testNestedSynchronousWaitFailsPromptly(): void
    {
        Server::flush();
        Server::enqueue([new Response(200)]);

        $handler = new CurlMultiHandler();
        $nestedFailure = null;

        $response = $handler(new Request('GET', Server::$url), [
            'on_headers' => static function () use ($handler, &$nestedFailure): void {
                try {
                    $handler(new Request('GET', Server::$url), [])->wait();
                } catch (\Throwable $e) {
                    $nestedFailure = $e;
                }
            },
        ])->wait();

        self::assertSame(200, $response->getStatusCode());
        self::assertInstanceOf(RequestException::class, $nestedFailure);
        self::assertStringContainsString('inside a cURL callback', $nestedFailure->getMessage());
        self::assertSame([], self::readMultiProperty($handler, 'deferredAdds'));
        self::assertSame([], self::readMultiProperty($handler, 'handles'));
    }

    public function testNestedSynchronousClientSendFailsWithRequestException(): void
    {
        Server::flush();
        Server::enqueue([new Response(200)]);

        $handler = new CurlMultiHandler();
        $client = new Client(['handler' => HandlerStack::create($handler)]);
        $nestedFailure = null;

        $response = $client->send(new Request('GET', Server::$url), [
            'on_headers' => static function () use ($client, &$nestedFailure): void {
                try {
                    $client->send(new Request('GET', Server::$url));
                } catch (\Throwable $e) {
                    $nestedFailure = $e;
                }
            },
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertInstanceOf(RequestException::class, $nestedFailure);
        self::assertStringContainsString('inside a cURL callback', $nestedFailure->getMessage());
    }

    public function testReentrantTickDoesNotExecuteNativeCurlRecursively(): void
    {
        Server::flush();
        Server::enqueue([new Response(200)]);

        $handler = new CurlMultiHandler();
        $depthDuringCallback = null;

        $response = $handler(new Request('GET', Server::$url), [
            'on_headers' => static function () use ($handler, &$depthDuringCallback): void {
                $handler->tick();
                $depthDuringCallback = self::readMultiProperty($handler, 'multiExecDepth');
            },
        ])->wait();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $depthDuringCallback, 'A reentrant tick must not clear the outer native execution guard.');
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

    public function testFailsClosedWhenConnectionCapCannotBeReappliedAfterProxyTunnelHandover(): void
    {
        self::skipIfConnectionCapCurlMultiOptionsUnavailable();

        $handler = new CurlMultiHandler(['max_host_connections' => 2]);
        self::setMultiProperty($handler, 'proxyTunnelOwner', 'sig-a');
        self::readMultiProperty($handler, '_mh');

        self::applyProxyTunnelOwnership($handler, self::easyWithSignature('sig-b'));
        $_SERVER['curl_multi_setopt_fail'] = \constant('CURLMOPT_MAX_HOST_CONNECTIONS');

        try {
            self::readMultiProperty($handler, '_mh');
            self::fail('Expected InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Unable to apply the cURL multi option CURLMOPT_MAX_HOST_CONNECTIONS', $e->getMessage());
            self::assertStringContainsString('rejected by the runtime libcurl', $e->getMessage());
        }

        self::assertFalse(self::multiHandleIsInitialized($handler), 'A failed recreation must not publish the multi handle.');

        // Clear the failure and the recorder so recovery is asserted on
        // fresh data; the shadow records before honoring the fail switch.
        unset($_SERVER['curl_multi_setopt_fail'], $_SERVER['_curl_multi']);

        self::readMultiProperty($handler, '_mh');

        self::assertTrue(self::multiHandleIsInitialized($handler));
        self::assertSame(2, $_SERVER['_curl_multi'][\constant('CURLMOPT_MAX_HOST_CONNECTIONS')]);
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

    public function testRejectsANonAsciiUriHostWithoutConnecting(): void
    {
        $handler = new CurlMultiHandler();
        $request = new Request('GET', "http://e\u{200B}vil.test:1/");

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('must contain only printable ASCII characters');

        $handler($request, ['timeout' => 0.001, 'connect_timeout' => 0.001]);
    }

    public function testRejectsANonAsciiHostHeaderWithoutConnecting(): void
    {
        $handler = new CurlMultiHandler();
        $request = (new Request('GET', 'http://example.com:1/'))->withHeader('Host', "e\u{200B}vil.test");

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('The request Host header');

        $handler($request, ['timeout' => 0.001, 'connect_timeout' => 0.001]);
    }

    public function testRejectsANoncanonicalUriHostWithACustomHandleFactory(): void
    {
        $factory = $this->createMock(CurlFactoryInterface::class);
        $factory->expects(self::never())->method('create');

        $handler = new CurlMultiHandler(['handle_factory' => $factory]);

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('must not contain a percent escape');

        $handler(new Request('GET', 'http://%65vil.test:1/'), []);
    }

    public function testRejectsANoncanonicalHostBeforeAnUnsupportedScheme(): void
    {
        $handler = new CurlMultiHandler();

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('must contain only printable ASCII characters');

        $handler(new Request('GET', "file://e\u{200B}vil.test/etc/passwd"), []);
    }

    public function testDoesNotTransferAPercentEncodedHost(): void
    {
        Server::flush();
        Server::enqueue([new Response(200)]);

        $handler = new CurlMultiHandler();
        $request = new Request('GET', 'http://127.0.0.%31:'.Server::$port.'/');

        try {
            $handler($request, [])->wait();
            self::fail('Must throw a RequestException.');
        } catch (RequestException $e) {
            self::assertStringContainsString('must not contain a percent escape', $e->getMessage());
        }

        self::assertSame([], Server::received());
    }

    /**
     * @dataProvider foldedTrailingDotHostProvider
     */
    public function testDoesNotTransferANumericHostWithARootDot(string $host): void
    {
        Server::flush();
        Server::enqueue([new Response(200)]);

        $handler = new CurlMultiHandler();
        $request = new Request('GET', 'http://'.$host.':'.Server::$port.'/');

        try {
            $handler($request, [])->wait();
            self::fail('Must throw a RequestException.');
        } catch (RequestException $e) {
            self::assertStringContainsString('must not be written as one to four decimal, octal or hexadecimal parts', $e->getMessage());
        }

        self::assertSame([], Server::received());
    }

    public static function foldedTrailingDotHostProvider(): iterable
    {
        yield 'loopback' => ['127.0.0.1.'];
        yield 'shortened decimal' => ['127.1.'];
        yield 'whole integer' => ['2130706433.'];
        yield 'hexadecimal' => ['0x7f000001.'];
        yield 'octal' => ['0177.0.0.1.'];
        yield 'zero padded octets' => ['127.000.000.001.'];
        yield 'zero padded final octet' => ['127.0.0.01.'];
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

    private static function multiHandleIsInitialized(CurlMultiHandler $handler): bool
    {
        // isset() does not trigger the lazy __get() initializer.
        $check = \Closure::bind(static function (CurlMultiHandler $handler): bool {
            return isset($handler->_mh);
        }, null, CurlMultiHandler::class);

        return $check($handler);
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
