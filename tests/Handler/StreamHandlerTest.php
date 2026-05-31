<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests\Handler;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\Exception\ResponseTimeoutException;
use GuzzleHttp\Exception\ResponseTransferException;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\Handler\TransferByteCounter;
use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\Server\Server;
use GuzzleHttp\TransferStats;
use GuzzleHttp\TransportSharing;
use GuzzleHttp\Utils;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * @covers \GuzzleHttp\Handler\StreamHandler
 */
class StreamHandlerTest extends TestCase
{
    private function queueRes(): void
    {
        Server::flush();
        Server::enqueue([
            new Response(200, [
                'Foo' => 'Bar',
                'Content-Length' => '8',
            ], 'hi there'),
        ]);
    }

    public function testReturnsResponseForSuccessfulRequest(): void
    {
        $this->queueRes();
        $handler = new StreamHandler();
        $response = $handler(
            new Request('GET', Server::$url, ['Foo' => 'Bar']),
            []
        )->wait();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('OK', $response->getReasonPhrase());
        self::assertSame('Bar', $response->getHeaderLine('Foo'));
        self::assertSame('8', $response->getHeaderLine('Content-Length'));
        self::assertSame('hi there', (string) $response->getBody());
        $sent = Server::received()[0];
        self::assertSame('GET', $sent->getMethod());
        self::assertSame('127.0.0.1', $sent->getUri()->getHost());
        self::assertSame(8126, $sent->getUri()->getPort());
        self::assertSame('/', $sent->getUri()->getPath());
        self::assertSame('127.0.0.1:8126', $sent->getHeaderLine('Host'));
        self::assertSame('Bar', $sent->getHeaderLine('foo'));
    }

    public function testRejectsEmptyProtocolVersion(): void
    {
        $handler = new StreamHandler();
        $request = self::requestWithProtocolVersion('');

        try {
            $handler($request, []);
            self::fail('Expected request exception.');
        } catch (RequestException $e) {
            self::assertSame($request, $e->getRequest());
            self::assertNotInstanceOf(ResponseException::class, $e);
            self::assertSame('HTTP protocol version must not be empty.', $e->getMessage());
        }
    }

    public function testRejectsMalformedProtocolVersion(): void
    {
        $handler = new StreamHandler();
        $request = self::requestWithProtocolVersion('HTTP/1.1');

        try {
            $handler($request, []);
            self::fail('Expected request exception.');
        } catch (RequestException $e) {
            self::assertSame($request, $e->getRequest());
            self::assertNotInstanceOf(ResponseException::class, $e);
            self::assertSame('HTTP protocol version must be a valid HTTP version number.', $e->getMessage());
        }
    }

    /**
     * @dataProvider invalidRequestContentLengthProvider
     *
     * @param string|string[] $contentLength
     */
    public function testRejectsInvalidRequestContentLength($contentLength): void
    {
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url, [
            'Content-Length' => $contentLength,
        ]);

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('Invalid Content-Length request header');

