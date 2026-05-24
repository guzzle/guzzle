<?php

declare(strict_types=1);

namespace GuzzleHttp\Test\Handler;

use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Stream;
use GuzzleHttp\TransferStats;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * @covers \GuzzleHttp\Handler\MockHandler
 */
class MockHandlerTest extends TestCase
{
    public function testReturnsMockResponse(): void
    {
        $res = new Response();
        $mock = new MockHandler([$res]);
        $request = new Request('GET', 'http://example.com');
        $p = $mock($request, []);
        self::assertSame($res, $p->wait());
    }

    public function testIsCountable(): void
    {
        $res = new Response();
        $mock = new MockHandler([$res, $res]);
        self::assertCount(2, $mock);
    }

    public function testEmptyHandlerIsCountable(): void
    {
        self::assertCount(0, new MockHandler());
    }

    public function testEnsuresEachAppendOnCreationIsValid(): void
    {
        $this->expectException(\TypeError::class);
        new MockHandler(['a']);
    }

    public function testEnsuresEachAppendIsValid(): void
    {
        $mock = new MockHandler();
        $this->expectException(\TypeError::class);
        $mock->append(['a']);
    }

    public function testCanQueueExceptions(): void
    {
        $e = new \Exception('a');
        $mock = new MockHandler([$e]);
        $request = new Request('GET', 'http://example.com');
        $p = $mock($request, []);
        try {
            $p->wait();
            self::fail();
        } catch (\Exception $e2) {
            self::assertSame($e, $e2);
        }
    }

    public function testCanGetLastRequestAndOptions(): void
    {
        $res = new Response();
        $mock = new MockHandler([$res]);
        $request = new Request('GET', 'http://example.com');
        $mock($request, ['foo' => 'bar']);
        self::assertSame($request, $mock->getLastRequest());
        self::assertSame(['foo' => 'bar'], $mock->getLastOptions());
    }

    public function testSinkFilename(): void
    {
        $filename = \sys_get_temp_dir().'/mock_test_'.\uniqid();

        try {
            $res = new Response(200, [], 'TEST CONTENT');
            $mock = new MockHandler([$res]);
            $request = new Request('GET', '/');
            $p = $mock($request, ['sink' => $filename]);
            $p->wait();

            self::assertFileExists($filename);
            self::assertStringEqualsFile($filename, 'TEST CONTENT');
        } finally {
            if (\file_exists($filename)) {
                \unlink($filename);
            }
        }
    }

    public function testSinkResource(): void
    {
        $file = \tmpfile();
        $meta = \stream_get_meta_data($file);
        $res = new Response(200, [], 'TEST CONTENT');
        $mock = new MockHandler([$res]);
        $request = new Request('GET', '/');
        $p = $mock($request, ['sink' => $file]);
        $p->wait();

        self::assertFileExists($meta['uri']);
        self::assertStringEqualsFile($meta['uri'], 'TEST CONTENT');
    }

    public function testSinkStream(): void
    {
        $stream = new Stream(\tmpfile());
        $res = new Response(200, [], 'TEST CONTENT');
        $mock = new MockHandler([$res]);
        $request = new Request('GET', '/');
        $p = $mock($request, ['sink' => $stream]);
        $p->wait();

        self::assertFileExists($stream->getMetadata('uri'));
        self::assertStringEqualsFile($stream->getMetadata('uri'), 'TEST CONTENT');
    }

    public function testCanEnqueueCallables(): void
    {
        $r = new Response();
        $fn = static function (RequestInterface $req, array $o) use ($r): ResponseInterface {
            return $r;
        };
        $mock = new MockHandler([$fn]);
        $request = new Request('GET', 'http://example.com');
        $p = $mock($request, ['foo' => 'bar']);
        self::assertSame($r, $p->wait());
    }

    public function testQueuedCallableCanReturnPromise(): void
    {
        $response = new Response(201);
        $mock = new MockHandler([
            static function () use ($response) {
                return Create::promiseFor($response);
            },
        ]);

        $promise = $mock(new Request('GET', 'http://example.com'), []);

        self::assertSame($response, $promise->wait());
    }

    public function testQueuedCallableCanReturnThrowable(): void
    {
        $reason = new \RuntimeException('failed');
        $mock = new MockHandler([
            static function () use ($reason): \Throwable {
                return $reason;
            },
        ]);

        $promise = $mock(new Request('GET', 'http://example.com'), []);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('failed');

        $promise->wait();
    }

    public function testEnsuresOnHeadersIsCallable(): void
    {
        $res = new Response();
        $mock = new MockHandler([$res]);
        $request = new Request('GET', 'http://example.com');

        $this->expectException(\InvalidArgumentException::class);
        $mock($request, ['on_headers' => 'error!']);
    }

