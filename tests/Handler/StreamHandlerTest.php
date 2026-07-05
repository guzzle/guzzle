<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests\Handler;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\InvalidArgumentException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\Exception\ResponseTimeoutException;
use GuzzleHttp\Exception\ResponseTransferException;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\Handler\TransferByteCounter;
use GuzzleHttp\Multiplexing;
use GuzzleHttp\ProxyOptions;
use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\Server\Server;
use GuzzleHttp\Tests\Psr17SpyFactory;
use GuzzleHttp\Tests\SpyResponse;
use GuzzleHttp\Tests\SpyStream;
use GuzzleHttp\Tests\StrictReadableResourceStreamFactory;
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

    public function testRejectsUnknownConstructorOption(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid StreamHandler constructor option "unknown".');

        new StreamHandler(['unknown' => true]);
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

    /**
     * @dataProvider forceIpResolveIpLiteralProvider
     */
    public function testResolveHostDoesNotResolveIpLiterals(string $host, string $forceIpResolve): void
    {
        $handler = new StreamHandler();
        $request = new Request('GET', 'http://'.$host.'/');

        $method = new \ReflectionMethod(StreamHandler::class, 'resolveHost');
        if (\PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }

        $uri = $method->invoke($handler, $request, ['force_ip_resolve' => $forceIpResolve]);

        self::assertSame($host, $uri->getHost());
    }

    public static function forceIpResolveIpLiteralProvider(): array
    {
        return [
            ['[::1]', 'v4'],
            ['[::1]', 'v6'],
            ['[2001:db8::1]', 'v4'],
            ['[2001:db8::1]', 'v6'],
            ['127.0.0.1', 'v4'],
            ['127.0.0.1', 'v6'],
        ];
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

    /**
     * @dataProvider requiredMultiplexProvider
     */
    public function testRejectsRequiredMultiplex(string $multiplex): void
    {
        $handler = new StreamHandler();
        $request = new Request('GET', 'https://example.com', [], null, '2.0');

        try {
            $handler($request, ['multiplex' => $multiplex]);
            self::fail('Expected request exception.');
        } catch (RequestException $e) {
            self::assertSame($request, $e->getRequest());
            self::assertSame('The stream handler cannot guarantee a multiplexed protocol; required multiplexing needs a cURL handler.', $e->getMessage());
        }
    }

    public static function requiredMultiplexProvider(): iterable
    {
        yield 'require_eager' => [Multiplexing::REQUIRE_EAGER];
        yield 'require_wait' => [Multiplexing::REQUIRE_WAIT];
    }

    /**
     * @dataProvider invalidMultiplexProvider
     *
     * @param mixed $value
     */
    public function testRejectsInvalidMultiplexValues($value): void
    {
        $handler = new StreamHandler();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The "multiplex" option must be null or a GuzzleHttp\\Multiplexing::* constant');

        $handler(new Request('GET', Server::$url), ['multiplex' => $value]);
    }

    public static function invalidMultiplexProvider(): iterable
    {
        yield 'bool true' => [true];
        yield 'bool false' => [false];
        yield 'int' => [1];
        yield 'unknown string' => ['always'];
    }

    /**
     * @dataProvider hintMultiplexProvider
     */
    public function testIgnoresHintMultiplex(string $multiplex): void
    {
        $this->queueRes();
        $handler = new StreamHandler();

        $response = $handler(new Request('GET', Server::$url), ['multiplex' => $multiplex])->wait();

        self::assertSame(200, $response->getStatusCode());
    }

    public static function hintMultiplexProvider(): iterable
    {
        yield 'eager' => [Multiplexing::EAGER];
        yield 'wait' => [Multiplexing::WAIT];
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
        self::assertSame('8', $response->getHeaderLine('Content-Length'));
        $body = $response->getBody();
        $stream = $body->detach();
        self::assertIsResource($stream);
        self::assertNotSame('http', \stream_get_meta_data($stream)['wrapper_type']);
        self::assertSame('', \stream_get_contents($stream));
        \fclose($stream);
    }

    /**
     * @dataProvider noContentStatusProvider
     */
    public function testNoContentStatusWithContentLengthHasEmptyBody(int $status): void
    {
        Server::flush();
        Server::enqueue([new Response($status, ['Content-Length' => '8'], '')]);
        $handler = new StreamHandler();
        $response = $handler(new Request('GET', Server::$url), [])->wait();

        self::assertSame($status, $response->getStatusCode());
        self::assertSame('8', $response->getHeaderLine('Content-Length'));
        self::assertSame('', (string) $response->getBody());
    }

    public static function noContentStatusProvider(): array
    {
        return [
            '204 No Content' => [204],
            '304 Not Modified' => [304],
        ];
    }

    /**
     * @dataProvider noContentSinkProvider
     */
    public function testNoContentResponseDoesNotCreateStringSinkFile(string $method, int $status): void
    {
        Server::flush();
        Server::enqueue([new Response($status, ['Content-Length' => '8'], '')]);
        $tmpfname = \tempnam(\sys_get_temp_dir(), 'nocontent');
        self::assertIsString($tmpfname);
        \unlink($tmpfname);

        try {
            $handler = new StreamHandler();
            $response = $handler(new Request($method, Server::$url), ['sink' => $tmpfname])->wait();

            self::assertSame($status, $response->getStatusCode());
            self::assertSame('', (string) $response->getBody());
            self::assertFileDoesNotExist($tmpfname);
        } finally {
            if (\file_exists($tmpfname)) {
                \unlink($tmpfname);
            }
        }
    }

    public static function noContentSinkProvider(): array
    {
        return [
            'HEAD 200' => ['HEAD', 200],
            'GET 204' => ['GET', 204],
            'GET 304' => ['GET', 304],
        ];
    }

    /**
     * @dataProvider noContentStreamOptionProvider
     */
    public function testStreamOptionYieldsEmptyFactoryStreamForNoContentResponse(string $method, int $status): void
    {
        Server::flush();
        Server::enqueue([new Response($status, ['Content-Length' => '8'], '')]);
        $factory = new Psr17SpyFactory();
        $handler = new StreamHandler();
        $response = $handler(new Request($method, Server::$url), [
            RequestOptions::STREAM => true,
            RequestOptions::STREAM_FACTORY => $factory,
        ])->wait();

        self::assertSame($status, $response->getStatusCode());
        self::assertInstanceOf(SpyStream::class, $response->getBody());
        self::assertSame(1, $factory->createStreamCalls);
        self::assertSame('', (string) $response->getBody());
        $stream = $response->getBody()->detach();
        self::assertIsResource($stream);
        self::assertNotSame('http', \stream_get_meta_data($stream)['wrapper_type']);
        \fclose($stream);
    }

    public static function noContentStreamOptionProvider(): array
    {
        return [
            'HEAD 200' => ['HEAD', 200],
            'GET 204' => ['GET', 204],
            'GET 304' => ['GET', 304],
        ];
    }

    /**
     * @dataProvider noContentReadProvider
     *
     * @param string[] $headers
     */
    public function testNoContentResponseSkipsDrainAndClosesSourceUnread(string $method, array $headers, string $sourceBytes, int $status, string $contentLength): void
    {
        $handler = new StreamHandler();
        $request = new Request($method, Server::$url);

        $this->setStreamHandlerLastHeaders($handler, $headers);

        $read = false;
        $closed = false;
        $source = FnStream::decorate(Psr7\Utils::streamFor($sourceBytes), [
            'read' => static function (int $length) use (&$read): string {
                $read = true;

                return '';
            },
            'close' => static function () use (&$closed): void {
                $closed = true;
            },
        ]);

        $response = $this->invokeStreamHandlerCreateResponse($handler, $request, [], $source)->wait();

        self::assertSame($status, $response->getStatusCode());
        self::assertSame($contentLength, $response->getHeaderLine('Content-Length'));
        self::assertSame('', (string) $response->getBody());
        self::assertFalse($read);
        self::assertTrue($closed);
    }

    public static function noContentReadProvider(): array
    {
        return [
            'HEAD 200, rogue body' => ['HEAD', ['HTTP/1.1 200 OK', 'Content-Length: 9'], 'roguebody', 200, '9'],
            'GET 100' => ['GET', ['HTTP/1.1 100 Continue'], 'roguebody', 100, ''],
            'GET 101' => ['GET', ['HTTP/1.1 101 Switching Protocols'], 'roguebody', 101, ''],
            'GET 204' => ['GET', ['HTTP/1.1 204 No Content', 'Content-Length: 8'], 'roguebody', 204, '8'],
            'GET 304' => ['GET', ['HTTP/1.1 304 Not Modified', 'Content-Length: 8'], 'roguebody', 304, '8'],
            'CONNECT 200' => ['CONNECT', ['HTTP/1.1 200 OK', 'Content-Length: 3'], 'roguebody', 200, '3'],
            'GET 204, chunked, rogue bytes' => ['GET', ['HTTP/1.1 204 No Content', 'Transfer-Encoding: chunked'], "0\r\n\r\nrogue", 204, ''],
        ];
    }

    public function testIgnoresSourceCloseFailureForNoContentResponse(): void
    {
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url);

        $this->setStreamHandlerLastHeaders($handler, [
            'HTTP/1.1 204 No Content',
            'Content-Length: 8',
        ]);

        $closeCalled = false;
        $source = FnStream::decorate(Psr7\Utils::streamFor(''), [
            'close' => static function () use (&$closeCalled): void {
                if (!$closeCalled) {
                    $closeCalled = true;

                    throw new \RuntimeException('close failed');
                }
            },
        ]);

        $response = $this->invokeStreamHandlerCreateResponse($handler, $request, [], $source)->wait();

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
        self::assertTrue($closeCalled);
    }

    public function testRogueHeadResponseBodyBytesOverTheWireAreIgnored(): void
    {
        Server::flush();
        Server::enqueueRawBytes("HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\nhello");
        $handler = new StreamHandler();
        $response = $handler(new Request('HEAD', Server::$url), [])->wait();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('5', $response->getHeaderLine('Content-Length'));
        self::assertSame('', (string) $response->getBody());
        self::assertSame('HEAD', Server::received()[0]->getMethod());
    }

    public function testRogue204TrailingBytesAreNotReadIntoTheBody(): void
    {
        Server::flush();
        Server::enqueueRawBytes("HTTP/1.1 204 No Content\r\nContent-Length: 5\r\n\r\nhello");
        $handler = new StreamHandler();
        $response = $handler(new Request('GET', Server::$url), [])->wait();

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
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

    public function testZeroStringDecodeContentReportsOriginalSizeAndContentEncodingAfterDecoding(): void
    {
        $decoded = 'test';
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
        $response = $this->invokeStreamHandlerCreateResponse($handler, $request, ['decode_content' => '0'], $resource)->wait();

        self::assertSame($decoded, (string) $response->getBody());
        self::assertSame('gzip', $response->getHeaderLine('x-encoded-content-encoding'));
        self::assertSame((string) \strlen($gzip), $response->getHeaderLine('x-encoded-content-length'));
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

    private function buildHttpsTlsContext(string $uri, array $options): array
    {
        $handler = new StreamHandler();
        $request = new Request('GET', $uri);
        $context = ['ssl' => []];
        $params = [];

        $apply = new \ReflectionMethod(StreamHandler::class, 'applyHandlerOptions');
        $addDefault = new \ReflectionMethod(StreamHandler::class, 'addDefaultTlsMinimum');
        if (\PHP_VERSION_ID < 80100) {
            $apply->setAccessible(true);
            $addDefault->setAccessible(true);
        }

        $apply->invokeArgs($handler, [$request, &$context, $options, &$params]);
        $addDefault->invokeArgs($handler, [$request, &$context]);

        return $context;
    }

    private function assertTlsVersionRangeForOptions(string $uri, array $options): void
    {
        $handler = new StreamHandler();
        $request = new Request('GET', $uri);
        $method = new \ReflectionMethod(StreamHandler::class, 'assertTlsVersionRangeForOptions');
        if (\PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }

        $method->invoke($handler, $request, $options);
    }

    /**
     * @param mixed $value
     */
    private function applyProxy(string $uri, array $context, $value): array
    {
        $handler = new StreamHandler();
        $request = new Request('GET', $uri);
        $method = new \ReflectionMethod(StreamHandler::class, 'applyProxy');
        if (\PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }

        $method->invokeArgs($handler, [$request, &$context, $value]);

        return $context;
    }

    private static function skipIfWindows(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Environment variables are case-insensitive on Windows.');
        }
    }

    /**
     * Runs the callback with only the given proxy environment variables set,
     * restoring the process environment afterwards.
     *
     * @param array<string, string> $env
     */
    private static function withProxyEnvironment(array $env, callable $test): void
    {
        $names = ['http_proxy', 'HTTP_PROXY', 'https_proxy', 'HTTPS_PROXY', 'all_proxy', 'ALL_PROXY', 'no_proxy', 'NO_PROXY'];
        $previous = [];
        foreach ($names as $name) {
            $previous[$name] = \getenv($name, true);
            \putenv($name);
        }
        foreach ($env as $name => $value) {
            \putenv($name.'='.$value);
        }

        try {
            $test();
        } finally {
            foreach ($names as $name) {
                \putenv($name);
            }
            foreach ($previous as $name => $value) {
                if ($value !== false) {
                    \putenv($name.'='.$value);
                }
            }
        }
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

    public function testHonorsNoProxyWithoutSchemeSpecificProxy(): void
    {
        $res = $this->getSendResult(['proxy' => [
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
            [['no' => [new \stdClass()]]],
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

    public static function rejectedProxySchemeProvider(): array
    {
        $https = 'HTTPS proxies are not supported by the stream handler.';
        $socks = 'SOCKS proxies are not supported by the stream handler.';
        $generic = static function (string $scheme): string {
            return \sprintf('The "%s" proxy scheme is not supported by the stream handler.', $scheme);
        };

        return [
            ['https://proxy.example.com:3128', $https],
            ['HTTPS://proxy.example.com:3128', $https],
            ['socks4://proxy.example.com:1080', $socks],
            ['socks4a://proxy.example.com:1080', $socks],
            ['socks5://proxy.example.com:1080', $socks],
            ['socks5h://proxy.example.com:1080', $socks],
            [['http' => 'socks5://proxy.example.com:1080'], $socks],
            ['ftp://proxy.example.com:21', $generic('ftp')],
            ['ws://proxy.example.com:80', $generic('ws')],
            ['gopher://proxy.example.com:70', $generic('gopher')],
            ['socks6://proxy.example.com:1080', $generic('socks6')],
            ['htps://proxy.example.com:3128', $generic('htps')],
            ['tlsx://proxy.example.com:8125', $generic('tlsx')],
            ['tlsfoo://proxy.example.com:8125', $generic('tlsfoo')],
            ['udp://127.0.0.1:8125', $generic('udp')],
            [['http' => 'ftp://proxy.example.com:21'], $generic('ftp')],
        ];
    }

    /**
     * @dataProvider rejectedProxySchemeProvider
     *
     * @param string|array $proxy
     */
    public function testRejectsUnsupportedProxySchemes($proxy, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $context = [];
        $this->applyProxy('http://example.com', $context, $proxy);
    }

    public function testPassesRawTransportProxySchemesThrough(): void
    {
        foreach (['tcp://127.0.0.1:8125', 'ssl://127.0.0.1:8125', 'tls://127.0.0.1:8125'] as $proxy) {
            $context = [];
            $result = $this->applyProxy('http://example.com', $context, $proxy);

            self::assertSame($proxy, $result['http']['proxy']);
        }
    }

    public function testRejectsBuildUnavailableRawTransportProxyWithRequestException(): void
    {
        // A recognized TLS-family transport the build's stream_get_transports()
        // does not provide is build-specific, so it throws RequestException
        // rather than the InvalidArgumentException used for a scheme invalid
        // everywhere.
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('proxy transport is not available in this PHP build');

        $context = [];
        $this->applyProxy('http://example.com', $context, 'tlsv1.9://proxy.example.com:443');
    }

    public function testResolvesProxyFromEnvironmentWithoutProxyOption(): void
    {
        self::skipIfWindows();

        // With no proxy option, the closed-port http_proxy is used only if the
        // createStream hoist resolves it (refused); prime before setting it.
        $this->queueRes();

        self::withProxyEnvironment(['http_proxy' => 'http://127.0.0.1:8125'], function (): void {
            $handler = new StreamHandler();
            try {
                $handler(new Request('GET', Server::$url), [])->wait();
                self::fail('Expected a ConnectException for the environment proxy');
            } catch (ConnectException $e) {
                self::assertMatchesRegularExpression('/refused/i', $e->getMessage());
            }
        });
    }

    public function testResolvesLowercaseHttpProxyFromEnvironment(): void
    {
        self::skipIfWindows();

        self::withProxyEnvironment(['http_proxy' => 'http://env.example.com:8125'], function (): void {
            $context = $this->applyProxy('http://example.com', [], null);

            self::assertSame('tcp://env.example.com:8125', $context['http']['proxy']);
        });
    }

    public function testEnvironmentNoProxyWildcardDisablesProxy(): void
    {
        self::skipIfWindows();

        self::withProxyEnvironment([
            'http_proxy' => 'http://env.example.com:8125',
            'NO_PROXY' => '*',
        ], function (): void {
            self::assertSame([], $this->applyProxy('http://example.com', [], null));
        });
    }

    public function testExplicitProxyOptionBeatsEnvironmentProxy(): void
    {
        self::skipIfWindows();

        self::withProxyEnvironment(['http_proxy' => 'http://env.example.com:8125'], function (): void {
            $context = $this->applyProxy('http://example.com', [], 'http://option.example.com:8125');

            self::assertSame('tcp://option.example.com:8125', $context['http']['proxy']);
        });
    }

    public function testRejectsEnvironmentHttpsProxy(): void
    {
        self::skipIfWindows();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('HTTPS proxies are not supported by the stream handler.');

        self::withProxyEnvironment(['https_proxy' => 'https://env.example.com:8125'], function (): void {
            $this->applyProxy('https://example.com', [], null);
        });
    }

    public function testAddsProxyAuthorizationHeaderForEnvironmentProxyCredentials(): void
    {
        self::skipIfWindows();

        self::withProxyEnvironment(['http_proxy' => 'http://user:pass@env.example.com:8125'], function (): void {
            $context = $this->applyProxy('http://example.com', [], null);

            self::assertSame('tcp://env.example.com:8125', $context['http']['proxy']);
            self::assertStringContainsString(
                'Proxy-Authorization: Basic '.\base64_encode('user:pass'),
                $context['http']['header']
            );
        });
    }

    public static function malformedProxyUrlProvider(): array
    {
        return [
            ['http://exa mple.com:3128'],               // space in host
            ['127.0.0.1:99999999'],                     // scheme-less, port out of range
            [' https://proxy.example.com:3128'],        // leading space before the scheme
            ["\u{00A0}https://proxy.example.com:3128"], // leading non-breaking space
            ['socks5:127.0.0.1:1080'],                  // single-colon scheme-like, not an authority
            ['http:127.0.0.1:8125'],                    // single-colon scheme-like, not an authority
            ['//proxy.example.com:8125'],               // protocol-relative, not an authority
        ];
    }

    /**
     * @dataProvider malformedProxyUrlProvider
     */
    public function testRejectsMalformedProxyUrl(string $proxy): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid proxy URL');

        $context = [];
        $this->applyProxy('http://example.com', $context, $proxy);
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

    public function testSetsCryptoMethodMaxTls12(): void
    {
        $res = $this->getSendResult([
            'crypto_method_max' => \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
        ]);

        $opts = \stream_context_get_options($res->getBody()->detach());

        self::assertSame(\STREAM_CRYPTO_PROTO_TLSv1_2, $opts['ssl']['max_proto_version']);
    }

    public function testSetsCryptoMethodMaxTls13(): void
    {
        $res = $this->getSendResult([
            'crypto_method_max' => \STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
        ]);

        $opts = \stream_context_get_options($res->getBody()->detach());

        self::assertSame(\STREAM_CRYPTO_PROTO_TLSv1_3, $opts['ssl']['max_proto_version']);
    }

    public function testSetsCryptoMethodRangeTls10ToTls11(): void
    {
        $res = $this->getSendResult([
            'crypto_method' => \STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT,
            'crypto_method_max' => \STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT,
        ]);

        $opts = \stream_context_get_options($res->getBody()->detach());

        self::assertSame(\STREAM_CRYPTO_PROTO_TLSv1_0, $opts['ssl']['min_proto_version']);
        self::assertSame(\STREAM_CRYPTO_PROTO_TLSv1_1, $opts['ssl']['max_proto_version']);
    }

    public function testRejectsInvertedCryptoMethodRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('crypto_method_max');

        $this->getSendResult([
            'crypto_method' => \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
            'crypto_method_max' => \STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT,
        ]);
    }

    public function testCryptoMethodMaxTls12KeepsDefaultHttpsTls12Minimum(): void
    {
        $context = $this->applyDefaultTlsMinimum('https://example.com', [
            'ssl' => ['max_proto_version' => \STREAM_CRYPTO_PROTO_TLSv1_2],
        ]);

        self::assertSame(\STREAM_CRYPTO_PROTO_TLSv1_2, $context['ssl']['min_proto_version']);
        self::assertSame(\STREAM_CRYPTO_PROTO_TLSv1_2, $context['ssl']['max_proto_version']);
    }

    public function testHttpsCryptoMethodMaxTls12RequestOptionKeepsDefaultMinimum(): void
    {
        $context = $this->buildHttpsTlsContext('https://example.com', [
            'crypto_method_max' => \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
        ]);

        self::assertSame(\STREAM_CRYPTO_PROTO_TLSv1_2, $context['ssl']['min_proto_version']);
        self::assertSame(\STREAM_CRYPTO_PROTO_TLSv1_2, $context['ssl']['max_proto_version']);
    }

    public function testRejectsHttpsCryptoMethodMaxBelowDefaultMinimum(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('crypto_method_max');

        $this->assertTlsVersionRangeForOptions('https://example.com', [
            'crypto_method_max' => \STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT,
        ]);
    }

    /**
     * @dataProvider conflictingStreamContextProvider
     *
     * @param mixed $value
     */
    public function testRejectsConflictingStreamContextOptions(string $wrapper, string $option, $value, ?string $replacement): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('stream_context.'.$wrapper.'.'.$option);
        $this->expectExceptionMessage('conflicts with Guzzle-managed');
        if ($replacement !== null) {
            $this->expectExceptionMessage($replacement);
        }

        $this->getSendResult([
            'stream_context' => [
                $wrapper => [$option => $value],
            ],
        ]);
    }

    public static function conflictingStreamContextProvider(): iterable
    {
        yield 'http content' => ['http', 'content', 'body', 'request body'];
        yield 'http follow location' => ['http', 'follow_location', 1, 'allow_redirects'];
        yield 'http header' => ['http', 'header', 'X-Test: 1', 'request headers'];
        yield 'http max redirects' => ['http', 'max_redirects', 5, 'allow_redirects'];
        yield 'http method' => ['http', 'method', 'POST', 'request method'];
        yield 'http protocol version' => ['http', 'protocol_version', '1.0', 'request protocol version'];
        yield 'http proxy' => ['http', 'proxy', 'tcp://proxy.example.com:8125', 'proxy'];
        yield 'http timeout' => ['http', 'timeout', 1, 'timeout'];
        yield 'ssl allow self signed' => ['ssl', 'allow_self_signed', true, 'verify'];
        yield 'ssl cafile' => ['ssl', 'cafile', __FILE__, 'verify'];
        yield 'ssl capath' => ['ssl', 'capath', __DIR__, 'verify'];
        yield 'ssl crypto method' => ['ssl', 'crypto_method', \STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT, 'crypto_method'];
        yield 'ssl local cert' => ['ssl', 'local_cert', __FILE__, 'cert'];
        yield 'ssl local pk' => ['ssl', 'local_pk', __FILE__, 'ssl_key'];
        yield 'ssl max protocol version' => ['ssl', 'max_proto_version', \STREAM_CRYPTO_PROTO_TLSv1_2, 'crypto_method_max'];
        yield 'ssl min protocol version' => ['ssl', 'min_proto_version', \STREAM_CRYPTO_PROTO_TLSv1_0, 'crypto_method'];
        yield 'ssl passphrase' => ['ssl', 'passphrase', 'secret', 'cert'];
        yield 'ssl peer name' => ['ssl', 'peer_name', 'example.com', 'request URI'];
        yield 'ssl verify peer' => ['ssl', 'verify_peer', false, 'verify'];
        yield 'ssl verify peer name' => ['ssl', 'verify_peer_name', false, 'verify'];
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
                ],
                'socket' => [
                    'bindto' => '127.0.0.1:0',
                ],
                'ssl' => [
                    'ciphers' => 'DEFAULT',
                ],
            ],
        ]);
        $opts = \stream_context_get_options($res->getBody()->detach());
        self::assertTrue($opts['http']['request_fulluri']);
        self::assertSame('127.0.0.1:0', $opts['socket']['bindto']);
        self::assertSame('DEFAULT', $opts['ssl']['ciphers']);
    }

    public function testRejectsUnsupportedStreamContextOptions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('stream_context.http.unknown_option');
        $this->expectExceptionMessage('stream_context.http.ignore_errors');
        $this->expectExceptionMessage('stream_context.ssl.SNI_server_name');
        $this->expectExceptionMessage('stream_context.custom.foo');

        $this->getSendResult([
            'stream_context' => [
                'http' => [
                    'ignore_errors' => true,
                    'unknown_option' => true,
                ],
                'ssl' => [
                    'SNI_server_name' => 'example.com',
                ],
                'custom' => [
                    'foo' => true,
                ],
            ],
        ]);
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
        $previous = new \Exception('sink failed');
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

    public function testSinkWriteErrorPropagates(): void
    {
        $this->queueRes();
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url);
        $previous = new \Error('sink bug');
        $sink = FnStream::decorate(Psr7\Utils::streamFor(), [
            'write' => static function (string $data) use ($previous): int {
                throw $previous;
            },
        ]);

        try {
            $handler($request, ['sink' => $sink]);
            self::fail('Expected Error');
        } catch (\Error $e) {
            self::assertSame($previous, $e);
        }
    }

    public function testThrowsResponseTransferExceptionWhenResponseBodyReadFails(): void
    {
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url);
        $previous = new \Exception('SSL: Connection reset by peer');
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

    public function testResponseBodyReadErrorPropagates(): void
    {
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url);
        $previous = new \Error('source bug');
        $source = FnStream::decorate(Psr7\Utils::streamFor('abc'), [
            'read' => static function (int $length) use ($previous): string {
                throw $previous;
            },
        ]);

        $this->setStreamHandlerLastHeaders($handler, [
            'HTTP/1.1 200 OK',
            'Content-Length: 3',
        ]);

        try {
            $this->invokeStreamHandlerCreateResponse($handler, $request, [], $source);
            self::fail('Expected Error');
        } catch (\Error $e) {
            self::assertSame($previous, $e);
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
        $previous = new \Exception('rewind failed');
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

    public function testSeekableSinkRewindErrorPropagates(): void
    {
        $this->queueRes();
        $handler = new StreamHandler();
        $request = new Request('GET', Server::$url);
        $previous = new \Error('rewind bug');
        $sink = FnStream::decorate(Psr7\Utils::streamFor(), [
            'rewind' => static function () use ($previous): void {
                throw $previous;
            },
        ]);

        try {
            $handler($request, ['sink' => $sink]);
            self::fail('Expected Error');
        } catch (\Error $e) {
            self::assertSame($previous, $e);
        }
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

    public function testStreamAcceptsDisabledTransportSharingConstructorOption(): void
    {
        Server::flush();
        Server::enqueue([new Response(200)]);

        $handler = new StreamHandler(['transport_sharing' => TransportSharing::NONE]);
        $response = $handler(new Request('GET', Server::$url), [])->wait();

        self::assertSame(200, $response->getStatusCode());
    }

    public function testStreamAcceptsNullTransportSharingConstructorOption(): void
    {
        Server::flush();
        Server::enqueue([new Response(200)]);

        $handler = new StreamHandler(['transport_sharing' => null]);
        $response = $handler(new Request('GET', Server::$url), [])->wait();

        self::assertSame(200, $response->getStatusCode());
    }

    public function testStreamAcceptsPreferredTransportSharingConstructorOption(): void
    {
        Server::flush();
        Server::enqueue([new Response(200), new Response(200)]);

        $handler = new StreamHandler(['transport_sharing' => TransportSharing::HANDLER_PREFER]);
        $response = $handler(new Request('GET', Server::$url), [])->wait();

        self::assertSame(200, $response->getStatusCode());

        $handler = new StreamHandler(['transport_sharing' => TransportSharing::PERSISTENT_PREFER]);
        $response = $handler(new Request('GET', Server::$url), [])->wait();

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * @dataProvider requiredTransportSharingModeProvider
     */
    public function testStreamRejectsRequiredTransportSharingConstructorOption(string $transportSharing): void
    {
        $handler = new StreamHandler(['transport_sharing' => $transportSharing]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('transport_sharing');

        $handler(new Request('GET', Server::$url), []);
    }

    public static function requiredTransportSharingModeProvider(): iterable
    {
        yield 'handler require' => [TransportSharing::HANDLER_REQUIRE];
        yield 'persistent require' => [TransportSharing::PERSISTENT_REQUIRE];
    }

    /**
     * @dataProvider requestTransportSharingOptionProvider
     *
     * @param mixed $transportSharing
     */
    public function testStreamIgnoresRequestLevelTransportSharingOption($transportSharing): void
    {
        Server::flush();
        Server::enqueue([new Response(200)]);

        $handler = new StreamHandler();
        $response = $handler(new Request('GET', Server::$url), [
            'transport_sharing' => $transportSharing,
        ])->wait();

        self::assertSame(200, $response->getStatusCode());
    }

    public static function requestTransportSharingOptionProvider(): iterable
    {
        yield 'null' => [null];
        yield 'none' => [TransportSharing::NONE];
        yield 'handler prefer' => [TransportSharing::HANDLER_PREFER];
        yield 'handler require' => [TransportSharing::HANDLER_REQUIRE];
        yield 'persistent prefer' => [TransportSharing::PERSISTENT_PREFER];
        yield 'persistent require' => [TransportSharing::PERSISTENT_REQUIRE];
        yield 'invalid' => ['invalid'];
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

    public function testStreamHandlerDoesNotRejectDigestAuthOption(): void
    {
        Server::flush();
        Server::enqueue([new Response(200)]);

        $handler = new StreamHandler();

        $response = $handler(new Request('GET', Server::$url), [
            'auth' => ['user', 'pass', 'digest'],
        ])->wait();

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse(Server::received()[0]->hasHeader('Authorization'));
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

    /**
     * @dataProvider uriMissingSchemeOrHostProvider
     */
    public function testRejectsRequestUriMissingSchemeOrHost(string $uri): void
    {
        $handler = new StreamHandler();

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('URI must include a scheme and host');

        $handler(
            new Request('GET', $uri),
            [
                RequestOptions::STREAM => true,
            ]
        )->wait();
    }

    public static function uriMissingSchemeOrHostProvider(): iterable
    {
        yield 'relative path' => ['baz'];
        yield 'host-like relative path' => ['gstatic.com/generate_204'];
        yield 'path starting with colon-slash-slash' => ['://gstatic.com/generate_204'];
        yield 'absolute path' => ['/generate_204'];
        yield 'scheme without host' => ['https:/generate_204'];
    }

    public function testResponseMessageIsBuiltViaResponseFactory(): void
    {
        $handler = new StreamHandler();
        $request = new Request('GET', 'http://example.com');
        $factory = new Psr17SpyFactory();

        $this->setStreamHandlerLastHeaders($handler, [
            'HTTP/1.1 201 Created',
            'Foo: Bar',
        ]);

        /** @var ResponseInterface $response */
        $response = $this->invokeStreamHandlerCreateResponse(
            $handler,
            $request,
            [RequestOptions::RESPONSE_FACTORY => $factory],
            Psr7\Utils::streamFor('body')
        )->wait();

        self::assertInstanceOf(SpyResponse::class, $response);
        self::assertSame(1, $factory->createResponseCalls);
        self::assertSame(201, $response->getStatusCode());
        self::assertSame('Created', $response->getReasonPhrase());
        self::assertSame('Bar', $response->getHeaderLine('Foo'));
        self::assertSame('1.1', $response->getProtocolVersion());
        self::assertSame('body', (string) $response->getBody());
    }

    public function testResponsePreservesMixedCaseDuplicateHeaders(): void
    {
        $handler = new StreamHandler();
        $request = new Request('GET', 'http://example.com');

        // Different-case duplicates are kept as separate keys by parseHeaders;
        // the response must merge them (withAddedHeader), not drop one.
        $this->setStreamHandlerLastHeaders($handler, [
            'HTTP/1.1 200 OK',
            'Set-Cookie: a=1',
            'set-cookie: b=2',
        ]);

        /** @var ResponseInterface $response */
        $response = $this->invokeStreamHandlerCreateResponse($handler, $request, [], Psr7\Utils::streamFor(''))->wait();

        self::assertSame(['a=1', 'b=2'], $response->getHeader('Set-Cookie'));
        self::assertSame('a=1, b=2', $response->getHeaderLine('Set-Cookie'));
    }

    public function testResponseAppliesDefaultReasonPhraseForAbsentReason(): void
    {
        $handler = new StreamHandler();
        $request = new Request('GET', 'http://example.com');

        $this->setStreamHandlerLastHeaders($handler, ['HTTP/1.1 200']);
        /** @var ResponseInterface $ok */
        $ok = $this->invokeStreamHandlerCreateResponse($handler, $request, [], Psr7\Utils::streamFor(''))->wait();
        self::assertSame('OK', $ok->getReasonPhrase());

        $unknown = new StreamHandler();
        $this->setStreamHandlerLastHeaders($unknown, ['HTTP/1.1 599']);
        /** @var ResponseInterface $unknownResponse */
        $unknownResponse = $this->invokeStreamHandlerCreateResponse($unknown, $request, [], Psr7\Utils::streamFor(''))->wait();
        self::assertSame(599, $unknownResponse->getStatusCode());
        self::assertSame('', $unknownResponse->getReasonPhrase());
    }

    public function testResponsePreservesProtocolVersion(): void
    {
        $handler = new StreamHandler();
        $request = new Request('GET', 'http://example.com');

        $this->setStreamHandlerLastHeaders($handler, ['HTTP/1.0 200 OK']);
        /** @var ResponseInterface $response */
        $response = $this->invokeStreamHandlerCreateResponse($handler, $request, [], Psr7\Utils::streamFor(''))->wait();

        self::assertSame('1.0', $response->getProtocolVersion());
    }

    public function testResponseBodyIsBuiltViaStreamFactory(): void
    {
        $this->queueRes();
        $handler = new StreamHandler();
        $factory = new Psr17SpyFactory();

        $response = $handler(new Request('GET', Server::$url), [
            RequestOptions::STREAM_FACTORY => $factory,
            RequestOptions::RESPONSE_FACTORY => $factory,
        ])->wait();

        self::assertInstanceOf(SpyResponse::class, $response);
        self::assertInstanceOf(SpyStream::class, $response->getBody());
        self::assertSame('hi there', (string) $response->getBody());
        self::assertGreaterThanOrEqual(1, $factory->createStreamFromResourceCalls);
    }

    public function testStreamOptionBodyIsBuiltViaStreamFactory(): void
    {
        $this->queueRes();
        $handler = new StreamHandler();
        $factory = new Psr17SpyFactory();

        $response = $handler(new Request('GET', Server::$url), [
            RequestOptions::STREAM => true,
            RequestOptions::STREAM_FACTORY => $factory,
            RequestOptions::RESPONSE_FACTORY => $factory,
        ])->wait();

        self::assertInstanceOf(SpyStream::class, $response->getBody());
        self::assertSame('hi there', (string) $response->getBody());
        // The stream option short-circuits sink creation, so only the body
        // source is wrapped by the stream factory.
        self::assertSame(1, $factory->createStreamFromResourceCalls);
    }

    public function testGzipDecodeRoutesBodySourceThroughStreamFactory(): void
    {
        $gzip = \gzencode('decoded');
        self::assertIsString($gzip);

        $resource = Psr7\Utils::tryFopen('php://temp', 'r+');
        \fwrite($resource, $gzip);
        \rewind($resource);

        $handler = new StreamHandler();
        $request = new Request('GET', 'http://example.com');
        $factory = new Psr17SpyFactory();

        $this->setStreamHandlerLastHeaders($handler, [
            'HTTP/1.1 200 OK',
            'Content-Encoding: gzip',
        ]);

        /** @var ResponseInterface $response */
        $response = $this->invokeStreamHandlerCreateResponse($handler, $request, [
            RequestOptions::STREAM => true,
            RequestOptions::DECODE_CONTENT => true,
            RequestOptions::STREAM_FACTORY => $factory,
        ], $resource)->wait();

        self::assertSame('decoded', (string) $response->getBody());
        // The transport resource is wrapped via the stream factory once, up
        // front; the decode path then layers an InflateStream over that stream.
        self::assertSame(1, $factory->createStreamFromResourceCalls);

        // A non-decoded streamed body source is wrapped exactly the same way.
        $plainResource = Psr7\Utils::tryFopen('php://temp', 'r+');
        \fwrite($plainResource, 'plain');
        \rewind($plainResource);

        $plainHandler = new StreamHandler();
        $this->setStreamHandlerLastHeaders($plainHandler, ['HTTP/1.1 200 OK']);
        $plainFactory = new Psr17SpyFactory();

        /** @var ResponseInterface $plainResponse */
        $plainResponse = $this->invokeStreamHandlerCreateResponse($plainHandler, $request, [
            RequestOptions::STREAM => true,
            RequestOptions::STREAM_FACTORY => $plainFactory,
        ], $plainResource)->wait();

        self::assertInstanceOf(SpyStream::class, $plainResponse->getBody());
        self::assertSame('plain', (string) $plainResponse->getBody());
        self::assertSame(1, $plainFactory->createStreamFromResourceCalls);
    }

    public function testCallerResourceSinkIsNotClosedWhenBodyClosesWithCustomStreamFactory(): void
    {
        $this->queueRes();
        $handler = new StreamHandler();
        $factory = new Psr17SpyFactory();
        $sink = Psr7\Utils::tryFopen('php://temp', 'r+');

        $response = $handler(new Request('GET', Server::$url), [
            RequestOptions::SINK => $sink,
            RequestOptions::STREAM_FACTORY => $factory,
            RequestOptions::RESPONSE_FACTORY => $factory,
        ])->wait();

        self::assertSame('hi there', (string) $response->getBody());
        self::assertGreaterThanOrEqual(1, $factory->createStreamFromResourceCalls);

        // Closing the response body must detach the caller's resource without
        // closing it (the FnStream close => detach contract).
        $response->getBody()->close();
        self::assertIsResource($sink);
        \fclose($sink);
    }

    public function testCallerOwnedWriteOnlyResourceSinkDoesNotUseStreamFactory(): void
    {
        $tmpfname = \tempnam(\sys_get_temp_dir(), 'guzzle-sink');
        self::assertIsString($tmpfname);
        $sink = null;

        try {
            $this->queueRes();
            $handler = new StreamHandler();
            $factory = new StrictReadableResourceStreamFactory();
            $sink = Psr7\Utils::tryFopen($tmpfname, 'w');

            $response = $handler(new Request('GET', Server::$url), [
                RequestOptions::SINK => $sink,
                RequestOptions::STREAM_FACTORY => $factory,
            ])->wait();

            self::assertSame(200, $response->getStatusCode());
            // Only the transport resource should go through the factory; the
            // caller-owned write-only sink must keep Guzzle's resource wrapper.
            self::assertSame(1, $factory->createStreamFromResourceCalls);
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

    public function testFilePathSinkUsesLazyOpenStreamWithCustomStreamFactory(): void
    {
        $tmpfname = \tempnam(\sys_get_temp_dir(), 'guzzle-sink');
        self::assertIsString($tmpfname);

        $body = null;
        try {
            $this->queueRes();
            $handler = new StreamHandler();
            $factory = new Psr17SpyFactory();

            $response = $handler(new Request('GET', Server::$url), [
                RequestOptions::SINK => $tmpfname,
                RequestOptions::STREAM_FACTORY => $factory,
                RequestOptions::RESPONSE_FACTORY => $factory,
            ])->wait();

            $body = $response->getBody();
            // String path sinks keep lazy open semantics and must not be routed
            // through the stream factory.
            self::assertInstanceOf(Psr7\LazyOpenStream::class, $body);
            self::assertNotInstanceOf(SpyStream::class, $body);
            self::assertSame($tmpfname, $body->getMetadata('uri'));
            self::assertSame('hi there', (string) $body);
        } finally {
            if ($body !== null) {
                $body->close();
            }
            @\unlink($tmpfname);
        }
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

    private function parseProxyResult(string $url): array
    {
        $method = new \ReflectionMethod(StreamHandler::class, 'parseProxy');
        if (\PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }

        return $method->invokeArgs(new StreamHandler(), [$url, ProxyOptions::proxyScheme($url)]);
    }

    private function getProxyContext(string $proxy, string $uri = 'http://example.com'): array
    {
        $handler = new StreamHandler();
        $request = new Request('GET', $uri);
        $context = ['http' => []];
        $method = new \ReflectionMethod(StreamHandler::class, 'applyProxy');
        if (\PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }

        $method->invokeArgs($handler, [$request, &$context, $proxy]);

        return $context;
    }

    public function proxyParseProvider(): array
    {
        return [
            'scheme-less host' => [
                'proxy.example.com:8125',
                ['proxy' => 'tcp://proxy.example.com:8125', 'auth' => null],
            ],
            'scheme-less credentials' => [
                'user:pass@proxy.example.com:8125',
                ['proxy' => 'tcp://proxy.example.com:8125', 'auth' => 'Basic '.\base64_encode('user:pass')],
            ],
            'scheme-less username only' => [
                'user@proxy.example.com:8125',
                ['proxy' => 'tcp://proxy.example.com:8125', 'auth' => 'Basic '.\base64_encode('user:')],
            ],
            'scheme-less empty password' => [
                'user:@proxy.example.com:8125',
                ['proxy' => 'tcp://proxy.example.com:8125', 'auth' => 'Basic '.\base64_encode('user:')],
            ],
            'scheme-less empty userinfo' => [
                '@proxy.example.com:8125',
                ['proxy' => 'tcp://proxy.example.com:8125', 'auth' => null],
            ],
            'scheme-less ipv6' => [
                '[::1]:8125',
                ['proxy' => 'tcp://[::1]:8125', 'auth' => null],
            ],
            'scheme-less ipv6 credentials' => [
                'user:pass@[::1]:8125',
                ['proxy' => 'tcp://[::1]:8125', 'auth' => 'Basic '.\base64_encode('user:pass')],
            ],
            'explicit http credentials' => [
                'http://user:pass@proxy.example.com:8125',
                ['proxy' => 'tcp://proxy.example.com:8125', 'auth' => 'Basic '.\base64_encode('user:pass')],
            ],
            'uppercase http scheme' => [
                'HTTP://user:pass@proxy.example.com:8125',
                ['proxy' => 'tcp://proxy.example.com:8125', 'auth' => 'Basic '.\base64_encode('user:pass')],
            ],
            'raw transport unchanged' => [
                'ssl://proxy.example.com:8125',
                ['proxy' => 'ssl://proxy.example.com:8125', 'auth' => null],
            ],
            'scheme-less host without port defaults to 1080' => [
                'proxy.example.com',
                ['proxy' => 'tcp://proxy.example.com:1080', 'auth' => null],
            ],
            'scheme-less credentials without port defaults to 1080' => [
                'user:pass@proxy.example.com',
                ['proxy' => 'tcp://proxy.example.com:1080', 'auth' => 'Basic '.\base64_encode('user:pass')],
            ],
            'explicit http without port defaults to 1080' => [
                'http://proxy.example.com',
                ['proxy' => 'tcp://proxy.example.com:1080', 'auth' => null],
            ],
        ];
    }

    /**
     * @dataProvider proxyParseProvider
     */
    public function testTranslatesProxyForStreamContext(string $url, array $expected): void
    {
        self::assertSame($expected, $this->parseProxyResult($url));
    }

    public function testAddsProxyAuthorizationHeaderForSchemeLessCredentials(): void
    {
        $context = $this->getProxyContext('user:pass@proxy.example.com:8125');

        self::assertSame('tcp://proxy.example.com:8125', $context['http']['proxy']);
        self::assertStringContainsString(
            'Proxy-Authorization: Basic '.\base64_encode('user:pass'),
            $context['http']['header']
        );
    }
}