        $handler($request, []);
    }

    public static function invalidRequestContentLengthProvider(): iterable
    {
        return [
            'empty' => [''],
            'empty comma member' => ['3,'],
            'non digit' => ['abc'],
            'partial numeric' => ['3abc'],
            'signed' => ['-1'],
            'decimal' => ['3.0'],
            'conflicting comma' => ['3, 5'],
            'conflicting duplicate' => [['3', '5']],
        ];
    }

    public function testPrepareRequestFailureDoesNotInvokeOnStats(): void
    {
        $handler = new StreamHandler();
        $called = false;
        $request = new Request('GET', Server::$url, [
            'Content-Length' => 'abc',
        ]);

        try {
            $handler($request, [
                'on_stats' => static function () use (&$called): void {
                    $called = true;
                },
            ]);

            self::fail('Expected RequestException');
        } catch (RequestException $e) {
            self::assertSame($request, $e->getRequest());
            self::assertSame(
                'Invalid Content-Length request header: value is not a non-negative decimal integer',
                $e->getMessage()
            );
        }

        self::assertFalse($called);
    }

    public function testRequestBodyGetSizeTimeoutRejectsAsRequestExceptionWithoutStats(): void
    {
        $handler = new StreamHandler();
        $called = false;
        $previous = new Psr7\Exception\TimeoutException('Unable to determine stream size: timed out');
        $body = FnStream::decorate(Psr7\Utils::streamFor('data'), [
            'getSize' => static function () use ($previous): ?int {
                throw $previous;
            },
        ]);
        $request = new Request('PUT', Server::$url, [], $body);

        try {
            $handler($request, [
                'on_stats' => static function () use (&$called): void {
                    $called = true;
                },
            ]);

            self::fail('Expected RequestException');
        } catch (RequestException $e) {
            self::assertSame($request, $e->getRequest());
            self::assertSame('Timed out while determining the request body size', $e->getMessage());
            self::assertSame($previous, $e->getPrevious());
            self::assertInstanceOf(RequestExceptionInterface::class, $e);
            self::assertNotInstanceOf(ResponseException::class, $e);
            self::assertNotInstanceOf(NetworkExceptionInterface::class, $e);
        }

        self::assertFalse($called);
    }

    public function testRequestBodyGetSizeFailureUsesFallbackMessageWhenMessageEmpty(): void
    {
        $handler = new StreamHandler();
        $previous = new \RuntimeException('');
        $body = FnStream::decorate(Psr7\Utils::streamFor('data'), [
            'getSize' => static function () use ($previous): ?int {
                throw $previous;
            },
        ]);
        $request = new Request('PUT', Server::$url, [], $body);

        try {
            $handler($request, []);

            self::fail('Expected RequestException');
        } catch (RequestException $e) {
            self::assertSame($request, $e->getRequest());
            self::assertSame('Failed to determine the request body size', $e->getMessage());
            self::assertSame($previous, $e->getPrevious());
        }
    }

    public function testNormalizesEquivalentRequestContentLengthValues(): void
    {
        $request = new Request('GET', Server::$url, [
            'Content-Length' => ['0000', '0'],
        ]);
        $reflection = new \ReflectionMethod(StreamHandler::class, 'prepareRequest');
        if (\PHP_VERSION_ID < 80100) {
            $reflection->setAccessible(true);
        }
        $prepared = $reflection->invoke(null, $request);

        self::assertInstanceOf(RequestInterface::class, $prepared);
        self::assertSame(['0'], $prepared->getHeader('Content-Length'));
    }

    public function testRejectsUnrepresentableRequestContentLength(): void
    {
        $length = ((string) \PHP_INT_MAX).'0';
        $request = new Request('GET', Server::$url, [
            'Content-Length' => $length,
        ]);
        $reflection = new \ReflectionMethod(StreamHandler::class, 'prepareRequest');
        if (\PHP_VERSION_ID < 80100) {
            $reflection->setAccessible(true);
        }

        try {
            $reflection->invoke(null, $request);
            self::fail('Expected RequestException');
        } catch (RequestException $e) {
            self::assertSame('Content-Length exceeds the maximum integer size supported on this platform', $e->getMessage());
            self::assertSame($request, $e->getRequest());
            self::assertInstanceOf(\OverflowException::class, $e->getPrevious());
        }
    }

    public function testRejectsInvalidRequestContentLengthBeforeAddingEmptyBodyDefault(): void
    {
        $handler = new StreamHandler();
        $request = new Request('PUT', Server::$url, [
            'Content-Length' => 'abc',
        ]);

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('Invalid Content-Length request header');

        $handler($request, []);
    }

    public function testAddsErrorToResponse(): void
    {
        $handler = new StreamHandler();

        $this->expectException(ConnectException::class);
        $handler(
            new Request('GET', 'http://localhost:123'),
            ['timeout' => 0.01]
        )->wait();
    }

    public function testClassifiesStreamTimeoutErrors(): void
    {
        self::assertTrue($this->matchesStreamHandlerError('isConnectTimeoutError', 'fopen(): SSL: Handshake timed out'));
        self::assertTrue($this->matchesStreamHandlerError('isConnectTimeoutError', 'fopen(): Failed to open stream: Connection timed out'));
        self::assertTrue($this->matchesStreamHandlerError('isConnectTimeoutError', 'fopen(): Failed to open stream: Operation timed out'));
        self::assertTrue($this->matchesStreamHandlerError('isConnectTimeoutError', 'stream_socket_client(): Unable to connect to example.test:443 (Operation timed out)'));
        // Windows WSAETIMEDOUT (errno 10060) wording matches for both connect-phase
        // and post-connect send timeouts; the send-error matcher is checked first.
        self::assertTrue($this->matchesStreamHandlerError('isConnectTimeoutError', 'A connection attempt failed because the connected party did not properly respond after a period of time, or established connection failed because connected host has failed to respond'));
        self::assertTrue($this->matchesStreamHandlerError('isConnectTimeoutError', 'Send of 65536 bytes failed with errno=10060 A connection attempt failed because the connected party did not properly respond after a period of time, or established connection failed because connected host has failed to respond'));
        self::assertFalse($this->matchesStreamHandlerError('isConnectTimeoutError', 'HTTP request failed!'));
        self::assertFalse($this->matchesStreamHandlerError('isConnectionError', 'fopen(): SSL: Handshake timed out'));
    }

    public function testClassifiesStreamConnectionErrors(): void
    {
        self::assertTrue($this->matchesStreamHandlerError('isConnectionError', 'php_network_getaddresses: getaddrinfo for example.test failed'));
        self::assertTrue($this->matchesStreamHandlerError('isConnectionError', 'Unable to connect to example.test:80'));
        self::assertTrue($this->matchesStreamHandlerError('isConnectionError', 'fopen(): Failed to open stream: Connection refused'));
        self::assertTrue($this->matchesStreamHandlerError('isConnectionError', 'fopen(): Failed to open stream: No connection could be made because the target machine actively refused it'));
        self::assertTrue($this->matchesStreamHandlerError('isConnectionError', 'Cannot connect to HTTPS server through proxy'));
        self::assertTrue($this->matchesStreamHandlerError('isConnectionError', 'Failed to enable crypto'));
        self::assertTrue($this->matchesStreamHandlerError('isConnectionError', 'fopen(): Failed to open stream: Network is unreachable'));
        self::assertTrue($this->matchesStreamHandlerError('isConnectionError', 'fopen(): Failed to open stream: No route to host'));
        self::assertTrue($this->matchesStreamHandlerError('isConnectionError', 'fopen(): Failed to open stream: Host is down'));
        self::assertTrue($this->matchesStreamHandlerError('isConnectionError', 'A connection attempt failed because the connected party did not properly respond after a period of time'));
        self::assertFalse($this->matchesStreamHandlerError('isConnectionError', 'HTTP request failed!'));
    }

    public function testClassifiesStreamSendErrors(): void
    {
        self::assertTrue($this->matchesStreamHandlerError('isSendError', 'Send of 65536 bytes failed with errno=110 Connection timed out'));
        self::assertTrue($this->matchesStreamHandlerError('isSendError', 'Send of 8192 bytes failed with errno=60 Operation timed out'));
        self::assertFalse($this->matchesStreamHandlerError('isSendError', 'fopen(): Failed to open stream: Connection timed out'));
        self::assertFalse($this->matchesStreamHandlerError('isSendError', 'HTTP request failed!'));
    }

    public function testClassifiesStreamNetworkErrors(): void
    {
        self::assertTrue($this->matchesStreamHandlerError('isNetworkError', 'SSL: Connection reset by peer'));
        self::assertTrue($this->matchesStreamHandlerError('isNetworkError', 'SSL: Broken pipe'));
        // OpenSSL 3.0+ reports a peer closing the connection without close_notify this way.
        self::assertTrue($this->matchesStreamHandlerError('isNetworkError', 'SSL operation failed with code 1. OpenSSL Error messages: error:0A000126:SSL routines::unexpected eof while reading'));
        // A bare connect-phase reset (no "SSL:" prefix) is not a network error.
        self::assertFalse($this->matchesStreamHandlerError('isNetworkError', 'fopen(): Failed to open stream: Connection reset by peer'));
        self::assertFalse($this->matchesStreamHandlerError('isNetworkError', 'fopen(): Failed to open stream: Connection refused'));
        self::assertFalse($this->matchesStreamHandlerError('isNetworkError', 'HTTP request failed!'));
    }

    public function testRejectsRequestExceptionWhenRequestBodyReadTimesOut(): void
    {
        $handler = new StreamHandler();
        $previous = new Psr7\Exception\TimeoutException('Unable to read stream contents: timed out');
        $body = FnStream::decorate(Psr7\Utils::streamFor('data'), [
            '__toString' => static function () use ($previous): string {
                throw $previous;
            },
        ]);
        $request = new Request('PUT', Server::$url, [], $body);
        $stats = null;
        $exception = null;
        $exceptionRequest = null;

        try {
            $handler($request, [
                'on_stats' => static function (TransferStats $transferStats) use (&$stats): void {
                    $stats = $transferStats;
                },
            ])->wait();

            self::fail('Expected RequestException');
        } catch (RequestException $e) {
            $exception = $e;
            $exceptionRequest = $e->getRequest();
            self::assertSame($request->getMethod(), $exceptionRequest->getMethod());
            self::assertSame((string) $request->getUri(), (string) $exceptionRequest->getUri());
            self::assertSame('Timed out while reading the request body', $e->getMessage());
            self::assertSame($previous, $e->getPrevious());
            self::assertInstanceOf(RequestExceptionInterface::class, $e);
            self::assertNotInstanceOf(NetworkExceptionInterface::class, $e);
            self::assertNotInstanceOf(ResponseException::class, $e);
        }

        self::assertInstanceOf(TransferStats::class, $stats);
        self::assertFalse($stats->hasResponse());
        self::assertSame($exceptionRequest->getMethod(), $stats->getRequest()->getMethod());
        self::assertSame((string) $exceptionRequest->getUri(), (string) $stats->getRequest()->getUri());
        self::assertSame($exception, $stats->getHandlerErrorData());
    }

    public function testRequestBodyReadFailureUsesFallbackMessageWhenMessageEmpty(): void
    {
        $handler = new StreamHandler();
        $previous = new \RuntimeException('');
        $body = FnStream::decorate(Psr7\Utils::streamFor('data'), [
            '__toString' => static function () use ($previous): string {
                throw $previous;
            },
        ]);
        $request = new Request('PUT', Server::$url, [], $body);

        try {
            $handler($request, [])->wait();

            self::fail('Expected RequestException');
        } catch (RequestException $e) {
            $exceptionRequest = $e->getRequest();
            self::assertSame($request->getMethod(), $exceptionRequest->getMethod());
            self::assertSame((string) $request->getUri(), (string) $exceptionRequest->getUri());
            self::assertSame('Failed to read the request body', $e->getMessage());
            self::assertSame($previous, $e->getPrevious());
        }
    }

    /**
     * @dataProvider transportLookingRequestBodyFailureMessageProvider
     */
    public function testRequestBodyFailureIsNotRepromotedToNetworkExceptionByMessage(string $message): void
    {
        $handler = new StreamHandler();
        $previous = new \RuntimeException($message);
        $body = FnStream::decorate(Psr7\Utils::streamFor('x'), [
            '__toString' => static function () use ($previous): string {
                throw $previous;
            },
        ]);
        $request = new Request('PUT', Server::$url, [], $body);

        try {
            $handler($request, [])->wait();

            self::fail('Expected RequestException');
        } catch (RequestException $e) {
            $exceptionRequest = $e->getRequest();
            self::assertSame($request->getMethod(), $exceptionRequest->getMethod());
            self::assertSame((string) $request->getUri(), (string) $exceptionRequest->getUri());
            self::assertSame($message, $e->getMessage());
            self::assertSame($previous, $e->getPrevious());
            self::assertInstanceOf(RequestExceptionInterface::class, $e);
            self::assertNotInstanceOf(ResponseException::class, $e);
            self::assertNotInstanceOf(NetworkExceptionInterface::class, $e);
        }
    }

    public static function transportLookingRequestBodyFailureMessageProvider(): iterable
    {
        return [
            'connect timeout' => ['Operation timed out'],
            'fopen connect timeout' => ['fopen(): Failed to open stream: Connection timed out'],
            'send timeout' => ['Send of 65536 bytes failed with errno=110 Connection timed out'],
            'send failure' => ['Send of 65536 bytes failed with errno=104 Connection reset by peer'],
            'windows timeout' => ['A connection attempt failed because the connected party did not properly respond after a period of time, or established connection failed because connected host has failed to respond'],
            'windows send timeout' => ['Send of 65536 bytes failed with errno=10060 A connection attempt failed because the connected party did not properly respond after a period of time, or established connection failed because connected host has failed to respond'],
            'tls reset' => ['SSL: Connection reset by peer'],
            'unexpected eof' => ['SSL operation failed with code 1. OpenSSL Error messages: error:0A000126:SSL routines::unexpected eof while reading'],
            'handshake eof' => ['SSL operation failed with code 1. OpenSSL Error messages: error:0A000126:SSL routines::unexpected eof while reading. Failed to enable crypto'],
        ];
    }

    public function testRejectsHttp3(): void
    {
        $handler = new StreamHandler();
        $request = new Request('GET', 'https://example.com', [], null, '3.0');

        try {
            $handler($request, []);
            self::fail('Expected request exception.');
        } catch (RequestException $e) {
            self::assertSame($request, $e->getRequest());
            self::assertSame('HTTP/3.0 is not supported by the stream handler.', $e->getMessage());
        }
    }

    public function testRejectsNonCallableOnStats(): void
    {
        $handler = new StreamHandler();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('on_stats must be callable');

        $handler(new Request('GET', 'http://example.com'), ['on_stats' => false]);
    }

    public function testStreamAttributeKeepsStreamOpen(): void
    {
        $this->queueRes();
        $handler = new StreamHandler();
        $request = new Request(
            'PUT',
            Server::$url.'foo?baz=bar',
            ['Foo' => 'Bar'],
            'test'
        );
        $response = $handler($request, ['stream' => true])->wait();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('OK', $response->getReasonPhrase());
        self::assertSame('8', $response->getHeaderLine('Content-Length'));
        $body = $response->getBody();
        $stream = $body->detach();
        self::assertIsResource($stream);
        self::assertSame('http', \stream_get_meta_data($stream)['wrapper_type']);
        self::assertSame('hi there', \stream_get_contents($stream));
        \fclose($stream);
        $sent = Server::received()[0];
        self::assertSame('PUT', $sent->getMethod());
        self::assertSame('http://127.0.0.1:8126/foo?baz=bar', (string) $sent->getUri());
        self::assertSame('Bar', $sent->getHeaderLine('Foo'));
        self::assertSame('test', (string) $sent->getBody());
    }

    public function testDrainsResponseIntoTempStream(): void
    {
        $this->queueRes();
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url);
        $response = $handler($request, [])->wait();
        $body = $response->getBody();
        $stream = $body->detach();
        self::assertSame('php://temp', \stream_get_meta_data($stream)['uri']);
        self::assertSame('hi', \fread($stream, 2));
        \fclose($stream);
    }

    public function testDrainsResponseIntoSaveToBody(): void
    {
        $r = \fopen('php://temp', 'r+');
        $this->queueRes();
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url);
        $response = $handler($request, ['sink' => $r])->wait();
        $body = $response->getBody()->detach();
        self::assertSame('php://temp', \stream_get_meta_data($body)['uri']);
        self::assertSame('hi', \fread($body, 2));
        self::assertSame(' there', \stream_get_contents($r));
        \fclose($r);
    }

    public function testDoesNotCloseResourceSinkWhenResponseIsDestroyed(): void
    {
        $stream = (function () {
            $stream = \tmpfile();
            self::assertIsResource($stream);

            $this->queueRes();
            $handler = new StreamHandler();
            $request = new Request('GET', Server::$url);
            $response = $handler($request, ['sink' => $stream])->wait();

            self::assertSame(200, $response->getStatusCode());

            return $stream;
        })();

        \gc_collect_cycles();

        try {
            self::assertIsResource($stream);
            \rewind($stream);
            self::assertSame('hi there', \stream_get_contents($stream));
        } finally {
            if (\is_resource($stream)) {
                \fclose($stream);
            }
        }
    }

    public function testDoesNotCloseResourceSinkWhenResponseBodyIsClosed(): void
    {
        $stream = \tmpfile();
        self::assertIsResource($stream);

        try {
            $this->queueRes();
            $handler = new StreamHandler();
            $request = new Request('GET', Server::$url);
            $response = $handler($request, ['sink' => $stream])->wait();

            $response->getBody()->close();

            self::assertIsResource($stream);
            \rewind($stream);
            self::assertSame('hi there', \stream_get_contents($stream));
        } finally {
            if (\is_resource($stream)) {
                \fclose($stream);
            }
        }
    }

    public function testDrainsResponseIntoSaveToBodyAtPath(): void
    {
        $tmpfname = \tempnam(\sys_get_temp_dir(), 'save_to_path');
        $body = null;

        try {
            $this->queueRes();
            $handler = new StreamHandler();
            $request = new Request('GET', Server::$url);
            $response = $handler($request, ['sink' => $tmpfname])->wait();
            $body = $response->getBody();
            self::assertSame($tmpfname, $body->getMetadata('uri'));
            self::assertSame('hi', $body->read(2));
        } finally {
            if ($body !== null) {
                $body->close();
            }
            if (\file_exists($tmpfname)) {
                \unlink($tmpfname);
            }
        }
    }

    public function testDrainsResponseIntoSaveToBodyAtNonExistentPath(): void
    {
        $tmpfname = \tempnam(\sys_get_temp_dir(), 'save_to_path');
        \unlink($tmpfname);
        $body = null;

        try {
            $this->queueRes();
            $handler = new StreamHandler();
            $request = new Request('GET', Server::$url);
            $response = $handler($request, ['sink' => $tmpfname])->wait();
            $body = $response->getBody();
            self::assertSame($tmpfname, $body->getMetadata('uri'));
            self::assertSame('hi', $body->read(2));
        } finally {
            if ($body !== null) {
                $body->close();
            }
            if (\file_exists($tmpfname)) {
                \unlink($tmpfname);
            }
        }
    }

    public function testDrainsResponseAndReadsOnlyContentLengthBytes(): void
    {
        Server::flush();
        Server::enqueue([
            new Response(200, [
                'Foo' => 'Bar',
                'Content-Length' => '8',
            ], 'hi there... This has way too much data!'),
        ]);
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url);
        $response = $handler($request, [])->wait();
        $body = $response->getBody();
        $stream = $body->detach();
        self::assertSame('hi there', \stream_get_contents($stream));
        \fclose($stream);
    }

    public function testThrowsResponseTransferExceptionWhenContentLengthBodyIsShort(): void
    {
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url);
        $stats = null;
        $exception = null;

        $this->setStreamHandlerLastHeaders($handler, [
            'HTTP/1.1 200 OK',
            'Content-Length: 3',
        ]);

        try {
            $this->invokeStreamHandlerCreateResponse(
                $handler,
                $request,
                [
                    'on_stats' => static function (TransferStats $transferStats) use (&$stats): void {
                        $stats = $transferStats;
                    },
                ],
                Psr7\Utils::streamFor('ab')
            )->wait();
            self::fail('Expected ResponseTransferException');
        } catch (ResponseTransferException $e) {
            $exception = $e;
            self::assertSame($request, $e->getRequest());
            self::assertSame(200, $e->getResponse()->getStatusCode());
            self::assertSame('Response body ended before the declared Content-Length was reached', $e->getMessage());
            self::assertNull($e->getPrevious());
            self::assertNotInstanceOf(ResponseTimeoutException::class, $e);
            self::assertNotInstanceOf(NetworkExceptionInterface::class, $e);
        }

        self::assertInstanceOf(ResponseTransferException::class, $exception);
        self::assertInstanceOf(TransferStats::class, $stats);
        self::assertTrue($stats->hasResponse());
        self::assertSame($exception->getResponse(), $stats->getResponse());
        self::assertSame($exception, $stats->getHandlerErrorData());
    }

    public function testExactContentLengthBodySucceeds(): void
    {
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url);

        $this->setStreamHandlerLastHeaders($handler, [
            'HTTP/1.1 200 OK',
            'Content-Length: 3',
        ]);

        $response = $this->invokeStreamHandlerCreateResponse($handler, $request, [], Psr7\Utils::streamFor('abc'))->wait();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('abc', (string) $response->getBody());
    }

    public function testOverlongContentLengthBodySucceedsAndStopsAtDeclaredLength(): void
    {
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url);

        $this->setStreamHandlerLastHeaders($handler, [
            'HTTP/1.1 200 OK',
            'Content-Length: 3',
        ]);

        $response = $this->invokeStreamHandlerCreateResponse($handler, $request, [], Psr7\Utils::streamFor('abcdef'))->wait();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('abc', (string) $response->getBody());
    }

    /**
     * @dataProvider bodilessStatusProvider
     */
    public function testBodilessStatusWithPositiveContentLengthSucceeds(int $status, string $reason): void
    {
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url);

        $this->setStreamHandlerLastHeaders($handler, [
            "HTTP/1.1 {$status} {$reason}",
            'Content-Length: 3',
        ]);

        $response = $this->invokeStreamHandlerCreateResponse($handler, $request, [], Psr7\Utils::streamFor(''))->wait();

        self::assertSame($status, $response->getStatusCode());
    }

    public static function bodilessStatusProvider(): array
    {
        return [
            '100 Continue' => [100, 'Continue'],
            '101 Switching Protocols' => [101, 'Switching Protocols'],
            '204 No Content' => [204, 'No Content'],
            '304 Not Modified' => [304, 'Not Modified'],
        ];
    }

    public function testShortContentLengthBodyOn205Rejects(): void
    {
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url);

        $this->setStreamHandlerLastHeaders($handler, [
            'HTTP/1.1 205 Reset Content',
            'Content-Length: 3',
        ]);

        try {
            $this->invokeStreamHandlerCreateResponse($handler, $request, [], Psr7\Utils::streamFor('ab'))->wait();
            self::fail('Expected ResponseTransferException');
        } catch (ResponseTransferException $e) {
            self::assertSame(205, $e->getResponse()->getStatusCode());
        }
    }

    public function testExactContentLengthBodyOn205Succeeds(): void
    {
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url);

        $this->setStreamHandlerLastHeaders($handler, [
            'HTTP/1.1 205 Reset Content',
            'Content-Length: 3',
        ]);

        $response = $this->invokeStreamHandlerCreateResponse($handler, $request, [], Psr7\Utils::streamFor('abc'))->wait();

        self::assertSame(205, $response->getStatusCode());
    }

    public function testConnect2xxWithPositiveContentLengthSucceeds(): void
    {
        $handler = new StreamHandler();
        $request = new Request('CONNECT', Server::$url);

        $this->setStreamHandlerLastHeaders($handler, [
            'HTTP/1.1 200 Connection Established',
            'Content-Length: 3',
        ]);

        $response = $this->invokeStreamHandlerCreateResponse($handler, $request, [], Psr7\Utils::streamFor(''))->wait();

        self::assertSame(200, $response->getStatusCode());
    }

    public function testLeadingZeroContentLengthIsEnforced(): void
    {
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url);

        $this->setStreamHandlerLastHeaders($handler, [
            'HTTP/1.1 200 OK',
            'Content-Length: 0003',
        ]);

        $this->expectException(ResponseTransferException::class);

        $this->invokeStreamHandlerCreateResponse($handler, $request, [], Psr7\Utils::streamFor('ab'))->wait();
    }

    public function testDuplicateIdenticalContentLengthIsEnforced(): void
    {
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url);

        $this->setStreamHandlerLastHeaders($handler, [
            'HTTP/1.1 200 OK',
            'Content-Length: 3',
            'Content-Length: 3',
        ]);

        $this->expectException(ResponseTransferException::class);

        $this->invokeStreamHandlerCreateResponse($handler, $request, [], Psr7\Utils::streamFor('ab'))->wait();
    }

    public function testConflictingContentLengthSkipsShortBodyCheck(): void
    {
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url);

        $this->setStreamHandlerLastHeaders($handler, [
            'HTTP/1.1 200 OK',
            'Content-Length: 3',
            'Content-Length: 5',
        ]);

        $response = $this->invokeStreamHandlerCreateResponse($handler, $request, [], Psr7\Utils::streamFor('ab'))->wait();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ab', (string) $response->getBody());
    }

    public function testContentLengthAbovePhpIntMaxRejectsNonStreamedResponse(): void
    {
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url);
        $overflow = '99999999999999999999999999';

        $this->setStreamHandlerLastHeaders($handler, [
            'HTTP/1.1 200 OK',
            "Content-Length: {$overflow}",
        ]);

        try {
            $this->invokeStreamHandlerCreateResponse($handler, $request, [], Psr7\Utils::streamFor('ab'))->wait();
            self::fail('Expected ResponseException');
        } catch (ResponseException $e) {
            self::assertResponseContentLengthPlatformException($e);
        }
    }

    public function testContentLengthAbovePhpIntMaxAllowsStreamedResponse(): void
    {
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url);
        $overflow = '99999999999999999999999999';

        $this->setStreamHandlerLastHeaders($handler, [
            'HTTP/1.1 200 OK',
            "Content-Length: {$overflow}",
        ]);

        $response = $this->invokeStreamHandlerCreateResponse($handler, $request, ['stream' => true], Psr7\Utils::streamFor('ab'))->wait();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($overflow, $response->getHeaderLine('Content-Length'));
        self::assertSame('ab', (string) $response->getBody());
    }

    public function testAttemptsSourceCloseWhenContentLengthOverflows(): void
    {
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url);
        $closeCalled = false;
        $source = FnStream::decorate(Psr7\Utils::streamFor('ab'), [
            'close' => static function () use (&$closeCalled): void {
                $closeCalled = true;

                throw new \RuntimeException('close failed');
            },
        ]);

        $this->setStreamHandlerLastHeaders($handler, [
            'HTTP/1.1 200 OK',
            'Content-Length: 99999999999999999999999999',
        ]);

        try {
            $this->invokeStreamHandlerCreateResponse($handler, $request, [], $source)->wait();
            self::fail('Expected ResponseException');
        } catch (ResponseException $e) {
            self::assertResponseContentLengthPlatformException($e);
        }

        self::assertTrue($closeCalled);
    }

    public function testAttemptsSourceCloseWhenContentLengthBodyIsShort(): void
    {
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url);
        $closeCalled = false;
        $source = FnStream::decorate(Psr7\Utils::streamFor('ab'), [
            'close' => static function () use (&$closeCalled): void {
                $closeCalled = true;

                throw new \RuntimeException('close failed');
            },
        ]);

        $this->setStreamHandlerLastHeaders($handler, [
            'HTTP/1.1 200 OK',
            'Content-Length: 3',
        ]);

        try {
            $this->invokeStreamHandlerCreateResponse($handler, $request, [], $source)->wait();
            self::fail('Expected ResponseTransferException');
        } catch (ResponseTransferException $e) {
            self::assertSame('Response body ended before the declared Content-Length was reached', $e->getMessage());
            self::assertNull($e->getPrevious());
        }

        self::assertTrue($closeCalled);
    }

    public function testTransferEncodingWithBogusContentLengthDoesNotTriggerShortBody(): void
    {
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url);

        $this->setStreamHandlerLastHeaders($handler, [
            'HTTP/1.1 200 OK',
            'Transfer-Encoding: chunked',
            'Content-Length: 100',
        ]);

        $response = $this->invokeStreamHandlerCreateResponse($handler, $request, [], Psr7\Utils::streamFor('abc'))->wait();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('abc', (string) $response->getBody());
    }

    public function testShortRawBodyWithUnsupportedEncodingAndDecodeOnThrows(): void
    {
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url);

        $this->setStreamHandlerLastHeaders($handler, [
            'HTTP/1.1 200 OK',
            'Content-Encoding: br',
            'Content-Length: 10',
        ]);

        try {
            $this->invokeStreamHandlerCreateResponse($handler, $request, ['decode_content' => true], Psr7\Utils::streamFor('rawbytes'))->wait();
            self::fail('Expected ResponseTransferException');
        } catch (ResponseTransferException $e) {
            self::assertSame(200, $e->getResponse()->getStatusCode());
        }
    }

    public function testShortRawBodyWithGzipEncodingAndDecodeOffThrows(): void
    {
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url);

        $this->setStreamHandlerLastHeaders($handler, [
            'HTTP/1.1 200 OK',
            'Content-Encoding: gzip',
            'Content-Length: 10',
        ]);

        try {
            $this->invokeStreamHandlerCreateResponse($handler, $request, ['decode_content' => false], Psr7\Utils::streamFor('rawbytes'))->wait();
            self::fail('Expected ResponseTransferException');
        } catch (ResponseTransferException $e) {
            self::assertSame(200, $e->getResponse()->getStatusCode());
        }
    }

    public function testDoesNotDrainWhenHeadRequest(): void
    {
        Server::flush();
        // Say the content-length is 8, but return no response.
        Server::enqueue([
            new Response(200, [
                'Foo' => 'Bar',
                'Content-Length' => '8',
            ], ''),
        ]);
        $handler = new StreamHandler();
        $request = new Request('HEAD', Server::$url);
        $response = $handler($request, [])->wait();
        $body = $response->getBody();
        $stream = $body->detach();
        self::assertSame('', \stream_get_contents($stream));
        \fclose($stream);
    }

    public function testAutomaticallyDecompressGzip(): void
    {
        Server::flush();
        $content = \gzencode('test');
        Server::enqueue([
            new Response(200, [
                'Content-Encoding' => 'gzip',
                'Content-Length' => (string) \strlen($content),
            ], $content),
        ]);
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url);
        $response = $handler($request, ['decode_content' => true])->wait();
        self::assertSame('test', (string) $response->getBody());
        self::assertFalse($response->hasHeader('content-encoding'));
        self::assertTrue(!$response->hasHeader('content-length') || $response->getHeaderLine('content-length') == $response->getBody()->getSize());
    }

    public function testDecodedGzipLargerThanEncodedReturnsFullBodyAndDropsContentLength(): void
    {
        $decoded = \str_repeat('A', 1000);
        $gzip = \gzencode($decoded);
        self::assertIsString($gzip);

        $resource = \fopen('php://temp', 'r+');
        self::assertIsResource($resource);
        \fwrite($resource, $gzip);
        \rewind($resource);

        $handler = new StreamHandler();
        $request = new Request('GET', 'http://example.com');

        $this->setStreamHandlerLastHeaders($handler, [
            'HTTP/1.1 200 OK',
            'Content-Encoding: gzip',
            'Content-Length: '.\strlen($gzip),
        ]);

        /** @var ResponseInterface $response */
        $response = $this->invokeStreamHandlerCreateResponse($handler, $request, ['decode_content' => true], $resource)->wait();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($decoded, (string) $response->getBody());
        self::assertFalse($response->hasHeader('Content-Length'));
        self::assertSame((string) \strlen($gzip), $response->getHeaderLine('x-encoded-content-length'));
    }

    public function testAutomaticallyDecompressGzipHead(): void
    {
        Server::flush();
        $content = \gzencode('test');
        Server::enqueue([
            new Response(200, [
                'Content-Encoding' => 'gzip',
                'Content-Length' => (string) \strlen($content),
            ], $content),
        ]);
        $handler = new StreamHandler();
        $request = new Request('HEAD', Server::$url);
        $response = $handler($request, ['decode_content' => true])->wait();

        // Verify that the content-length is removed after decoding.
        self::assertTrue(!$response->hasHeader('content-length') || $response->getHeaderLine('content-length') == \strlen($content));
    }

    public function testReportsOriginalSizeAndContentEncodingAfterDecoding(): void
    {
        Server::flush();
        $content = \gzencode('test');
        Server::enqueue([
            new Response(200, [
                'Content-Encoding' => 'gzip',
                'Content-Length' => (string) \strlen($content),
            ], $content),
        ]);
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url);
        $response = $handler($request, ['decode_content' => true])->wait();

        self::assertSame(
            'gzip',
            $response->getHeaderLine('x-encoded-content-encoding')
        );
        self::assertSame(
            \strlen($content),
            (int) $response->getHeaderLine('x-encoded-content-length')
        );
    }

    public function testDoesNotForceGzipDecode(): void
    {
        Server::flush();
        $content = \gzencode('test');
        Server::enqueue([
            new Response(200, [
                'Content-Encoding' => 'gzip',
                'Content-Length' => (string) \strlen($content),
            ], $content),
        ]);
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url);
        $response = $handler($request, ['decode_content' => false])->wait();
        self::assertSame($content, (string) $response->getBody());
        self::assertSame('gzip', $response->getHeaderLine('content-encoding'));
        self::assertEquals(\strlen($content), $response->getHeaderLine('content-length'));
    }

    public function testProtocolVersion(): void
    {
        $this->queueRes();
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url, [], null, '1.0');
        $handler($request, []);
        self::assertSame('1.0', Server::received()[0]->getProtocolVersion());
    }

    protected function getSendResult(array $opts): ResponseInterface
    {
        $this->queueRes();
        $handler = new StreamHandler();
        $opts['stream'] = true;
        $request = new Request('GET', Server::$url);

        return $handler($request, $opts)->wait();
    }

    private function applyDefaultTlsMinimum(string $uri, array $context): array
    {
        $handler = new StreamHandler();
        $request = new Request('GET', $uri);
        $method = new \ReflectionMethod(StreamHandler::class, 'addDefaultTlsMinimum');
        if (\PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }

        $method->invokeArgs($handler, [$request, &$context]);

        return $context;
    }

    private function matchesStreamHandlerError(string $method, string $message): bool
    {
        $reflection = new \ReflectionMethod(StreamHandler::class, $method);
        if (\PHP_VERSION_ID < 80100) {
            $reflection->setAccessible(true);
        }

        return $reflection->invoke(null, $message) === true;
    }

    public function testAddsProxy(): void
    {
        try {
            $this->getSendResult(['proxy' => '127.0.0.1:8125']);
            self::fail('Expected ConnectException');
        } catch (ConnectException $e) {
            self::assertMatchesRegularExpression('/refused/i', $e->getMessage());
        }
    }

    public function testAddsProxyByProtocol(): void
    {
        $url = Server::$url;
        $res = $this->getSendResult(['proxy' => ['http' => $url]]);
        $opts = \stream_context_get_options($res->getBody()->detach());

        foreach ([\PHP_URL_HOST, \PHP_URL_PORT] as $part) {
            self::assertSame(parse_url($url, $part), parse_url($opts['http']['proxy'], $part));
        }
    }

    public function testAddsProxyButHonorsNoProxy(): void
    {
        $url = Server::$url;
        $res = $this->getSendResult(['proxy' => [
            'http' => $url,
            'no' => ['*'],
        ]]);
        $opts = \stream_context_get_options($res->getBody()->detach());
        self::assertArrayNotHasKey('proxy', $opts['http']);
    }

    /**
     * @dataProvider invalidProxyOptionProvider
     *
     * @param mixed $proxy
     */
    public function testEnsuresProxyOptionShapeIsValid($proxy): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->getSendResult(['proxy' => $proxy]);
    }

    public static function invalidProxyOptionProvider(): array
    {
        return [
            [new \stdClass()],
            [['http' => new \stdClass()]],
            [['http' => 'http://proxy.example.com:8125', 'no' => new \stdClass()]],
            [['http' => 'http://proxy.example.com:8125', 'no' => [new \stdClass()]]],
        ];
    }

    public function testAddsProxyButHonorsStringNoProxy(): void
    {
        $url = Server::$url;
        $host = (string) parse_url($url, \PHP_URL_HOST);

        $res = $this->getSendResult(['proxy' => [
            'http' => $url,
            'no' => 'example.com, '.$host,
        ]]);
        $opts = \stream_context_get_options($res->getBody()->detach());

        self::assertArrayNotHasKey('proxy', $opts['http']);
    }

    public function testUsesProxy(): void
    {
        $this->queueRes();
        $handler = new StreamHandler();
        $request = new Request('GET', 'http://www.example.com', [], null, '1.0');
        $response = $handler($request, [
            'proxy' => Server::$url,
        ])->wait();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('OK', $response->getReasonPhrase());
        self::assertSame('Bar', $response->getHeaderLine('Foo'));
        self::assertSame('8', $response->getHeaderLine('Content-Length'));
        self::assertSame('hi there', (string) $response->getBody());
    }

    public function testAddsTimeout(): void
    {
        $res = $this->getSendResult(['stream' => true, 'timeout' => 200]);
        $opts = \stream_context_get_options($res->getBody()->detach());
        self::assertEquals(200, $opts['http']['timeout']);
    }

    /**
     * @dataProvider invalidStreamTimeoutProvider
     *
     * @param mixed $value
     */
    public function testRejectsInvalidStreamTimeouts(string $option, $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($option.' must be 0 or greater than or equal to 0.001 seconds');
        $this->getSendResult([$option => $value]);
    }

    public static function invalidStreamTimeoutProvider(): array
    {
        return [
            ['timeout', 0.0001],
            ['timeout', -1],
            ['read_timeout', 0.0001],
            ['read_timeout', -1],
        ];
    }

    public function testVerifiesVerifyIsValidIfPath(): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('SSL CA bundle not found: /does/not/exist');

        $this->getSendResult(['verify' => '/does/not/exist']);
    }

    public function testVerifyCanBeDisabled(): void
    {
        $handler = $this->getSendResult(['verify' => false]);
        self::assertInstanceOf(Response::class, $handler);
    }

    public function testVerifiesCertIfValidPath(): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('SSL certificate not found: /does/not/exist');

        $this->getSendResult(['cert' => '/does/not/exist']);
    }

    public function testUsesSystemDefaultBundle(): void
    {
        $res = $this->getSendResult(['verify' => true]);
        $opts = \stream_context_get_options($res->getBody()->detach());
        self::assertArrayNotHasKey('cafile', $opts['ssl']);
    }

    public function testEnsuresVerifyOptionIsValid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid verify request option');

        $this->getSendResult(['verify' => 10]);
    }

    public function testEnsuresCryptoMethodOptionIsValid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid crypto_method request option: unknown version provided');

        $this->getSendResult(['crypto_method' => 123]);
    }

    public function testDefaultsHttpsToTls12Minimum(): void
    {
        $context = $this->applyDefaultTlsMinimum('https://example.com', ['ssl' => []]);

        self::assertSame(\STREAM_CRYPTO_PROTO_TLSv1_2, $context['ssl']['min_proto_version']);
    }

    public function testDoesNotDefaultTlsMinimumForHttp(): void
    {
        $context = $this->applyDefaultTlsMinimum('http://example.com', ['ssl' => []]);

        self::assertArrayNotHasKey('min_proto_version', $context['ssl']);
    }

    public function testDoesNotDefaultTlsMinimumWhenTlsContextExists(): void
    {
        $context = $this->applyDefaultTlsMinimum('https://example.com', [
            'ssl' => ['crypto_method' => \STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT],
        ]);

        self::assertArrayNotHasKey('min_proto_version', $context['ssl']);
    }

    public function testSetsCryptoMethodTls10(): void
    {
        $res = $this->getSendResult(['crypto_method' => \STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT]);
        $opts = \stream_context_get_options($res->getBody()->detach());
        self::assertSame(\STREAM_CRYPTO_PROTO_TLSv1_0, $opts['ssl']['min_proto_version']);
    }

    public function testSetsCryptoMethodTls11(): void
    {
        $res = $this->getSendResult(['crypto_method' => \STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT]);
        $opts = \stream_context_get_options($res->getBody()->detach());
        self::assertSame(\STREAM_CRYPTO_PROTO_TLSv1_1, $opts['ssl']['min_proto_version']);
    }

    public function testSetsCryptoMethodTls12(): void
    {
        $res = $this->getSendResult(['crypto_method' => \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT]);
        $opts = \stream_context_get_options($res->getBody()->detach());
        self::assertSame(\STREAM_CRYPTO_PROTO_TLSv1_2, $opts['ssl']['min_proto_version']);
    }

    public function testSetsCryptoMethodTls13(): void
    {
        $res = $this->getSendResult(['crypto_method' => \STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT]);
        $opts = \stream_context_get_options($res->getBody()->detach());
        self::assertSame(\STREAM_CRYPTO_PROTO_TLSv1_3, $opts['ssl']['min_proto_version']);
    }

    public function testStreamContextTlsMinimumOverridesCryptoMethod(): void
    {
        $res = $this->getSendResult([
            'crypto_method' => \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
            'stream_context' => [
                'ssl' => ['min_proto_version' => \STREAM_CRYPTO_PROTO_TLSv1_0],
            ],
        ]);
        $opts = \stream_context_get_options($res->getBody()->detach());

        self::assertSame(\STREAM_CRYPTO_PROTO_TLSv1_0, $opts['ssl']['min_proto_version']);
    }

    public function testStreamContextCryptoMethodOverridesCryptoMethod(): void
    {
        $res = $this->getSendResult([
            'crypto_method' => \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
            'stream_context' => [
                'ssl' => ['crypto_method' => \STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT],
            ],
        ]);
        $opts = \stream_context_get_options($res->getBody()->detach());

        self::assertSame(\STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT, $opts['ssl']['crypto_method']);
        self::assertArrayNotHasKey('min_proto_version', $opts['ssl']);
    }

    public function testCanSetPasswordWhenSettingCert(): void
    {
        $path = __FILE__;
        $res = $this->getSendResult(['cert' => [$path, 'foo']]);
        $opts = \stream_context_get_options($res->getBody()->detach());
        self::assertSame($path, $opts['ssl']['local_cert']);
        self::assertSame('foo', $opts['ssl']['passphrase']);
    }

    public function testCanSetCertWithArrayPathOnly(): void
    {
        $path = __FILE__;
        $res = $this->getSendResult(['cert' => [$path]]);
        $opts = \stream_context_get_options($res->getBody()->detach());

        self::assertSame($path, $opts['ssl']['local_cert']);
        self::assertArrayNotHasKey('passphrase', $opts['ssl']);
    }

    public function testCanSetCertTypeToPem(): void
    {
        $response = $this->getSendResult(['cert_type' => 'pem']);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testRejectsNonPemCertType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The stream handler only supports "PEM" for the cert_type request option.');

        $this->getSendResult(['cert_type' => 'DER']);
    }

    public function testCanSetSslKey(): void
    {
        $path = __FILE__;
        $res = $this->getSendResult(['ssl_key' => $path]);
        $opts = \stream_context_get_options($res->getBody()->detach());
        self::assertSame($path, $opts['ssl']['local_pk']);
    }

    public function testCanSetPasswordWhenSettingSslKey(): void
    {
        $path = __FILE__;
        $res = $this->getSendResult(['ssl_key' => [$path, 'foo']]);
        $opts = \stream_context_get_options($res->getBody()->detach());
        self::assertSame($path, $opts['ssl']['local_pk']);
        self::assertSame('foo', $opts['ssl']['passphrase']);
    }

    public function testCanSetCertAndSslKeyWithSamePassword(): void
    {
        $path = __FILE__;
        $res = $this->getSendResult([
            'cert' => [$path, 'foo'],
            'ssl_key' => [$path, 'foo'],
        ]);
        $opts = \stream_context_get_options($res->getBody()->detach());
        self::assertSame($path, $opts['ssl']['local_cert']);
        self::assertSame($path, $opts['ssl']['local_pk']);
        self::assertSame('foo', $opts['ssl']['passphrase']);
    }

    public function testRejectsCertAndSslKeyWithDifferentPasswords(): void
    {
        $path = __FILE__;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot use different passphrases for cert and ssl_key with the stream handler');

        $this->getSendResult([
            'cert' => [$path, 'foo'],
            'ssl_key' => [$path, 'bar'],
        ]);
    }

    public function testCanSetSslKeyWithArrayPathOnly(): void
    {
        $path = __FILE__;
        $res = $this->getSendResult(['ssl_key' => [$path]]);
        $opts = \stream_context_get_options($res->getBody()->detach());

        self::assertSame($path, $opts['ssl']['local_pk']);
        self::assertArrayNotHasKey('passphrase', $opts['ssl']);
    }

    public function testCanSetSslKeyTypeToPem(): void
    {
        $response = $this->getSendResult(['ssl_key_type' => 'pem']);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testRejectsNonPemSslKeyType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The stream handler only supports "PEM" for the ssl_key_type request option.');

        $this->getSendResult(['ssl_key_type' => 'DER']);
    }

    /**
     * @dataProvider invalidCertOptionProvider
     *
     * @param mixed $cert
     */
    public function testEnsuresCertOptionShapeIsValid($cert): void
    {
        $handler = new StreamHandler();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid cert request option');
        $handler(new Request('GET', 'http://example.com'), ['cert' => $cert]);
    }

    public static function invalidCertOptionProvider(): array
    {
        return [
            [[]],
            [['passphrase' => 'test']],
            [[new \stdClass(), 'test']],
            [[__FILE__, new \stdClass()]],
            [new \stdClass()],
        ];
    }

    /**
     * @dataProvider invalidSslKeyOptionProvider
     *
     * @param mixed $sslKey
     */
    public function testEnsuresSslKeyOptionShapeIsValid($sslKey): void
    {
        $handler = new StreamHandler();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid ssl_key request option');
        $handler(new Request('GET', 'http://example.com'), ['ssl_key' => $sslKey]);
    }

    public static function invalidSslKeyOptionProvider(): array
    {
        return [
            [[]],
            [['passphrase' => 'test']],
            [[new \stdClass(), 'test']],
            [[__FILE__, new \stdClass()]],
            [new \stdClass()],
        ];
    }

    public function testDebugAttributeWritesToStream(): void
    {
        $this->queueRes();
        $f = \fopen('php://temp', 'w+');
        $this->getSendResult(['debug' => $f]);
        \fseek($f, 0);
        $contents = \stream_get_contents($f);
        self::assertStringContainsString('<GET http://127.0.0.1:8126/> [CONNECT]', $contents);
        self::assertStringContainsString('<GET http://127.0.0.1:8126/> [FILE_SIZE_IS]', $contents);
        self::assertStringContainsString('<GET http://127.0.0.1:8126/> [PROGRESS]', $contents);
    }

    public function testDebugAttributeWritesStreamInfoToBuffer(): void
    {
        $called = false;
        $this->queueRes();
        $buffer = \fopen('php://temp', 'r+');
        $this->getSendResult([
            'progress' => static function () use (&$called): void {
                $called = true;
            },
            'debug' => $buffer,
        ]);
        \fseek($buffer, 0);
        $contents = \stream_get_contents($buffer);
        self::assertStringContainsString('<GET http://127.0.0.1:8126/> [CONNECT]', $contents);
        self::assertStringContainsString('<GET http://127.0.0.1:8126/> [FILE_SIZE_IS] message: "Content-Length: 8"', $contents);
        self::assertStringContainsString('<GET http://127.0.0.1:8126/> [PROGRESS] bytes_max: "8"', $contents);
        self::assertTrue($called);
    }

    public function testEmitsProgressInformation(): void
    {
        $called = [];
        $this->queueRes();
        $this->getSendResult([
            'progress' => static function (...$args) use (&$called): void {
                $called[] = $args;
            },
        ]);
        self::assertNotEmpty($called);
        self::assertEquals(8, $called[0][0]);
        self::assertEquals(0, $called[0][1]);
    }

    public function testEmitsIntegerProgressInformation(): void
    {
        $called = [];
        $this->queueRes();
        $this->getSendResult([
            'progress' => static function (int $downloadTotal, int $downloadedBytes, int $uploadTotal, int $uploadedBytes) use (&$called): void {
                $called[] = [$downloadTotal, $downloadedBytes, $uploadTotal, $uploadedBytes];
            },
        ]);
        self::assertNotEmpty($called);
        self::assertSame(8, $called[0][0]);
        self::assertSame(0, $called[0][1]);
        self::assertSame(0, $called[0][2]);
        self::assertSame(0, $called[0][3]);
    }

    public function testProgressReturnValueDoesNotAbortTransfer(): void
    {
        $this->queueRes();

        $response = $this->getSendResult([
            'progress' => static function (): bool {
                return true;
            },
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('hi there', (string) $response->getBody());
    }

    public function testProgressOverflowValueThrows(): void
    {
        $this->expectException(\OverflowException::class);
        $this->expectExceptionMessage('Progress byte count exceeds the maximum integer size supported on this platform');

        TransferByteCounter::progressValueToInt(\INF);
    }

    public function testEmitsProgressInformationAndDebugInformation(): void
    {
        $called = [];
        $this->queueRes();
        $buffer = \fopen('php://memory', 'w+');
        $this->getSendResult([
            'debug' => $buffer,
            'progress' => static function (...$args) use (&$called): void {
                $called[] = $args;
            },
        ]);
        self::assertNotEmpty($called);
        self::assertEquals(8, $called[0][0]);
        self::assertEquals(0, $called[0][1]);
        \rewind($buffer);
        self::assertNotEmpty(\stream_get_contents($buffer));
        \fclose($buffer);
    }

    public function testPerformsShallowMergeOfCustomContextOptions(): void
    {
        $res = $this->getSendResult([
            'stream_context' => [
                'http' => [
                    'request_fulluri' => true,
                    'method' => 'HEAD',
                ],
                'socket' => [
                    'bindto' => '127.0.0.1:0',
                ],
                'ssl' => [
                    'verify_peer' => false,
                ],
            ],
        ]);
        $opts = \stream_context_get_options($res->getBody()->detach());
        self::assertSame('HEAD', $opts['http']['method']);
        self::assertTrue($opts['http']['request_fulluri']);
        self::assertSame('127.0.0.1:0', $opts['socket']['bindto']);
        self::assertFalse($opts['ssl']['verify_peer']);
    }

    public function testEnsuresThatStreamContextIsAnArray(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('stream_context must be an array');

        $this->getSendResult(['stream_context' => 'foo']);
    }

    public function testDoesNotAddContentTypeByDefault(): void
    {
        $this->queueRes();
        $handler = new StreamHandler();
        $request = new Request('PUT', Server::$url, ['Content-Length' => '3'], 'foo');
        $handler($request, []);
        $req = Server::received()[0];
        self::assertEquals('', $req->getHeaderLine('Content-Type'));
        self::assertEquals(3, $req->getHeaderLine('Content-Length'));
    }

    public function testAddsContentLengthByDefault(): void
    {
        $this->queueRes();
        $handler = new StreamHandler();
        $request = new Request('PUT', Server::$url, [], 'foo');
        $handler($request, []);
        $req = Server::received()[0];
        self::assertEquals(3, $req->getHeaderLine('Content-Length'));
    }

    public function testAddsContentLengthForPUTEvenWhenEmpty(): void
    {
        $this->queueRes();
        $handler = new StreamHandler();
        $request = new Request('PUT', Server::$url, [], '');
        $handler($request, []);
        $req = Server::received()[0];
        self::assertEquals(0, $req->getHeaderLine('Content-Length'));
    }

    public function testAddsContentLengthForPOSTEvenWhenEmpty(): void
    {
        $this->queueRes();
        $handler = new StreamHandler();
        $request = new Request('POST', Server::$url, [], '');
        $handler($request, []);
        $req = Server::received()[0];
        self::assertEquals(0, $req->getHeaderLine('Content-Length'));
    }

    public function testDontAddContentLengthForGETEvenWhenEmpty(): void
    {
        $this->queueRes();
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url, [], '');
        $handler($request, []);
        $req = Server::received()[0];
        self::assertSame('', $req->getHeaderLine('Content-Length'));
    }

    public function testSupports100Continue(): void
    {
        Server::flush();
        $response = new Response(200, ['Test' => 'Hello', 'Content-Length' => '4'], 'test');
        Server::enqueue([$response]);
        $request = new Request('PUT', Server::$url, ['Expect' => '100-Continue'], 'test');
        $handler = new StreamHandler();
        $response = $handler($request, [])->wait();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Hello', $response->getHeaderLine('Test'));
        self::assertSame('4', $response->getHeaderLine('Content-Length'));
        self::assertSame('test', (string) $response->getBody());
    }

    public function testDoesSleep(): void
    {
        $response = new Response(200);
        Server::enqueue([$response]);
        $a = new StreamHandler();
        $request = new Request('GET', Server::$url);
        $s = Utils::currentTime();
        $a($request, ['delay' => 0.1])->wait();
        self::assertGreaterThan(0.0001, Utils::currentTime() - $s);
    }

    public function testEnsuresOnHeadersIsCallable(): void
    {
        $req = new Request('GET', Server::$url);
        $handler = new StreamHandler();

        $this->expectException(\InvalidArgumentException::class);
        $handler($req, ['on_headers' => 'error!']);
    }

    public function testEnsuresProgressIsCallable(): void
    {
        $req = new Request('GET', 'http://example.com');
        $handler = new StreamHandler();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('progress client option must be callable');
        $handler($req, ['progress' => 'error!']);
    }

    public function testRejectsPromiseWhenOnHeadersFails(): void
    {
        Server::flush();
        Server::enqueue([
            new Response(200, ['X-Foo' => 'bar'], 'abc 123'),
        ]);
        $req = new Request('GET', Server::$url);
        $handler = new StreamHandler();
        $promise = $handler($req, [
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
        Server::flush();
        Server::enqueue([
            new Response(200, ['X-Foo' => 'bar'], 'abc 123'),
        ]);
        $req = new Request('GET', Server::$url);
        $handler = new StreamHandler();
        $promise = $handler($req, [
            'on_headers' => static function (): void {
                throw new \Error('test');
            },
        ]);

        try {
            $promise->wait();
            self::fail('Expected RequestException');
        } catch (RequestException $e) {
            self::assertStringContainsString(
                'An error was encountered during the on_headers event',
                $e->getMessage()
            );
            self::assertInstanceOf(\Error::class, $e->getPrevious());
            self::assertNotInstanceOf(ResponseTransferException::class, $e);
            self::assertNotInstanceOf(NetworkExceptionInterface::class, $e);
        }
    }

    public function testInvokesOnStatsWhenOnHeadersFails(): void
    {
        Server::flush();
        Server::enqueue([
            new Response(200, ['X-Foo' => 'bar'], 'abc 123'),
        ]);
        $req = new Request('GET', Server::$url);
        $gotStats = null;
        $handler = new StreamHandler();
        $promise = $handler($req, [
            'on_headers' => static function (): void {
                throw new \RuntimeException('test');
            },
            'on_stats' => static function (TransferStats $stats) use (&$gotStats): void {
                $gotStats = $stats;
            },
        ]);

        try {
            $promise->wait();
            self::fail('Expected RequestException');
        } catch (RequestException $e) {
            self::assertStringContainsString('An error was encountered during the on_headers event', $e->getMessage());
            self::assertNotInstanceOf(ResponseTransferException::class, $e);
            self::assertNotInstanceOf(NetworkExceptionInterface::class, $e);
            self::assertInstanceOf(TransferStats::class, $gotStats);
            self::assertTrue($gotStats->hasResponse());
            self::assertSame(200, $gotStats->getResponse()->getStatusCode());
            self::assertSame($e, $gotStats->getHandlerErrorData());
        }
    }

    public function testSuccessfullyCallsOnHeadersBeforeWritingToSink(): void
    {
        Server::flush();
        Server::enqueue([
            new Response(200, ['X-Foo' => 'bar'], 'abc 123'),
        ]);
        $req = new Request('GET', Server::$url);
        $got = null;
        $gotRequest = null;

        $stream = Psr7\Utils::streamFor();
        $stream = FnStream::decorate($stream, [
            'write' => static function (string $data) use ($stream, &$got): int {
                self::assertNotNull($got);

                return $stream->write($data);
            },
        ]);

        $handler = new StreamHandler();
        $promise = $handler($req, [
            'sink' => $stream,
            'on_headers' => static function (
                ResponseInterface $res,
                RequestInterface $request
            ) use (&$got, &$gotRequest, $req): void {
                $got = $res;
                $gotRequest = $request;
                self::assertSame($req, $request);
                self::assertSame('bar', $res->getHeaderLine('X-Foo'));
            },
        ]);

        $response = $promise->wait();
        self::assertSame($req, $gotRequest);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('bar', $response->getHeaderLine('X-Foo'));
        self::assertSame('abc 123', (string) $response->getBody());
    }

    public function testThrowsResponseExceptionWhenSinkWriteTimesOut(): void
    {
        $this->queueRes();
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url);
        $stats = null;
        $exception = null;
        $writeCalled = false;
        $previous = new Psr7\Exception\TimeoutException('Unable to write to stream: timed out');
        $sink = FnStream::decorate(Psr7\Utils::streamFor(), [
            'write' => static function (string $data) use (&$writeCalled, $previous): int {
                $writeCalled = true;

                throw $previous;
            },
        ]);

        try {
            $handler(
                $request,
                [
                    'sink' => $sink,
                    'on_stats' => static function (TransferStats $transferStats) use (&$stats): void {
                        $stats = $transferStats;
                    },
                ]
            )->wait();

            self::fail('Expected ResponseException');
        } catch (ResponseException $e) {
            $exception = $e;
            self::assertSame($request, $e->getRequest());
            self::assertSame(200, $e->getResponse()->getStatusCode());
            self::assertSame('Timed out while writing the response body', $e->getMessage());
            self::assertSame($previous, $e->getPrevious());
            self::assertNotInstanceOf(ResponseTimeoutException::class, $e);
            self::assertNotInstanceOf(ResponseTransferException::class, $e);
            self::assertNotInstanceOf(NetworkExceptionInterface::class, $e);
        }

        self::assertTrue($writeCalled);
        self::assertInstanceOf(TransferStats::class, $stats);
        self::assertTrue($stats->hasResponse());
        self::assertSame($exception->getResponse(), $stats->getResponse());
        self::assertSame($exception, $stats->getHandlerErrorData());
    }

    public function testThrowsResponseExceptionWhenSinkWriteFails(): void
    {
        $this->queueRes();
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url);
        $stats = null;
        $exception = null;
        $previous = new \RuntimeException('sink failed');
        $sink = FnStream::decorate(Psr7\Utils::streamFor(), [
            'write' => static function (string $data) use ($previous): int {
                throw $previous;
            },
        ]);

        try {
            $handler(
                $request,
                [
                    'sink' => $sink,
                    'on_stats' => static function (TransferStats $transferStats) use (&$stats): void {
                        $stats = $transferStats;
                    },
                ]
            )->wait();

            self::fail('Expected ResponseException');
        } catch (ResponseException $e) {
            $exception = $e;
            self::assertNotInstanceOf(ResponseTransferException::class, $e);
            self::assertNotInstanceOf(NetworkExceptionInterface::class, $e);
            self::assertSame($request, $e->getRequest());
            self::assertSame(200, $e->getResponse()->getStatusCode());
            self::assertSame($previous, $e->getPrevious());
        }

        self::assertInstanceOf(TransferStats::class, $stats);
        self::assertTrue($stats->hasResponse());
        self::assertSame($exception->getResponse(), $stats->getResponse());
        self::assertSame($exception, $stats->getHandlerErrorData());
    }

    public function testThrowsResponseTransferExceptionWhenResponseBodyReadFails(): void
    {
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url);
        $previous = new \RuntimeException('SSL: Connection reset by peer');
        $stats = null;
        $source = FnStream::decorate(Psr7\Utils::streamFor('abc'), [
            'read' => static function (int $length) use ($previous): string {
                throw $previous;
            },
        ]);

        $this->setStreamHandlerLastHeaders($handler, [
            'HTTP/1.1 200 OK',
            'Content-Length: 3',
        ]);

        $promise = $this->invokeStreamHandlerCreateResponse(
            $handler,
            $request,
            [
                'on_stats' => static function (TransferStats $transferStats) use (&$stats): void {
                    $stats = $transferStats;
                },
            ],
            $source
        );

        try {
            $promise->wait();

            self::fail('Expected ResponseTransferException');
        } catch (ResponseTransferException $e) {
            self::assertSame($request, $e->getRequest());
            self::assertSame(200, $e->getResponse()->getStatusCode());
            self::assertSame($previous, $e->getPrevious());
            self::assertNotInstanceOf(NetworkExceptionInterface::class, $e);
            self::assertInstanceOf(TransferStats::class, $stats);
            self::assertTrue($stats->hasResponse());
            self::assertSame($e->getResponse(), $stats->getResponse());
            self::assertSame($e, $stats->getHandlerErrorData());
        }
    }

    public function testKeepsSinkWriteFailureAsResponseExceptionWhenUnderlyingSinkReportsTimedOut(): void
    {
        $this->queueRes();
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url);
        $previous = new \RuntimeException('sink failed');
        $underlying = Psr7\Utils::streamFor();
        $sink = FnStream::decorate($underlying, [
            'write' => static function (string $data) use ($previous): int {
                throw $previous;
            },
            'getMetadata' => static function (?string $key = null) use ($underlying) {
                if ($key === 'timed_out') {
                    return true;
                }

                return $underlying->getMetadata($key);
            },
        ]);

        try {
            $handler($request, ['sink' => $sink])->wait();
            self::fail('Expected ResponseException');
        } catch (ResponseException $e) {
            self::assertSame($request, $e->getRequest());
            self::assertSame(200, $e->getResponse()->getStatusCode());
            self::assertSame('sink failed', $e->getMessage());
            self::assertSame($previous, $e->getPrevious());
            self::assertNotInstanceOf(ResponseTimeoutException::class, $e);
            self::assertNotInstanceOf(ResponseTransferException::class, $e);
            self::assertNotInstanceOf(NetworkExceptionInterface::class, $e);
        }
    }

    public function testThrowsResponseExceptionWhenSinkWriteReturnsZero(): void
    {
        $this->queueRes();
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url);
        $sink = FnStream::decorate(Psr7\Utils::streamFor(), [
            'write' => static function (string $data): int {
                return 0;
            },
        ]);

        try {
            $handler($request, ['sink' => $sink])->wait();
            self::fail('Expected ResponseException');
        } catch (ResponseException $e) {
            self::assertSame($request, $e->getRequest());
            self::assertSame(200, $e->getResponse()->getStatusCode());
            self::assertSame('Unable to write to stream', $e->getMessage());
            self::assertNotInstanceOf(ResponseTimeoutException::class, $e);
            self::assertNotInstanceOf(ResponseTransferException::class, $e);
            self::assertNotInstanceOf(NetworkExceptionInterface::class, $e);
        }
    }

    public function testUsesFallbackMessageWhenResponseBodyReadFailsWithEmptyMessage(): void
    {
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url);
        $previous = new \RuntimeException('');
        $source = FnStream::decorate(Psr7\Utils::streamFor('abc'), [
            'read' => static function (int $length) use ($previous): string {
                throw $previous;
            },
        ]);

        $this->setStreamHandlerLastHeaders($handler, [
            'HTTP/1.1 200 OK',
            'Content-Length: 3',
        ]);

        $promise = $this->invokeStreamHandlerCreateResponse($handler, $request, [], $source);

        try {
            $promise->wait();
            self::fail('Expected ResponseTransferException');
        } catch (ResponseTransferException $e) {
            self::assertSame($request, $e->getRequest());
            self::assertSame(200, $e->getResponse()->getStatusCode());
            self::assertSame('Failed while transferring the response body', $e->getMessage());
            self::assertSame($previous, $e->getPrevious());
        }
    }

    public function testUsesFallbackMessageWhenSinkWriteFailsWithEmptyMessage(): void
    {
        $this->queueRes();
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url);
        $previous = new \RuntimeException('');
        $sink = FnStream::decorate(Psr7\Utils::streamFor(), [
            'write' => static function (string $data) use ($previous): int {
                throw $previous;
            },
        ]);

        try {
            $handler($request, ['sink' => $sink])->wait();
            self::fail('Expected ResponseException');
        } catch (ResponseException $e) {
            self::assertSame('Failed to write the response body', $e->getMessage());
            self::assertSame($previous, $e->getPrevious());
            self::assertNotInstanceOf(ResponseTransferException::class, $e);
            self::assertNotInstanceOf(ResponseTimeoutException::class, $e);
        }
    }

    public function testSurfacesSeekableSinkRewindFailureAsResponseException(): void
    {
        $this->queueRes();
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url);
        $previous = new \RuntimeException('rewind failed');
        $exception = null;
        $stats = null;
        $sink = FnStream::decorate(Psr7\Utils::streamFor(), [
            'rewind' => static function () use ($previous): void {
                throw $previous;
            },
        ]);

        try {
            $handler($request, [
                'sink' => $sink,
                'on_stats' => static function (TransferStats $transferStats) use (&$stats): void {
                    $stats = $transferStats;
                },
            ])->wait();
            self::fail('Expected ResponseException');
        } catch (ResponseException $e) {
            $exception = $e;
            self::assertSame($request, $e->getRequest());
            self::assertSame(200, $e->getResponse()->getStatusCode());
            self::assertSame($previous, $e->getPrevious());
            self::assertNotInstanceOf(ResponseTransferException::class, $e);
            self::assertNotInstanceOf(ResponseTimeoutException::class, $e);
            self::assertNotInstanceOf(NetworkExceptionInterface::class, $e);
        }

        self::assertInstanceOf(ResponseException::class, $exception);
        self::assertInstanceOf(TransferStats::class, $stats);
        self::assertTrue($stats->hasResponse());
        self::assertSame($exception->getResponse(), $stats->getResponse());
        self::assertSame($exception, $stats->getHandlerErrorData());
    }

    public function testNonSeekableSinkSucceedsWithoutRewind(): void
    {
        $this->queueRes();
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url);
        $underlying = Psr7\Utils::streamFor();
        $stats = null;
        $statsCalled = 0;
        $rewindCalled = false;
        $seekCalled = false;
        $sink = FnStream::decorate($underlying, [
            'isSeekable' => static function (): bool {
                return false;
            },
            'rewind' => static function () use (&$rewindCalled): void {
                $rewindCalled = true;

                throw new \RuntimeException('must not rewind a non-seekable sink');
            },
            'seek' => static function ($offset, $whence = \SEEK_SET) use (&$seekCalled): void {
                $seekCalled = true;

                throw new \RuntimeException('must not seek a non-seekable sink');
            },
        ]);

        $response = $handler($request, [
            'sink' => $sink,
            'on_stats' => static function (TransferStats $transferStats) use (&$stats, &$statsCalled): void {
                ++$statsCalled;
                $stats = $transferStats;
            },
        ])->wait();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($sink, $response->getBody());
        self::assertFalse($rewindCalled);
        self::assertFalse($seekCalled);
        $underlying->rewind();
        self::assertSame('hi there', $underlying->getContents());
        self::assertSame(1, $statsCalled);
        self::assertInstanceOf(TransferStats::class, $stats);
        self::assertTrue($stats->hasResponse());
        self::assertSame($response, $stats->getResponse());
        self::assertNull($stats->getHandlerErrorData());
    }

    public function testIgnoresSourceCloseFailureAfterCompleteBody(): void
    {
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url);
        $closeCalled = false;
        $throwOnClose = true;
        $source = FnStream::decorate(Psr7\Utils::streamFor('abc'), [
            'close' => static function () use (&$closeCalled, &$throwOnClose): void {
                $closeCalled = true;

                if ($throwOnClose) {
                    $throwOnClose = false;

                    throw new \RuntimeException('close failed');
                }
            },
        ]);
        $this->setStreamHandlerLastHeaders($handler, [
            'HTTP/1.1 200 OK',
            'Content-Length: 3',
        ]);

        $response = $this->invokeStreamHandlerCreateResponse($handler, $request, [], $source)->wait();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('abc', (string) $response->getBody());
        self::assertTrue($closeCalled);
    }

    public function testAttemptsSourceCloseWhenSinkRewindFails(): void
    {
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url);
        $rewindFailure = new \RuntimeException('rewind failed');
        $closeFailure = new \RuntimeException('close failed');
        $closeCalled = false;
        $throwOnClose = true;
        $source = FnStream::decorate(Psr7\Utils::streamFor('abc'), [
            'close' => static function () use (&$closeCalled, &$throwOnClose, $closeFailure): void {
                $closeCalled = true;

                if ($throwOnClose) {
                    $throwOnClose = false;

                    throw $closeFailure;
                }
            },
        ]);
        $sink = FnStream::decorate(Psr7\Utils::streamFor(), [
            'rewind' => static function () use ($rewindFailure): void {
                throw $rewindFailure;
            },
        ]);
        $this->setStreamHandlerLastHeaders($handler, [
            'HTTP/1.1 200 OK',
            'Content-Length: 3',
        ]);

        try {
            $this->invokeStreamHandlerCreateResponse($handler, $request, ['sink' => $sink], $source)->wait();
            self::fail('Expected ResponseException');
        } catch (ResponseException $e) {
            self::assertSame($rewindFailure, $e->getPrevious());
            self::assertNotSame($closeFailure, $e->getPrevious());
            self::assertNotInstanceOf(ResponseTransferException::class, $e);
        }

        self::assertTrue($closeCalled);
    }

    public function testInvokesOnStatsOnSuccess(): void
    {
        Server::flush();
        Server::enqueue([new Response(200)]);
        $req = new Request('GET', Server::$url);
        $gotStats = null;
        $handler = new StreamHandler();
        $promise = $handler($req, [
            'on_stats' => static function (TransferStats $stats) use (&$gotStats): void {
                $gotStats = $stats;
            },
        ]);
        $response = $promise->wait();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(200, $gotStats->getResponse()->getStatusCode());
        self::assertSame(
            Server::$url,
            (string) $gotStats->getEffectiveUri()
        );
        self::assertSame(
            Server::$url,
            (string) $gotStats->getRequest()->getUri()
        );
        self::assertGreaterThan(0, $gotStats->getTransferTime());
    }

    public function testOnStatsExceptionEscapesOnSuccessWithoutWrapping(): void
    {
        Server::flush();
        Server::enqueue([new Response(200)]);
        $req = new Request('GET', Server::$url);
        $handler = new StreamHandler();
        $previous = new \RuntimeException('stats failed');
        $called = 0;

        try {
            $handler($req, [
                'on_stats' => static function (TransferStats $stats) use (&$called, $previous): void {
                    ++$called;
                    self::assertTrue($stats->hasResponse());

                    throw $previous;
                },
            ]);

            self::fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            self::assertSame($previous, $e);
            self::assertSame(1, $called);
        }
    }

    public function testOnStatsExceptionEscapesWhenOnHeadersFails(): void
    {
        Server::flush();
        Server::enqueue([new Response(200, ['X-Foo' => 'bar'], 'abc 123')]);
        $req = new Request('GET', Server::$url);
        $handler = new StreamHandler();
        $previous = new \RuntimeException('stats failed');
        $called = 0;

        try {
            $handler($req, [
                'on_headers' => static function (): void {
                    throw new \RuntimeException('headers failed');
                },
                'on_stats' => static function (TransferStats $stats) use (&$called, $previous): void {
                    ++$called;
                    self::assertTrue($stats->hasResponse());

                    throw $previous;
                },
            ]);

            self::fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            self::assertSame($previous, $e);
            self::assertSame(1, $called);
        }
    }

    public function testInvokesOnStatsOnError(): void
    {
        $req = new Request('GET', 'http://127.0.0.1:123');
        $gotStats = null;
        $handler = new StreamHandler();
        $promise = $handler($req, [
            'connect_timeout' => 0.001,
            'timeout' => 0.001,
            'on_stats' => static function (TransferStats $stats) use (&$gotStats): void {
                $gotStats = $stats;
            },
        ]);
        $promise->wait(false);
        self::assertFalse($gotStats->hasResponse());
        self::assertSame(
            'http://127.0.0.1:123',
            (string) $gotStats->getEffectiveUri()
        );
        self::assertSame(
            'http://127.0.0.1:123',
            (string) $gotStats->getRequest()->getUri()
        );
        self::assertIsFloat($gotStats->getTransferTime());
        self::assertInstanceOf(
            ConnectException::class,
            $gotStats->getHandlerErrorData()
        );
    }

    public function testStreamIgnoresZeroTimeout(): void
    {
        Server::flush();
        Server::enqueue([new Response(200)]);
        $req = new Request('GET', Server::$url);
        $handler = new StreamHandler();
        $promise = $handler($req, [
            'connect_timeout' => 10,
            'timeout' => 0,
        ]);
        $response = $promise->wait();
        self::assertSame(200, $response->getStatusCode());
    }

    public function testStreamAcceptsDisabledTransportSharingOption(): void
    {
        Server::flush();
        Server::enqueue([new Response(200)]);

        $handler = new StreamHandler();
        $response = $handler(new Request('GET', Server::$url), [
            'transport_sharing' => TransportSharing::NONE,
        ])->wait();

        self::assertSame(200, $response->getStatusCode());
    }

    public function testStreamAcceptsNullTransportSharingOption(): void
    {
        Server::flush();
        Server::enqueue([new Response(200)]);

        $handler = new StreamHandler();
        $response = $handler(new Request('GET', Server::$url), [
            'transport_sharing' => null,
        ])->wait();

        self::assertSame(200, $response->getStatusCode());
    }

    public function testStreamAcceptsPreferredTransportSharingOption(): void
    {
        Server::flush();
        Server::enqueue([new Response(200), new Response(200)]);

        $handler = new StreamHandler();
        $response = $handler(new Request('GET', Server::$url), [
            'transport_sharing' => TransportSharing::HANDLER_PREFER,
        ])->wait();

        self::assertSame(200, $response->getStatusCode());

        $response = $handler(new Request('GET', Server::$url), [
            'transport_sharing' => TransportSharing::PERSISTENT_PREFER,
        ])->wait();

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * @dataProvider requiredTransportSharingModeProvider
     */
    public function testStreamRejectsRequiredTransportSharingOption(string $transportSharing): void
    {
        $handler = new StreamHandler();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('transport_sharing');

        $handler(new Request('GET', Server::$url), [
            'transport_sharing' => $transportSharing,
        ]);
    }

    public static function requiredTransportSharingModeProvider(): iterable
    {
        yield 'handler require' => [TransportSharing::HANDLER_REQUIRE];
        yield 'persistent require' => [TransportSharing::PERSISTENT_REQUIRE];
    }

    public function testStreamRejectsCurlOption(): void
    {
        $handler = new StreamHandler();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('curl');

        $handler(new Request('GET', Server::$url), [
            'curl' => [\CURLOPT_LOW_SPEED_LIMIT => 10],
        ]);
    }

    public function testStreamRejectsDigestAuth(): void
    {
        $handler = new StreamHandler();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Digest authentication');

        $handler(new Request('GET', Server::$url), [
            'auth' => ['user', 'pass', 'digest'],
        ]);
    }

    public function testStreamRejectsExpectOptionWhenHeaderIsPresent(): void
    {
        $handler = new StreamHandler();
        $request = new Request('PUT', Server::$url, ['Expect' => '100-Continue'], 'test');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('expect');

        $handler($request, [
            'expect' => true,
        ]);
    }

    public function testDrainsResponseAndReadsAllContentWhenContentLengthIsZero(): void
    {
        Server::flush();
        Server::enqueue([
            new Response(200, [
                'Foo' => 'Bar',
                'Content-Length' => '0',
            ], 'hi there... This has a lot of data!'),
        ]);
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url);
        $response = $handler($request, [])->wait();
        $body = $response->getBody();
        $stream = $body->detach();
        self::assertSame('hi there... This has a lot of data!', \stream_get_contents($stream));
        \fclose($stream);
    }

    public function testHonorsReadTimeout(): void
    {
        Server::flush();
        $handler = new StreamHandler();
        $response = $handler(
            new Request('GET', Server::$url.'guzzle-server/read-timeout'),
            [
                RequestOptions::READ_TIMEOUT => 1,
                RequestOptions::STREAM => true,
            ]
        )->wait();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('OK', $response->getReasonPhrase());
        $body = $response->getBody()->detach();
        $line = \fgets($body);
        self::assertSame("sleeping 60 seconds ...\n", $line);
        $line = \fgets($body);
        self::assertFalse($line);
        self::assertTrue(\stream_get_meta_data($body)['timed_out']);
        self::assertFalse(\feof($body));
    }

    public function testThrowsResponseTimeoutExceptionWhenDrainingResponseBodyTimesOut(): void
    {
        Server::flush();
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url.'guzzle-server/read-timeout');
        $stats = null;
        $exception = null;

        try {
            $handler(
                $request,
                [
                    RequestOptions::READ_TIMEOUT => 0.05,
                    'on_stats' => static function (TransferStats $transferStats) use (&$stats): void {
                        $stats = $transferStats;
                    },
                ]
            )->wait();
            self::fail('Expected ResponseTimeoutException');
        } catch (ResponseTimeoutException $e) {
            $exception = $e;
            self::assertSame($request, $e->getRequest());
            self::assertSame(200, $e->getResponse()->getStatusCode());
            self::assertSame('Timed out while transferring the response body', $e->getMessage());
            self::assertInstanceOf(Psr7\Exception\TimeoutException::class, $e->getPrevious());
            self::assertNotInstanceOf(NetworkExceptionInterface::class, $e);
        }

        self::assertInstanceOf(TransferStats::class, $stats);
        self::assertTrue($stats->hasResponse());
        self::assertSame($exception->getResponse(), $stats->getResponse());
        self::assertSame($exception, $stats->getHandlerErrorData());
    }

    public function testThrowsResponseTimeoutExceptionWhenDrainingGzipBodyTimesOut(): void
    {
        Server::flush();
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url.'guzzle-server/read-timeout-gzip');
        $stats = null;
        $exception = null;

        try {
            $handler(
                $request,
                [
                    'decode_content' => true,
                    RequestOptions::READ_TIMEOUT => 0.05,
                    'on_stats' => static function (TransferStats $transferStats) use (&$stats): void {
                        $stats = $transferStats;
                    },
                ]
            )->wait();
            self::fail('Expected ResponseTimeoutException');
        } catch (ResponseTimeoutException $e) {
            $exception = $e;
            self::assertSame($request, $e->getRequest());
            self::assertSame(200, $e->getResponse()->getStatusCode());
            self::assertSame('Timed out while transferring the response body', $e->getMessage());
            self::assertInstanceOf(Psr7\Exception\TimeoutException::class, $e->getPrevious());
            self::assertNotInstanceOf(NetworkExceptionInterface::class, $e);
        }

        self::assertInstanceOf(TransferStats::class, $stats);
        self::assertTrue($stats->hasResponse());
        self::assertSame($exception->getResponse(), $stats->getResponse());
        self::assertSame($exception, $stats->getHandlerErrorData());
    }

    public function testHandlesGarbageHttpServerGracefully(): void
    {
        $handler = new StreamHandler();

        try {
            $handler(
                new Request('GET', Server::$url.'guzzle-server/garbage'),
                [
                    RequestOptions::STREAM => true,
                ]
            )->wait();
            self::fail('Expected an exception');
        } catch (ConnectException $e) {
            self::assertMatchesRegularExpression('/refused/i', $e->getMessage());
        } catch (RequestException $e) {
            self::assertMatchesRegularExpression(
                '/HTTP invalid response format|An error was encountered while creating the response/',
                $e->getMessage()
            );
        }
    }

    public function testHandlesInvalidStatusCodeGracefully(): void
    {
        $handler = new StreamHandler();
        $called = false;
        $stats = null;

        try {
            $handler(
                new Request('GET', Server::$url.'guzzle-server/bad-status'),
                [
                    RequestOptions::STREAM => true,
                    'on_headers' => static function () use (&$called): void {
                        $called = true;
                    },
                    'on_stats' => static function (TransferStats $transferStats) use (&$stats): void {
                        $stats = $transferStats;
                    },
                ]
            )->wait();
            self::fail('Expected RequestException');
        } catch (RequestException $e) {
            self::assertStringContainsString(
                'An error was encountered while creating the response',
                $e->getMessage()
            );
            self::assertFalse($called);
            self::assertNotInstanceOf(ResponseException::class, $e);
            self::assertInstanceOf(\RuntimeException::class, $e->getPrevious());
            self::assertInstanceOf(TransferStats::class, $stats);
            self::assertFalse($stats->hasResponse());
            self::assertNull($stats->getResponse());
            self::assertSame($e, $stats->getHandlerErrorData());
        }
    }

    public function testRejectsNonHttpSchemes(): void
    {
        $handler = new StreamHandler();

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage("The scheme 'file' is not supported.");

        $handler(
            new Request('GET', 'file:///etc/passwd'),
            [
                RequestOptions::STREAM => true,
            ]
        )->wait();
    }

    private static function requestWithProtocolVersion(string $protocolVersion): RequestInterface
    {
        return new class($protocolVersion) extends Request {
            /** @var string */
            private $protocolVersion;

            public function __construct(string $protocolVersion)
            {
                parent::__construct('GET', Server::$url);

                $this->protocolVersion = $protocolVersion;
            }

            public function getProtocolVersion(): string
            {
                return $this->protocolVersion;
            }

            public function withProtocolVersion(string $version): MessageInterface
            {
                if ($this->protocolVersion === $version) {
                    return $this;
                }

                $new = clone $this;
                $new->protocolVersion = $version;

                return $new;
            }
        };
    }

    private static function assertResponseContentLengthPlatformException(ResponseException $e): void
    {
        self::assertNotInstanceOf(ResponseTransferException::class, $e);
        self::assertSame('Content-Length exceeds the maximum integer size supported on this platform', $e->getMessage());
        self::assertInstanceOf(\OverflowException::class, $e->getPrevious());
    }

    /**
     * @param list<string> $headers
     */
    private function setStreamHandlerLastHeaders(StreamHandler $handler, array $headers): void
    {
        $property = new \ReflectionProperty(StreamHandler::class, 'lastHeaders');
        if (\PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }

        $property->setValue($handler, $headers);
    }

    /**
     * @param resource|StreamInterface $stream
     */
    private function invokeStreamHandlerCreateResponse(StreamHandler $handler, RequestInterface $request, array $options, $stream)
    {
        $method = new \ReflectionMethod(StreamHandler::class, 'createResponse');
        if (\PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }

        return $method->invoke($handler, $request, $options, $stream, Utils::currentTime());
    }

    public function testProtocolsOptionRejectsDisallowedStreamScheme(): void
    {
        $handler = new StreamHandler();

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('not allowed by the protocols request option');

        $handler(
            new Request('GET', Server::$url),
            [
                RequestOptions::STREAM => true,
                RequestOptions::PROTOCOLS => ['https'],
            ]
        )->wait();
    }
}
