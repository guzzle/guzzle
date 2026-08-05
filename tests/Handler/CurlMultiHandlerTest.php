<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests\Handler;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\HandlerClosedException;
use GuzzleHttp\Exception\InvalidArgumentException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\Handler\Clock;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Handler\CurlFactoryInterface;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Handler\CurlShareHandleState;
use GuzzleHttp\Handler\CurlVersion;
use GuzzleHttp\Handler\EasyHandle;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Multiplexing;
use GuzzleHttp\Promise as P;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\Server\Server;
use GuzzleHttp\Tests\UnvalidatedUri;
use GuzzleHttp\Tests\UnvalidatedUriRequest;
use GuzzleHttp\TransportSharing;
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
            $_SERVER['curl_multi_setopt_throw'],
            $_SERVER['curl_setopt_fail'],
            $_SERVER['curl_multi_add_handle_result']
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
            $_SERVER['curl_setopt_fail'],
            $_SERVER['curl_multi_add_handle_result'],
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
        $delays->setValue($handler, [1 => Clock::now() + 0.5]);

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
        $delays->setValue($handler, [1 => Clock::now() + 1.0e15]);

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

    public function testSynchronousWaitOnUntrackedTransferRejectsWithAttributableFailure(): void
    {
        Server::flush();

        $handler = new CurlMultiHandler(['select_timeout' => 2]);
        $request = new Request('GET', Server::$url);
        $promise = $handler($request, ['delay' => 2000]);

        $handles = self::readMultiProperty($handler, 'handles');
        self::assertCount(1, $handles);
        $id = (int) \key($handles);

        // Simulate the transfer leaving the handler without settling, which
        // stops the wait loop while the promise is still pending.
        self::setMultiProperty($handler, 'handles', []);
        self::setMultiProperty($handler, 'delays', []);

        try {
            $promise->wait();
            self::fail('Expected RequestException.');
        } catch (RequestException $e) {
            self::assertSame(\sprintf('Waiting on cURL multi handler transfer %d cannot make progress (its entry was removed without settling).', $id), $e->getMessage());
            self::assertSame($request, $e->getRequest());
            self::assertNotInstanceOf(ResponseException::class, $e);
        }

        $handler->close();
    }

    public function testSynchronousWaitOnReplacedTransferRejectsWithAttributableFailure(): void
    {
        Server::flush();

        $handler = new CurlMultiHandler(['select_timeout' => 2]);
        $request = new Request('GET', Server::$url);
        $promise = $handler($request, ['delay' => 2000]);

        $handles = self::readMultiProperty($handler, 'handles');
        self::assertCount(1, $handles);
        $id = (int) \key($handles);

        // Simulate the native handle ID having been reused by a replacement
        // request created after this promise's transfer left the handler.
        $handles[$id]['wait_token'] = new \stdClass();
        $handles[$id]['deferred'] = new P\Promise();
        self::setMultiProperty($handler, 'handles', $handles);

        try {
            $promise->wait();
            self::fail('Expected RequestException.');
        } catch (RequestException $e) {
            self::assertSame(\sprintf('Waiting on cURL multi handler transfer %d cannot make progress (its native cURL handle ID was reused by another request).', $id), $e->getMessage());
            self::assertSame($request, $e->getRequest());
        }

        // The replacement request must be left entirely alone.
        $handles = self::readMultiProperty($handler, 'handles');
        self::assertArrayHasKey($id, $handles);
        self::assertTrue(P\Is::pending($handles[$id]['deferred']));

        $handler->close();
    }

    public function testSynchronousWaitOnUntrackedTransferReportsTheResponseWhenOneArrived(): void
    {
        Server::flush();
        Server::enqueue([new Response(200)]);

        $handler = new CurlMultiHandler(['select_timeout' => 2]);
        $request = new Request('GET', Server::$url);
        $id = null;

        $promise = $handler($request, [
            'on_headers' => static function () use ($handler, &$id): void {
                // Drop the transfer once its response exists, so the wait
                // stops with a response in hand but nothing left to settle.
                $handles = self::readMultiProperty($handler, 'handles');
                $id = (int) \key($handles);
                self::setMultiProperty($handler, 'handles', []);
            },
        ]);

        try {
            $promise->wait();
            self::fail('Expected ResponseException.');
        } catch (ResponseException $e) {
            self::assertIsInt($id);
            self::assertSame(\sprintf('Waiting on cURL multi handler transfer %d cannot make progress (its entry was removed without settling).', $id), $e->getMessage());
            self::assertSame($request, $e->getRequest());
            self::assertSame(200, $e->getResponse()->getStatusCode());
        } finally {
            $handler->close();
            Server::flush();
        }
    }

    public function testSynchronousWaitReportsWhyItStoppedRatherThanLaterQueuedActivity(): void
    {
        Server::flush();

        $handler = new CurlMultiHandler(['select_timeout' => 2]);
        $request = new Request('GET', Server::$url);
        $promise = $handler($request, ['delay' => 2000]);

        $handles = self::readMultiProperty($handler, 'handles');
        self::assertCount(1, $handles);
        $id = (int) \key($handles);
        $entry = $handles[$id];

        // The transfer leaves the handler, and only afterwards does queued
        // work put a replacement under the same native cURL handle ID, so
        // the reported cause must not be the state left by that queue run.
        self::setMultiProperty($handler, 'handles', []);
        self::setMultiProperty($handler, 'delays', []);
        P\Utils::queue()->add(static function () use ($handler, $id, $entry): void {
            $entry['wait_token'] = new \stdClass();
            $entry['deferred'] = new P\Promise();
            self::setMultiProperty($handler, 'handles', [$id => $entry]);
        });

        try {
            $promise->wait();
            self::fail('Expected RequestException.');
        } catch (RequestException $e) {
            self::assertSame(\sprintf('Waiting on cURL multi handler transfer %d cannot make progress (its entry was removed without settling).', $id), $e->getMessage());
        }

        $handler->close();
    }

    public function testNestedSynchronousWaitOnUntrackedTransferRejectsWithAttributableFailure(): void
    {
        Server::flush();
        Server::enqueue([new Response(200)]);

        $handler = new CurlMultiHandler(['select_timeout' => 2]);
        $request = new Request('GET', Server::$url);
        $delayed = $handler($request, ['delay' => 2000]);
        $nestedFailure = null;
        $delayedId = null;

        try {
            $response = $handler(new Request('GET', Server::$url), [
                'on_headers' => static function () use ($handler, $delayed, &$nestedFailure, &$delayedId): void {
                    // Drop the delayed transfer while a cURL callback owns the
                    // multi handle, so the nested wait finds nothing to fail.
                    $handles = self::readMultiProperty($handler, 'handles');
                    foreach ($handles as $id => $entry) {
                        if ($entry['deferred'] === $delayed) {
                            $delayedId = $id;
                            unset($handles[$id]);
                        }
                    }
                    self::setMultiProperty($handler, 'handles', $handles);
                    self::setMultiProperty($handler, 'delays', []);

                    try {
                        $delayed->wait();
                    } catch (\Throwable $e) {
                        $nestedFailure = $e;
                    }
                },
            ])->wait();

            self::assertSame(200, $response->getStatusCode());
            self::assertIsInt($delayedId);
            self::assertInstanceOf(RequestException::class, $nestedFailure);
            self::assertSame(\sprintf('Waiting on cURL multi handler transfer %d cannot make progress (its entry was removed without settling).', $delayedId), $nestedFailure->getMessage());
            self::assertSame($request, $nestedFailure->getRequest());
        } finally {
            $handler->close();
            Server::flush();
        }
    }

    public function testNestedSynchronousWaitDoesNotReportATransferTheQueueStillSettles(): void
    {
        Server::flush();
        Server::enqueue([new Response(200)]);

        $handler = new CurlMultiHandler(['select_timeout' => 2]);
        $delayed = $handler(new Request('GET', Server::$url), ['delay' => 2000]);
        $settled = null;

        try {
            $handler(new Request('GET', Server::$url), [
                'on_headers' => static function () use ($handler, $delayed, &$settled): void {
                    // Drop the delayed transfer, then queue its settlement the
                    // way a completion task would, so only draining the queue
                    // can reveal that the wait did achieve something.
                    $handles = self::readMultiProperty($handler, 'handles');
                    foreach ($handles as $id => $entry) {
                        if ($entry['deferred'] === $delayed) {
                            unset($handles[$id]);
                        }
                    }
                    self::setMultiProperty($handler, 'handles', $handles);
                    self::setMultiProperty($handler, 'delays', []);

                    P\Utils::queue()->add(static function () use ($delayed): void {
                        $delayed->resolve(new Response(204));
                    });

                    $settled = $delayed->wait();
                },
            ])->wait();

            self::assertInstanceOf(Response::class, $settled);
            self::assertSame(204, $settled->getStatusCode());
        } finally {
            $handler->close();
            Server::flush();
        }
    }

    public function testSynchronousWaitInterruptedByCloseKeepsTheHandlerClosedRejection(): void
    {
        Server::flush();
        Server::enqueue([new Response(200)]);

        $handler = new CurlMultiHandler(['select_timeout' => 0]);
        $request = new Request('GET', Server::$url.'guzzle-server/read-timeout');
        $promise = $handler($request, ['timeout' => 10]);

        $handler(new Request('GET', Server::$url), [
            'timeout' => 10,
            'on_stats' => static function () use ($handler): void {
                $handler->close();
            },
        ]);

        try {
            $promise->wait();
            self::fail('Expected HandlerClosedException.');
        } catch (HandlerClosedException $e) {
            self::assertSame('The cURL multi handler was closed before the transfer completed.', $e->getMessage());
            self::assertSame($request, $e->getRequest());
        } finally {
            Server::flush();
        }
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

    /**
     * @dataProvider connectionCapOptionProvider
     */
    public function testFailsClosedWhenNamedConnectionCapCannotBeApplied(string $option, string $constant): void
    {
        self::skipIfConnectionCapCurlMultiOptionsUnavailable();

        $handler = new CurlMultiHandler([$option => 2]);
        $_SERVER['curl_multi_setopt_fail'] = \constant($constant);

        try {
            self::initMultiHandle($handler);
            self::fail('Expected InvalidArgumentException.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('Unable to apply the cURL multi option '.$constant, $e->getMessage());
            self::assertStringContainsString('rejected by the runtime libcurl', $e->getMessage());
        }

        self::assertFalse(self::hasMultiHandle($handler), 'A failed initialization must not publish the multi handle.');

        // Removing the failure allows the same handler to retry.
        unset($_SERVER['curl_multi_setopt_fail']);
        self::initMultiHandle($handler);
        self::assertTrue(self::hasMultiHandle($handler));
        self::assertSame(2, $_SERVER['_curl_multi'][\constant($constant)]);
    }

    public function testMultiplexNoneFailsClosedWhenPipeliningCannotBeApplied(): void
    {
        $handler = new CurlMultiHandler(['multiplex' => Multiplexing::NONE]);
        $_SERVER['curl_multi_setopt_fail'] = \CURLMOPT_PIPELINING;

        try {
            self::initMultiHandle($handler);
            self::fail('Expected InvalidArgumentException.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('Unable to apply the cURL multi option CURLMOPT_PIPELINING', $e->getMessage());
            self::assertStringContainsString('rejected by the runtime libcurl', $e->getMessage());
        }

        self::assertFalse(self::hasMultiHandle($handler), 'A failed initialization must not publish the multi handle.');

        // Removing the failure allows the same handler to retry.
        unset($_SERVER['curl_multi_setopt_fail']);
        self::initMultiHandle($handler);
        self::assertTrue(self::hasMultiHandle($handler));
        self::assertSame(0, $_SERVER['_curl_multi'][\CURLMOPT_PIPELINING]);
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

        self::setMultiProperty($handler, 'delays', [$id => Clock::now() - 1]);
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

    public static function rawPipeliningProvider(): iterable
    {
        yield 'disable' => [0];
        yield 'multiplex mask' => [2];
        yield 'non-scalar' => [[1]];
    }

    /**
     * @dataProvider rawPipeliningProvider
     *
     * @param mixed $pipelining
     */
    public function testRejectsRawPipeliningCurlMultiOption($pipelining): void
    {
        try {
            new CurlMultiHandler(['options' => [\CURLMOPT_PIPELINING => $pipelining]]);
            self::fail('Expected the raw CURLMOPT_PIPELINING option to be rejected.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('Passing CURLMOPT_PIPELINING', $e->getMessage());
            self::assertStringContainsString('Use Multiplexing::NONE via the "multiplex" cURL multi handler or client option to disable multiplexing, or remove the raw option for the runtime default (multiplexing defaults on from libcurl 7.62, except 7.65.0 and 7.65.1) instead.', $e->getMessage());
        }
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
    public function testRejectsInvalidHandlerMultiplexValues($value, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new CurlMultiHandler(['multiplex' => $value]);
    }

    public function testMultiplexNoneDisablesPipeliningOnTheMultiHandle(): void
    {
        Server::flush();
        Server::enqueue([new Response()]);
        $a = new CurlMultiHandler(['multiplex' => Multiplexing::NONE]);
        $response = $a(new Request('GET', Server::$url), [])->wait();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, $_SERVER['_curl_multi'][\CURLMOPT_PIPELINING]);
    }

    public function testMultiplexNoneAllowsDefaultWaitRequests(): void
    {
        if (!CurlVersion::supportsHttp2() || !CurlVersion::supportsMultiplex()) {
            self::markTestSkipped('HTTP/2 or multiplex support is unavailable.');
        }

        // The WAIT default adapts instead of conflicting: the handler option
        // wins, and libcurl ignores the written CURLOPT_PIPEWAIT when the
        // multi handle disallows multiplexing.
        Server::flush();
        Server::enqueue([new Response()]);
        $a = new CurlMultiHandler(['multiplex' => Multiplexing::NONE]);
        $response = $a(new Request('GET', Server::$url, [], null, '2.0'), [])->wait();

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($_SERVER['_curl'][(int) \constant('CURLOPT_PIPEWAIT')]);
        self::assertSame(0, $_SERVER['_curl_multi'][\CURLMOPT_PIPELINING]);
    }

    public function testRejectsExplicitWaitOnMultiplexNoneHandler(): void
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
    public function testRejectsRequiredMultiplexOnMultiplexNoneHandler(string $multiplex): void
    {
        if (!\defined('CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE') || !\defined('CURLOPT_PIPEWAIT') || !\defined('CURL_VERSION_HTTP2')) {
            self::markTestSkipped('CURLOPT_PIPEWAIT or HTTP/2 cURL constants are unavailable.');
        }

        $previousVersionInfo = self::setCurlVersionInfo([
            'version' => '8.14.0',
            'features' => self::curlSslFeature() | \CURL_VERSION_HTTP2,
        ]);

        try {
            // The required family conflicts marker-independently: a required
            // guarantee on a handler that disallows multiplexing is
            // contradictory even when the transfer would not wait.
            $a = new CurlMultiHandler(['multiplex' => Multiplexing::NONE]);

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('The "multiplex" request option cannot be combined with a CurlMultiHandler whose "multiplex" option is Multiplexing::NONE; remove the handler option or set the request option to "eager".');
            $a(new Request('GET', Server::$url, [], null, '2.0'), ['multiplex' => $multiplex]);
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
        }
    }

    public function testMultiplexNoneRejectionLeavesHandlerUsable(): void
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

    public static function multiplexNoneCustomFactoryVersionProvider(): iterable
    {
        yield 'http 1.1' => ['1.1'];
        yield 'http 2.0' => ['2.0'];
    }

    /**
     * @dataProvider multiplexNoneCustomFactoryVersionProvider
     */
    public function testRejectsMultiplexNoneWithCustomHandleFactoryOnEnabledHandler(string $version): void
    {
        if ('2.0' === $version && (!CurlVersion::supportsHttp2() || !CurlVersion::supportsMultiplex())) {
            self::markTestSkipped('HTTP/2 or multiplex support is unavailable.');
        }

        $events = [];
        $a = new CurlMultiHandler(['handle_factory' => self::recordingHandleFactory($events)]);

        try {
            $a(new Request('GET', Server::$url, [], null, $version), ['multiplex' => Multiplexing::NONE]);
            self::fail('Expected the custom handle factory conflict to be rejected.');
        } catch (InvalidArgumentException $e) {
            self::assertSame('The "multiplex" request option can only be Multiplexing::NONE on a CurlMultiHandler with a custom "handle_factory" when the handler\'s own "multiplex" option is Multiplexing::NONE, because the guarantee is enforced against the native easy handle the factory controls.', $e->getMessage());
        }

        self::assertSame(['release'], $events, 'The rejected easy handle must be released.');
    }

    public function testAllowsMultiplexNoneWithCustomHandleFactoryOnMultiplexNoneHandler(): void
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

    public function testMultiplexNoneAllowsEagerRequests(): void
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

    public function testMultiplexNoneAllowsExplicitWaitForHttp11(): void
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

    public function testAllowsMultiplexNoneRequestOnMultiplexNoneHandler(): void
    {
        Server::flush();
        Server::enqueue([new Response()]);
        $a = new CurlMultiHandler(['multiplex' => Multiplexing::NONE]);
        $response = $a(new Request('GET', Server::$url, [], null, '1.1'), ['multiplex' => Multiplexing::NONE])->wait();

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayNotHasKey(\CURLOPT_FRESH_CONNECT, $_SERVER['_curl']);
    }

    public function testAllowsMultiplexNoneRequestForHttp2OnMultiplexNoneHandler(): void
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
    public function testAllowsMultiplexNoneForHttp1WithoutHardeningOnMatcherSafeRuntimes(string $version): void
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
    public function testHardensMultiplexNoneForHttp1OnMatcherVulnerableRuntimes(string $curlVersion): void
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

    public function testMultiplexNoneFailsClosedWhenFreshConnectCannotBeApplied(): void
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
            } catch (InvalidArgumentException $e) {
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
    public function testRejectsMultiplexNoneForHttp2OnMultiplexingHandler(string $version): void
    {
        if (!CurlVersion::supportsHttp2() || !CurlVersion::supportsMultiplex()) {
            self::markTestSkipped('HTTP/2 or multiplex support is unavailable.');
        }

        // The asynchronous half of the default stack's sync/async fork; the
        // synchronous half is CurlHandlerTest's HTTP/2 acceptance.
        $a = new CurlMultiHandler();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The "multiplex" request option can only be Multiplexing::NONE for an HTTP/1.x request on a CurlMultiHandler that permits multiplexing; set the "multiplex" client or CurlMultiHandler constructor option to Multiplexing::NONE to disable multiplexing for every transfer, or send the request with its "version" option set to "1.1".');
        $a(new Request('GET', Server::$url, [], null, $version), ['multiplex' => Multiplexing::NONE]);
    }

    public function testRejectsMultiplexNoneForProxiedHttp3OnMultiplexingHandler(): void
    {
        self::requireHttp3TestConstants();

        $previousVersionInfo = self::setCurlVersionInfo([
            'version' => '8.14.0',
            'features' => (int) \constant('CURL_VERSION_HTTP3') | self::curlSslFeature(),
        ]);

        try {
            // Acceptance is decided from the request's declared protocol
            // version, before any transport-level downgrade: a non-required
            // HTTP/3 request through a proxy is delivered over HTTP/2 or
            // HTTP/1.1 on the wire, but is still rejected.
            $a = new CurlMultiHandler();

            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('The "multiplex" request option can only be Multiplexing::NONE for an HTTP/1.x request on a CurlMultiHandler that permits multiplexing; set the "multiplex" client or CurlMultiHandler constructor option to Multiplexing::NONE to disable multiplexing for every transfer, or send the request with its "version" option set to "1.1".');
            $a(new Request('GET', 'https://example.com', [], null, '3'), [
                'multiplex' => Multiplexing::NONE,
                'proxy' => 'http://127.0.0.1:8125',
            ]);
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
        }
    }

    public function testRejectsMultiplexNoneWithRawHttpAuth(): void
    {
        // Key presence alone conflicts: challenge-response retries are
        // libcurl-internal follows, which disarm CURLOPT_FRESH_CONNECT.
        $a = new CurlMultiHandler();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The "multiplex" request option cannot be Multiplexing::NONE combined with the raw CURLOPT_HTTPAUTH cURL option on a CurlMultiHandler that permits multiplexing; remove the raw option, or set the "multiplex" client or CurlMultiHandler constructor option to Multiplexing::NONE.');
        $a(new Request('GET', Server::$url, [], null, '1.1'), [
            'multiplex' => Multiplexing::NONE,
            'curl' => [\CURLOPT_HTTPAUTH => \CURLAUTH_DIGEST],
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
    public function testRejectsMultiplexNoneWithExpectContinueHeader(string $headerValue): void
    {
        $a = new CurlMultiHandler();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The "multiplex" request option cannot be Multiplexing::NONE for a request carrying an "Expect: 100-continue" header on a CurlMultiHandler that permits multiplexing; remove the explicitly supplied "Expect" header, set the "expect" request option to false to prevent it being added automatically, or set the "multiplex" client or CurlMultiHandler constructor option to Multiplexing::NONE.');
        $a(new Request('GET', Server::$url, ['Expect' => $headerValue], null, '1.1'), ['multiplex' => Multiplexing::NONE]);
    }

    public static function persistentShareMatcherPinProvider(): iterable
    {
        yield 'matcher-safe 8.13.0' => ['8.13.0'];
        yield 'matcher-vulnerable 8.12.1' => ['8.12.1'];
    }

    /**
     * @dataProvider persistentShareMatcherPinProvider
     */
    public function testRejectsMultiplexNoneWithRequiredPersistentSharing(string $curlVersion): void
    {
        self::skipIfPersistentCurlShareIsUnavailable();

        $previousVersionInfo = self::setCurlVersionInfo([
            'version' => $curlVersion,
            'features' => self::curlSslFeature(),
        ]);

        try {
            // Rejected on matcher-safe and matcher-vulnerable runtimes
            // alike: the rejection is deterministic, although the hardening
            // it protects only fires on vulnerable runtimes.
            $a = new CurlMultiHandler(['transport_sharing' => TransportSharing::PERSISTENT_REQUIRE]);

            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('The "multiplex" request option cannot be Multiplexing::NONE on a CurlMultiHandler that permits multiplexing and requires persistent transport sharing; set the "multiplex" client or CurlMultiHandler constructor option to Multiplexing::NONE, or use TransportSharing::PERSISTENT_PREFER.');
            $a(new Request('GET', Server::$url, [], null, '1.1'), ['multiplex' => Multiplexing::NONE]);
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
        }
    }

    /**
     * @dataProvider persistentShareMatcherPinProvider
     */
    public function testAllowsMultiplexNoneWithPreferredPersistentSharing(string $curlVersion): void
    {
        self::skipIfPersistentCurlShareIsUnavailable();

        $previousVersionInfo = self::setCurlVersionInfo([
            'version' => $curlVersion,
            'features' => self::curlSslFeature(),
        ]);

        try {
            // Fresh connections are legal under preference semantics, so
            // acceptance holds and the hardening applies only on the
            // matcher-vulnerable pin. This asserts the request outcome only.
            $a = new CurlMultiHandler(['transport_sharing' => TransportSharing::PERSISTENT_PREFER]);
            $promise = $a(new Request('GET', Server::$url, [], null, '1.1'), ['multiplex' => Multiplexing::NONE]);
            $promise->cancel();

            self::assertInstanceOf(P\PromiseInterface::class, $promise);
            if ('8.12.1' === $curlVersion) {
                self::assertTrue($_SERVER['_curl'][\CURLOPT_FRESH_CONNECT]);
            } else {
                self::assertArrayNotHasKey(\CURLOPT_FRESH_CONNECT, $_SERVER['_curl']);
            }
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
        }
    }

    public static function persistentSharingModeProvider(): iterable
    {
        yield 'persistent prefer' => [TransportSharing::PERSISTENT_PREFER];
        yield 'persistent require' => [TransportSharing::PERSISTENT_REQUIRE];
    }

    /**
     * @dataProvider persistentSharingModeProvider
     */
    public function testAllowsMultiplexNoneHandlerWithPersistentTransportSharing(string $mode): void
    {
        self::skipIfPersistentCurlShareIsUnavailable();

        if (!CurlVersion::supportsConnectionSharing()) {
            self::markTestSkipped('Persistent transport sharing is unavailable.');
        }

        // No sharing guard: idle connections may move between handlers
        // sequentially, and in-use connections are join-protected by
        // libcurl's same-multi rule.
        $a = new CurlMultiHandler([
            'multiplex' => Multiplexing::NONE,
            'transport_sharing' => $mode,
        ]);

        self::assertInstanceOf(CurlMultiHandler::class, $a);
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
            'delay' => 3600000,
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
            'delay' => 3600000,
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
        $expected = Clock::now() + (100 / 1000);
        $response = $a(new Request('GET', Server::$url), ['delay' => 100]);
        $response->wait();
        self::assertGreaterThanOrEqual($expected, Clock::now());
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

    public function testAttachesCallbackCreatedRequestAfterExecUnwinds(): void
    {
        Server::flush();
        Server::enqueue([new Response(200), new Response(200)]);

        $handler = new CurlMultiHandler();
        $nested = null;
        $deferredDuringCallback = null;

        try {
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
        } finally {
            $handler->close();
        }
    }

    public function testFailedDeferredAttachmentRejectsCallbackCreatedRequest(): void
    {
        Server::flush();
        Server::enqueue([new Response(200)]);

        $handler = new CurlMultiHandler();
        $nested = null;

        try {
            $response = $handler(new Request('GET', Server::$url), [
                'on_headers' => static function () use ($handler, &$nested): void {
                    $nested = $handler(new Request('GET', Server::$url), []);
                    // Fail only the deferred attachment; the outer transfer
                    // is already attached.
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
        } finally {
            $handler->close();
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
            $handler->close();
            Server::flush();
        }
    }

    public function testCloseFromPrematureRemovalCallbackDefersClose(): void
    {
        Server::flush();
        Server::enqueue([
            new Response(200, ['Content-Length' => '1048576'], \str_repeat('x', 1048576)),
        ]);

        $handler = new CurlMultiHandler(['select_timeout' => 0]);
        $closedDuringRemoval = false;
        $canceling = false;

        $promise = $handler(new Request('GET', Server::$url), [
            'timeout' => 5,
            'progress' => static function () use ($handler, &$closedDuringRemoval, &$canceling): void {
                if ($canceling && !$closedDuringRemoval) {
                    $closedDuringRemoval = true;
                    $handler->close();
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

            if (!$closedDuringRemoval) {
                self::markTestSkipped('libcurl did not run a final progress update on premature removal.');
            }

            self::assertTrue(P\Is::rejected($promise));
            self::assertTrue(self::readMultiProperty($handler, 'closed'), 'A close deferred from the removal callback must complete once the removal unwinds.');
            self::assertFalse(self::hasMultiHandle($handler));
            self::assertSame([], self::readMultiProperty($handler, 'handles'));
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
            $handler->close();
            Server::flush();
        }
    }

    public function testCloseChainedFromPrematureRemovalDisposesRemainingTransfer(): void
    {
        Server::flush();
        Server::enqueue([
            new Response(200, ['Content-Length' => '1048576'], \str_repeat('x', 1048576)),
            new Response(200, ['Content-Length' => '1048576'], \str_repeat('x', 1048576)),
        ]);

        $handler = new CurlMultiHandler(['select_timeout' => 0]);
        $first = null;
        $cancelStarted = false;
        $closedDuringRemoval = false;
        $secondRemovedDuringFlush = false;

        $first = $handler(new Request('GET', Server::$url), [
            'timeout' => 5,
            'progress' => static function () use ($handler, &$first, &$cancelStarted, &$closedDuringRemoval): void {
                if (!$cancelStarted) {
                    $cancelStarted = true;
                    $first->cancel();

                    return;
                }

                if (!$closedDuringRemoval && self::readMultiProperty($handler, 'finishingDeferredWork')) {
                    // Final update while the deferred cancel flush removes
                    // this transfer: defer a close, which moves the sibling
                    // into the deferred cancels mid-flush.
                    $closedDuringRemoval = true;
                    $handler->close();
                }
            },
        ]);

        $second = $handler(new Request('GET', Server::$url), [
            'timeout' => 5,
            'progress' => static function () use ($handler, &$secondRemovedDuringFlush): void {
                if (self::readMultiProperty($handler, 'finishingDeferredWork')) {
                    $secondRemovedDuringFlush = true;
                }
            },
        ]);

        try {
            $deadline = \microtime(true) + 5;
            while (!$cancelStarted) {
                if (\microtime(true) >= $deadline) {
                    self::fail('Timed out waiting for the transfer to start.');
                }

                $handler->tick();
            }

            if (!$closedDuringRemoval) {
                self::markTestSkipped('libcurl did not run a final progress update on premature removal.');
            }

            self::assertTrue($secondRemovedDuringFlush, 'The remaining transfer must be removed and disposed before the multi handle closes.');
            self::assertTrue(self::readMultiProperty($handler, 'closed'));
            self::assertFalse(self::hasMultiHandle($handler));
            self::assertTrue(P\Is::rejected($second));
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

        try {
            $response = $handler(new Request('GET', Server::$url), [
                'on_headers' => static function () use ($handler, &$first, &$second): void {
                    $first = $handler(new Request('GET', Server::$url), []);
                    $second = $handler(new Request('GET', Server::$url), []);
                    // Settle the first promise directly, then fail every
                    // deferred attachment; the settled promise must not abort
                    // the flush.
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
        } finally {
            $handler->close();
        }
    }

    public function testNestedWaitOnRespondedTransferRejectsWithResponseException(): void
    {
        Server::flush();
        Server::enqueue([new Response(200)]);

        $handler = new CurlMultiHandler();
        $nestedFailure = null;

        try {
            $delayed = $handler(new Request('GET', Server::$url), ['delay' => 3600000]);

            // Simulate the delayed transfer having already received response
            // headers by the time a callback waits on it.
            $handles = self::readMultiProperty($handler, 'handles');
            $delayedId = \array_key_first($handles);
            $handles[$delayedId]['easy']->response = new Response(203);

            // Synchronous, so the wait drives executeUntil() and never
            // sleeps out the delayed sibling's timer like execute() would.
            $response = $handler(new Request('GET', Server::$url), [
                RequestOptions::SYNCHRONOUS => true,
                'on_headers' => static function () use ($delayed, &$nestedFailure): void {
                    try {
                        $delayed->wait();
                    } catch (\Throwable $e) {
                        $nestedFailure = $e;
                    }
                },
            ])->wait();

            self::assertSame(200, $response->getStatusCode());
            self::assertInstanceOf(ResponseException::class, $nestedFailure);
            self::assertSame(203, $nestedFailure->getResponse()->getStatusCode());
            self::assertStringContainsString('inside a cURL callback', $nestedFailure->getMessage());
        } finally {
            $handler->close();
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

        try {
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
        } finally {
            $handler->close();
        }
    }

    public function testCloseFromNativeCallbackRejectsUnattachedNestedRequest(): void
    {
        Server::flush();
        Server::enqueue([new Response(200)]);

        $handler = new CurlMultiHandler();
        $nested = null;

        $outer = $handler(new Request('GET', Server::$url), [
            'on_headers' => static function () use ($handler, &$nested): void {
                $nested = $handler(new Request('GET', Server::$url), []);
                $handler->close();
            },
        ]);

        try {
            $outer->wait();
            self::fail('Expected HandlerClosedException.');
        } catch (HandlerClosedException $e) {
            self::assertStringContainsString('closed before the transfer completed', $e->getMessage());
        }

        self::assertInstanceOf(P\PromiseInterface::class, $nested);
        self::assertTrue(P\Is::rejected($nested));
        self::assertSame([], self::readMultiProperty($handler, 'deferredAdds'));
        self::assertSame([], self::readMultiProperty($handler, 'handles'));
        self::assertFalse(self::hasMultiHandle($handler));

        try {
            $nested->wait();
            self::fail('Expected HandlerClosedException.');
        } catch (HandlerClosedException $e) {
            self::assertStringContainsString('closed before the transfer completed', $e->getMessage());
        }
    }

    public function testCancelingCallbackCreatedRequestNeverAttachesIt(): void
    {
        Server::flush();
        Server::enqueue([new Response(200)]);

        $handler = new CurlMultiHandler();
        $nested = null;

        try {
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
        } finally {
            $handler->close();
        }
    }

    public function testNestedSynchronousWaitFailsPromptly(): void
    {
        Server::flush();
        Server::enqueue([new Response(200)]);

        $handler = new CurlMultiHandler();
        $nestedFailure = null;

        try {
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
        } finally {
            $handler->close();
        }
    }

    public function testNestedSynchronousClientSendFailsWithRequestException(): void
    {
        Server::flush();
        Server::enqueue([new Response(200)]);

        $handler = new CurlMultiHandler();
        $client = new Client(['handler' => HandlerStack::create($handler)]);
        $nestedFailure = null;

        try {
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
        } finally {
            $handler->close();
        }
    }

    public function testReentrantTickDoesNotExecuteNativeCurlRecursively(): void
    {
        Server::flush();
        Server::enqueue([new Response(200)]);

        $handler = new CurlMultiHandler();
        $depthDuringCallback = null;

        try {
            $response = $handler(new Request('GET', Server::$url), [
                'on_headers' => static function () use ($handler, &$depthDuringCallback): void {
                    $handler->tick();
                    $depthDuringCallback = self::readMultiProperty($handler, 'multiExecDepth');
                },
            ])->wait();

            self::assertSame(200, $response->getStatusCode());
            self::assertSame(1, $depthDuringCallback, 'A reentrant tick must not clear the outer native execution guard.');
        } finally {
            $handler->close();
        }
    }

    public function testFailedAttachmentRollsBackImmediateRequest(): void
    {
        $handler = new CurlMultiHandler();
        $_SERVER['curl_multi_add_handle_result'] = \CURLM_INTERNAL_ERROR;

        try {
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
        } finally {
            $handler->close();
        }
    }

    public function testFailedAttachmentRejectsEscapedDelayedRequest(): void
    {
        $handler = new CurlMultiHandler();

        try {
            $promise = $handler(new Request('GET', Server::$url), ['delay' => 1]);

            $handles = self::readMultiProperty($handler, 'handles');
            self::assertCount(1, $handles);
            $id = \array_key_first($handles);

            $_SERVER['curl_multi_add_handle_result'] = \CURLM_INTERNAL_ERROR;
            self::setMultiProperty($handler, 'delays', [$id => Clock::now() - 1]);

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
        } finally {
            $handler->close();
        }
    }

    public function testValidSiblingSurvivesAnotherRequestsFailedAttachment(): void
    {
        Server::flush();
        Server::enqueue([new Response(200)]);

        $handler = new CurlMultiHandler();

        try {
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
        } finally {
            $handler->close();
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

    public function testConnectionCapsAreReappliedAfterIdleProxyTunnelOwnerHandover(): void
    {
        self::skipIfConnectionCapCurlMultiOptionsUnavailable();

        $handler = new CurlMultiHandler([
            'max_host_connections' => 2,
            'max_total_connections' => 5,
        ]);
        self::setMultiProperty($handler, 'proxyTunnelOwner', 'sig-a');
        self::initMultiHandle($handler);
        self::assertSame(2, $_SERVER['_curl_multi'][\constant('CURLMOPT_MAX_HOST_CONNECTIONS')]);

        unset($_SERVER['_curl_multi']);
        self::applyProxyTunnelOwnership($handler, self::easyWithSignature('sig-b'));
        self::assertNull(self::readMultiHandle($handler), 'An idle owner change must release the multi handle for lazy recreation.');

        self::initMultiHandle($handler);

        self::assertSame(2, $_SERVER['_curl_multi'][\constant('CURLMOPT_MAX_HOST_CONNECTIONS')], 'The handover-recreated multi handle must re-apply the connection caps.');
        self::assertSame(5, $_SERVER['_curl_multi'][\constant('CURLMOPT_MAX_TOTAL_CONNECTIONS')], 'The handover-recreated multi handle must re-apply the connection caps.');
    }

    public function testFailsClosedWhenConnectionCapCannotBeReappliedAfterProxyTunnelHandover(): void
    {
        self::skipIfConnectionCapCurlMultiOptionsUnavailable();

        $handler = new CurlMultiHandler(['max_host_connections' => 2]);
        self::setMultiProperty($handler, 'proxyTunnelOwner', 'sig-a');
        self::initMultiHandle($handler);

        self::applyProxyTunnelOwnership($handler, self::easyWithSignature('sig-b'));
        $_SERVER['curl_multi_setopt_fail'] = \constant('CURLMOPT_MAX_HOST_CONNECTIONS');

        try {
            self::initMultiHandle($handler);
            self::fail('Expected InvalidArgumentException.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('Unable to apply the cURL multi option CURLMOPT_MAX_HOST_CONNECTIONS', $e->getMessage());
            self::assertStringContainsString('rejected by the runtime libcurl', $e->getMessage());
        }

        self::assertFalse(self::hasMultiHandle($handler), 'A failed recreation must not publish the multi handle.');
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
        if (!CurlVersion::supportsProxyTunneling()) {
            self::markTestSkipped('Requires proxy CONNECT tunnel support.');
        }

        $events = [];
        $handler = new CurlMultiHandler(['handle_factory' => self::recordingHandleFactory($events)]);
        self::setMultiProperty($handler, 'proxyTunnelOwner', 'sig-a');
        self::initMultiHandle($handler);
        $mh = self::readMultiHandle($handler);
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
        self::assertSame($mh, self::readMultiHandle($handler), 'The multi handle must not be recreated.');
    }

    public function testAttachTimeIsolationFailureRollsBackThePendingRequest(): void
    {
        if (!CurlVersion::supportsProxyTunneling()) {
            self::markTestSkipped('Requires proxy CONNECT tunnel support.');
        }

        $events = [];
        $handler = new CurlMultiHandler(['handle_factory' => self::recordingHandleFactory($events)]);
        self::initMultiHandle($handler);
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

        self::assertSame([], $events, 'The rolled-back easy handle is disposed directly, never released to the factory pool.');
        self::assertSame([], self::readMultiProperty($handler, 'handles'), 'The failed request must be rolled back out of the pending map.');
        self::assertSame([], self::readMultiProperty($handler, 'delays'));
        self::assertSame([], self::readMultiProperty($handler, 'deferredAdds'));
        self::assertSame(['sig-b' => 1], self::readMultiProperty($handler, 'activeProxyTunnelSignatures'), 'The foreign attachment bookkeeping must be unchanged.');
        self::assertSame([7 => 'sig-b'], self::readMultiProperty($handler, 'activeProxyTunnelHandles'));
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

    private static function skipIfPersistentCurlShareIsUnavailable(): void
    {
        if (
            !\function_exists('curl_share_init_persistent')
            || !\class_exists('CurlSharePersistentHandle')
            || !\defined('CURL_LOCK_DATA_DNS')
            || !\defined('CURL_LOCK_DATA_CONNECT')
            || !\defined('CURL_LOCK_DATA_SSL_SESSION')
        ) {
            self::markTestSkipped('Persistent cURL share handles are unavailable.');
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

    private static function requireHttp3TestConstants(): void
    {
        foreach (['CURL_VERSION_HTTP3', 'CURL_HTTP_VERSION_3', 'CURL_HTTP_VERSION_3ONLY'] as $constant) {
            if (!\defined($constant)) {
                self::markTestSkipped($constant.' is not available.');
            }
        }
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

    public function testDoesNotTransferANoncanonicalUriHost(): void
    {
        Server::flush();
        Server::enqueue([new Response(200)]);
        $handler = new CurlMultiHandler();

        try {
            $handler(new Request('GET', 'http://127.0.0.%31:'.Server::$port.'/'), [])->wait();
            self::fail('An exception was not thrown');
        } catch (RequestException $e) {
            self::assertStringContainsString('must not contain a percent escape', $e->getMessage());
        }

        self::assertSame([], Server::received());
    }

    public function testDoesNotTransferANonPrintableAsciiUriHost(): void
    {
        Server::flush();
        Server::enqueue([new Response(200)]);
        $handler = new CurlMultiHandler();
        $host = "\u{FF11}\u{FF12}\u{FF17}\u{3002}\u{FF10}\u{3002}\u{FF10}\u{3002}\u{FF11}";

        try {
            $handler(new Request('GET', 'http://'.$host.':'.Server::$port.'/'), [])->wait();
            self::fail('An exception was not thrown');
        } catch (RequestException $e) {
            self::assertStringContainsString('must contain only printable ASCII characters', $e->getMessage());
        }

        self::assertSame([], Server::received());
    }

    /**
     * @dataProvider foldedTrailingRootDotHostProvider
     */
    public function testDoesNotTransferANumericIpv4UriHostWithATrailingRootDot(string $host): void
    {
        Server::flush();
        Server::enqueue([new Response(200)]);
        $handler = new CurlMultiHandler();

        try {
            $handler(new Request('GET', 'http://'.$host.':'.Server::$port.'/'), [])->wait();
            self::fail('An exception was not thrown');
        } catch (RequestException $e) {
            self::assertStringContainsString('must not be written as one to four decimal, octal or hexadecimal parts', $e->getMessage());
        }

        self::assertSame([], Server::received());
    }

    public static function foldedTrailingRootDotHostProvider(): iterable
    {
        yield 'loopback' => ['127.0.0.1.'];
        yield 'shortened' => ['127.1.'];
        yield 'integer' => ['2130706433.'];
        yield 'hexadecimal' => ['0x7f000001.'];
        yield 'octal' => ['0177.0.0.1.'];
        yield 'zero padded' => ['127.000.000.001.'];
        yield 'zero padded octet' => ['127.0.0.01.'];
    }

    public function testRejectsANoncanonicalHostHeaderWithoutConnecting(): void
    {
        Server::flush();
        Server::enqueue([new Response(200)]);
        $handler = new CurlMultiHandler();
        $request = (new Request('GET', Server::$url))->withHeader('Host', "e\u{200B}vil.test");

        try {
            $handler($request, [])->wait();
            self::fail('An exception was not thrown');
        } catch (RequestException $e) {
            self::assertStringContainsString('The request Host header', $e->getMessage());
        }

        self::assertSame([], Server::received());
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

    public function testRejectsAForeignUriHostWithAnAuthorityDelimiterWithoutConnecting(): void
    {
        Server::flush();
        Server::enqueue([new Response(200)]);
        $handler = new CurlMultiHandler();
        $request = new UnvalidatedUriRequest(
            new Request('GET', Server::$url),
            new UnvalidatedUri('http', 'blocked.example.com@127.0.0.1', Server::$port)
        );

        try {
            $handler($request, [])->wait();
            self::fail('An exception was not thrown');
        } catch (RequestException $e) {
            self::assertStringContainsString('must be a valid RFC 3986 host', $e->getMessage());
        }

        self::assertSame([], Server::received());
    }

    public function testRejectsANoncanonicalHostBeforeAnUnsupportedScheme(): void
    {
        $handler = new CurlMultiHandler();

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('must contain only printable ASCII characters');

        $handler(new Request('GET', "file://e\u{200B}vil.test/x"), []);
    }

    public function testStillTransfersANoncanonicalNumericHost(): void
    {
        self::skipIfCurlDoesNotFoldNumericHosts();

        Server::flush();
        Server::enqueue([new Response(200)]);
        $handler = new CurlMultiHandler();

        $response = $handler(new Request('GET', 'http://127.1:'.Server::$port.'/'), [])->wait();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('127.1:'.Server::$port, Server::received()[0]->getHeaderLine('Host'));
    }

    public function testReRegisteringATrackedHandleIdSettlesTheDisplacedTransfer(): void
    {
        Server::flush();

        $handler = new CurlMultiHandler(['select_timeout' => 2]);
        $request = new Request('GET', Server::$url);
        $promise = $handler($request, ['delay' => 2000]);

        $handles = self::readMultiProperty($handler, 'handles');
        self::assertCount(1, $handles);
        $id = (int) \key($handles);

        // A replacement request whose easy handle reuses the same native ID.
        $replacement = new EasyHandle();
        $replacement->handle = $handles[$id]['easy']->handle;
        $replacement->request = new Request('GET', Server::$url);
        $replacement->options = ['delay' => 2000];
        $entry = [
            'easy' => $replacement,
            'deferred' => new P\Promise(),
            'wait_token' => new \stdClass(),
        ];

        $add = \Closure::bind(static function (CurlMultiHandler $handler, array $entry): void {
            $handler->addRequest($entry);
        }, null, CurlMultiHandler::class);
        $add($handler, $entry);

        try {
            $promise->wait();
            self::fail('Expected the displaced transfer to reject.');
        } catch (RequestException $e) {
            self::assertSame(\sprintf('cURL multi handler transfer %d was displaced by another request that reused its native cURL handle ID.', $id), $e->getMessage());
            self::assertSame($request, $e->getRequest());
        }

        $handles = self::readMultiProperty($handler, 'handles');
        self::assertSame($entry['wait_token'], $handles[$id]['wait_token']);
    }

    /**
     * Older libcurl delegates numeric shorthand to the platform resolver,
     * which rejects it on Windows. Validation is covered separately.
     */
    private static function skipIfCurlDoesNotFoldNumericHosts(): void
    {
        $version = \curl_version();

        if (!\is_array($version) || $version['version_number'] < 0x074D00) {
            self::markTestSkipped('libcurl does not fold numeric IPv4 hosts before 7.77.0.');
        }
    }
}