    public function testRejectsPromiseWhenOnHeadersFails(): void
    {
        $res = new Response();
        $mock = new MockHandler([$res]);
        $request = new Request('GET', 'http://example.com');
        $promise = $mock($request, [
            'on_headers' => static function (): void {
                throw new \Exception('test');
            },
        ]);

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('An error was encountered during the on_headers event');
        $promise->wait();
    }

    public function testRejectsPromiseWhenOnHeadersThrowsThrowable(): void
    {
        $res = new Response();
        $mock = new MockHandler([$res]);
        $request = new Request('GET', 'http://example.com');
        $promise = $mock($request, [
            'on_headers' => static function (): void {
                throw new \Error('test');
            },
        ]);

        try {
            $promise->wait();
            self::fail('Expected RequestException');
        } catch (RequestException $e) {
            self::assertSame('An error was encountered during the on_headers event', $e->getMessage());
            self::assertInstanceOf(\Error::class, $e->getPrevious());
        }
    }

    public function testInvokesOnStatsWhenOnHeadersFails(): void
    {
        $res = new Response(200);
        $mock = new MockHandler([$res]);
        $request = new Request('GET', 'http://example.com');
        $stats = null;
        $promise = $mock($request, [
            'on_headers' => static function (): void {
                throw new \RuntimeException('test');
            },
            'on_stats' => static function (TransferStats $transferStats) use (&$stats): void {
                $stats = $transferStats;
            },
        ]);

        try {
            $promise->wait();
            self::fail('Expected RequestException');
        } catch (RequestException $e) {
            self::assertSame('An error was encountered during the on_headers event', $e->getMessage());
            self::assertInstanceOf(TransferStats::class, $stats);
            self::assertSame($request, $stats->getRequest());
            self::assertTrue($stats->hasResponse());
            self::assertSame($res, $stats->getResponse());
            self::assertSame($e, $stats->getHandlerErrorData());
        }
    }

    public function testInvokesOnHeadersWithResponseAndRequest(): void
    {
        $res = new Response(201, ['X-Foo' => 'bar']);
        $mock = new MockHandler([$res]);
        $request = new Request('GET', 'http://example.com');
        $gotResponse = null;
        $gotRequest = null;

        $promise = $mock($request, [
            'on_headers' => static function (
                ResponseInterface $response,
                RequestInterface $receivedRequest
            ) use (&$gotResponse, &$gotRequest): void {
                $gotResponse = $response;
                $gotRequest = $receivedRequest;
                self::assertSame('bar', $response->getHeaderLine('X-Foo'));
            },
        ]);

        self::assertSame($res, $promise->wait());
        self::assertSame($res, $gotResponse);
        self::assertSame($request, $gotRequest);
    }

    public function testInvokesOnHeadersWithQueuedPromiseResponse(): void
    {
        $res = new Response(202, ['X-Foo' => 'bar']);
        $mock = new MockHandler([Create::promiseFor($res)]);
        $request = new Request('GET', 'http://example.com');
        $gotResponse = null;

        $promise = $mock($request, [
            'on_headers' => static function (ResponseInterface $response) use (&$gotResponse): void {
                $gotResponse = $response;
            },
        ]);

        self::assertSame($res, $promise->wait());
        self::assertSame($res, $gotResponse);
    }

    public function testInvokesOnHeadersAfterQueuedCallableReturnsResponse(): void
    {
        $res = new Response(203, ['X-Foo' => 'bar']);
        $mock = new MockHandler([
            static function () use ($res): ResponseInterface {
                return $res;
            },
        ]);
        $request = new Request('GET', 'http://example.com');
        $gotResponse = null;

        $promise = $mock($request, [
            'on_headers' => static function (ResponseInterface $response) use (&$gotResponse): void {
                $gotResponse = $response;
            },
        ]);

        self::assertSame($res, $promise->wait());
        self::assertSame($res, $gotResponse);
    }

    public function testInvokesOnHeadersAfterQueuedCallableReturnsPromise(): void
    {
        $res = new Response(204, ['X-Foo' => 'bar']);
        $mock = new MockHandler([
            static function () use ($res): PromiseInterface {
                return Create::promiseFor($res);
            },
        ]);
        $request = new Request('GET', 'http://example.com');
        $gotResponse = null;

        $promise = $mock($request, [
            'on_headers' => static function (ResponseInterface $response) use (&$gotResponse): void {
                $gotResponse = $response;
            },
        ]);

        self::assertSame($res, $promise->wait());
        self::assertSame($res, $gotResponse);
    }

    public function testDoesNotInvokeOnHeadersForQueuedThrowable(): void
    {
        $reason = new \RuntimeException('failed');
        $mock = new MockHandler([$reason]);
        $called = false;

        $promise = $mock(new Request('GET', 'http://example.com'), [
            'on_headers' => static function () use (&$called): void {
                $called = true;
            },
        ]);

        try {
            $promise->wait();
            self::fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            self::assertSame($reason, $e);
            self::assertFalse($called);
        }
    }

    public function testInvokesOnStatsWhenPromiseOnHeadersFails(): void
    {
        $res = new Response(200);
        $mock = new MockHandler([Create::promiseFor($res)]);
        $request = new Request('GET', 'http://example.com');
        $stats = null;
        $promise = $mock($request, [
            'on_headers' => static function (): void {
                throw new \RuntimeException('test');
            },
            'on_stats' => static function (TransferStats $transferStats) use (&$stats): void {
                $stats = $transferStats;
            },
        ]);

        try {
            $promise->wait();
            self::fail('Expected RequestException');
        } catch (RequestException $e) {
            self::assertSame('An error was encountered during the on_headers event', $e->getMessage());
            self::assertSame($res, $e->getResponse());
            self::assertInstanceOf(TransferStats::class, $stats);
            self::assertSame($request, $stats->getRequest());
            self::assertTrue($stats->hasResponse());
            self::assertSame($res, $stats->getResponse());
            self::assertSame($e, $stats->getHandlerErrorData());
        }
    }

    public function testInvokesOnFulfilled(): void
    {
        $res = new Response();
        $mock = new MockHandler([$res], static function (ResponseInterface $v) use (&$c): void {
            $c = $v;
        });
        $request = new Request('GET', 'http://example.com');
        $mock($request, [])->wait();
        self::assertSame($res, $c);
    }

    public function testInvokesOnRejected(): void
    {
        $e = new \Exception('a');
        $c = null;
        $mock = new MockHandler([$e], null, static function (\Exception $v) use (&$c): void {
            $c = $v;
        });
        $request = new Request('GET', 'http://example.com');
        $mock($request, [])->wait(false);
        self::assertSame($e, $c);
    }

    public function testLateRejectedHandlerReceivesRejectedReason(): void
    {
        $e = new \Exception('a');
        $mock = new MockHandler([$e]);
        $request = new Request('GET', 'http://example.com');

        $promise = $mock($request, []);
        $promise->wait(false);

        $reason = null;
        $promise->then(null, static function (\Exception $value) use (&$reason): void {
            $reason = $value;
        });

        \GuzzleHttp\Promise\Utils::queue()->run();

        self::assertSame($e, $reason);
    }

    public function testThrowsWhenNoMoreResponses(): void
    {
        $mock = new MockHandler();
        $request = new Request('GET', 'http://example.com');

        $this->expectException(\OutOfBoundsException::class);
        $mock($request, []);
    }

    public function testCanCreateWithDefaultMiddleware(): void
    {
        $r = new Response(500);
        $mock = MockHandler::createWithMiddleware([$r]);
        $request = new Request('GET', 'http://example.com');

        $this->expectException(BadResponseException::class);
        $mock($request, ['http_errors' => true])->wait();
    }

    public function testInvokesOnStatsFunctionForResponse(): void
    {
        $res = new Response();
        $mock = new MockHandler([$res]);
        $request = new Request('GET', 'http://example.com');
        /** @var TransferStats|null $stats */
        $stats = null;
        $onStats = static function (TransferStats $s) use (&$stats): void {
            $stats = $s;
        };
        $p = $mock($request, ['on_stats' => $onStats]);
        $p->wait();
        self::assertSame($res, $stats->getResponse());
        self::assertSame($request, $stats->getRequest());
    }

    public function testInvokesOnStatsFunctionForError(): void
    {
        $e = new \Exception('a');
        $c = null;
        $mock = new MockHandler([$e], null, static function (\Exception $v) use (&$c): void {
            $c = $v;
        });
        $request = new Request('GET', 'http://example.com');

        /** @var TransferStats|null $stats */
        $stats = null;
        $onStats = static function (TransferStats $s) use (&$stats): void {
            $stats = $s;
        };
        $mock($request, ['on_stats' => $onStats])->wait(false);
        self::assertSame($e, $stats->getHandlerErrorData());
        self::assertNull($stats->getResponse());
        self::assertSame($request, $stats->getRequest());
    }

    public function testTransferTime(): void
    {
        $e = new \Exception('a');
        $c = null;
        $mock = new MockHandler([$e], null, static function (\Exception $v) use (&$c): void {
            $c = $v;
        });
        $request = new Request('GET', 'http://example.com');
        $stats = null;
        $onStats = static function (TransferStats $s) use (&$stats): void {
            $stats = $s;
        };
        $mock($request, ['on_stats' => $onStats, 'transfer_time' => 0.4])->wait(false);
        self::assertEquals(0.4, $stats->getTransferTime());
    }

    public function testResetQueue(): void
    {
        $mock = new MockHandler([new Response(200), new Response(204)]);
        self::assertCount(2, $mock);

        $mock->reset();
        self::assertEmpty($mock);

        $mock->append(new Response(500));
        self::assertCount(1, $mock);
    }
}
