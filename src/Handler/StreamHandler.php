<?php

declare(strict_types=1);

namespace GuzzleHttp\Handler;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Exception\InvalidArgumentException;
use GuzzleHttp\Exception\NetworkException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\Exception\ResponseTimeoutException;
use GuzzleHttp\Exception\ResponseTransferException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Multiplexing;
use GuzzleHttp\NonSerializableTrait;
use GuzzleHttp\Promise as P;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\ProxyOptions;
use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\Exception\TimeoutException;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\TransferStats;
use GuzzleHttp\TransportSharing;
use GuzzleHttp\Utils;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

/**
 * HTTP handler that uses PHP's HTTP stream wrapper.
 */
final class StreamHandler
{
    use NonSerializableTrait;

    private const KNOWN_CONSTRUCTOR_OPTIONS = [
        'max_host_connections' => true,
        'max_total_connections' => true,
        'transport_sharing' => true,
    ];

    private const CONNECTION_ERRORS = [
        'php_network_getaddresses:',
        'getaddrinfo',
        'gethostbyname failed',
        'Unable to connect to',
        'Connection refused',
        'No connection could be made because the target machine actively refused it',
        'connection attempt failed',
        'connect() failed',
        'Network is unreachable',
        'No route to host',
        'Host is unreachable',
        'Host is down',
        'Cannot connect to HTTPS server through proxy',
        'Failed to enable crypto',
    ];

    private const CONNECT_TIMEOUT_ERRORS = [
        'Connection timed out',
        'Operation timed out',
        'SSL: Handshake timed out',
        'did not properly respond after a period of time',
    ];

    private const NETWORK_ERRORS = [
        'SSL: Connection reset by peer',
        'SSL: Broken pipe',
        'unexpected eof while reading',
    ];

    /**
     * Default idle timeout in milliseconds when the "read_timeout" option is
     * not set. Matches PHP's default_socket_timeout default, which the
     * handler never consults.
     */
    private const DEFAULT_IDLE_TIMEOUT_MS = 60000;

    private array $lastHeaders = [];

    private ?float $lastDeadline = null;

    private ?\Throwable $onStatsException = null;

    private string $transportSharingMode;

    private bool $connectionCapsConfigured = false;

    /**
     * Accepts an associative array of options:
     *
     * - max_host_connections: Optional positive integer or null. A non-null
     *   value marks the handler as incompatible with enabled response
     *   streaming; the number is not used for stream-handler admission.
     * - max_total_connections: Optional positive integer or null. A non-null
     *   value marks the handler as incompatible with enabled response
     *   streaming; the number is not used for stream-handler admission.
     * - transport_sharing: Optional transport sharing mode.
     *
     * The stream handler cannot cap streamed connections, so a configured cap
     * marker rejects enabled response streaming ("stream" => true). Accepted
     * transfers are buffered and hold at most one connection per in-flight
     * call, but overlapping buffered calls are not collectively limited.
     *
     * @param array{max_host_connections?: mixed, max_total_connections?: mixed, transport_sharing?: mixed} $options Array of options to use with the handler
     */
    public function __construct(array $options = [])
    {
        foreach ($options as $name => $_) {
            if (!isset(self::KNOWN_CONSTRUCTOR_OPTIONS[$name])) {
                throw new InvalidArgumentException(\sprintf('Invalid StreamHandler constructor option "%s".', Psr7\DiagnosticValue::escape((string) $name)));
            }
        }

        $this->transportSharingMode = CurlShareHandleState::normalizeMode(
            $options['transport_sharing'] ?? null,
            'transport_sharing'
        );

        foreach (['max_host_connections', 'max_total_connections'] as $capOption) {
            $value = $options[$capOption] ?? null;
            if ($value === null) {
                continue;
            }

            if (!\is_int($value) || $value < 1) {
                throw new InvalidArgumentException(\sprintf('%s must be a positive integer.', $capOption));
            }

            $this->connectionCapsConfigured = true;
        }
    }

    /**
     * Sends an HTTP request.
     *
     * @param RequestInterface $request Request to send.
     * @param array            $options Request transfer options.
     *
     * @return PromiseInterface<ResponseInterface, mixed>
     */
    public function __invoke(
        #[\SensitiveParameter]
        RequestInterface $request,
        #[\SensitiveParameter]
        array $options
    ): PromiseInterface {
        $this->onStatsException = null;

        // Sleep if there is a delay specified.
        if (isset($options['delay'])) {
            \usleep((int) ($options['delay'] * 1000));
        }

        $multiplex = $options['multiplex'] ?? null;

        // Multiplexing::NONE is trivially satisfied: the stream handler sends
        // one HTTP/1.x request per connection and never multiplexes.
        if (null !== $multiplex && !\in_array($multiplex, [Multiplexing::NONE, Multiplexing::EAGER, Multiplexing::WAIT, Multiplexing::REQUIRE_EAGER, Multiplexing::REQUIRE_WAIT], true)) {
            throw new InvalidArgumentException(\sprintf(
                'The "multiplex" option must be null or a GuzzleHttp\\Multiplexing::* constant; received %s.',
                \get_debug_type($multiplex)
            ));
        }

        if (\in_array($multiplex, [Multiplexing::REQUIRE_EAGER, Multiplexing::REQUIRE_WAIT], true)) {
            throw new RequestException('The stream handler cannot guarantee a multiplexed protocol; required multiplexing needs a cURL handler.', $request);
        }

        $protocolVersion = $request->getProtocolVersion();

        if ('' === $protocolVersion) {
            throw new RequestException('HTTP protocol version must not be empty.', $request);
        }

        if (1 !== \preg_match('/^\d+(?:\.\d+)?$/D', $protocolVersion)) {
            throw new RequestException('HTTP protocol version must be a valid HTTP version number.', $request);
        }

        if ('1.0' !== $protocolVersion && '1.1' !== $protocolVersion) {
            throw new RequestException(sprintf('HTTP/%s is not supported by the stream handler.', $protocolVersion), $request);
        }

        if (isset($options['on_stats']) && !\is_callable($options['on_stats'])) {
            throw new InvalidArgumentException('on_stats must be callable');
        }

        $startTime = isset($options['on_stats']) ? Clock::now() : null;

        self::rejectUnsupportedRequestOptions($request, $options);
        $this->rejectStreamingWithConnectionCaps($options);
        $this->assertTransportSharingSupported();

        // The stream wrapper sends HEAD request bodies, unlike cURL's NOBODY
        // path, so validate their framing normally.
        $framing = RequestFraming::analyze($request->withoutHeader('Expect'));
        $request = $framing->request;

        try {
            self::assertRequestUriSupported($request, $options);
            $body = $framing->materialize();
            if ($framing->contentLength === null && ($body !== '' || \in_array($request->getMethod(), ['PUT', 'POST'], true))) {
                $request = $request->withHeader('Content-Length', (string) \strlen($body));
            }

            return $this->createResponse(
                $request,
                $options,
                $this->createStream($request, $options, $body),
                $startTime
            );
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\Exception $e) {
            if ($this->isOnStatsException($e)) {
                throw $e;
            }

            if (!$e instanceof TransferException) {
                $message = $e->getMessage();
                if (self::isSendError($message)) {
                    $e = self::isConnectTimeoutError($message)
                        ? new NetworkTimeoutException($message, $request, $e)
                        : new NetworkException($message, $request, $e);
                } elseif (self::isConnectTimeoutError($message)) {
                    $e = new ConnectTimeoutException($message, $request, $e);
                } elseif (self::isConnectionError($message)) {
                    $e = new ConnectException($message, $request, $e);
                } elseif (self::isNetworkError($message)) {
                    $e = new NetworkException($message, $request, $e);
                } else {
                    $e = new RequestException($message, $request, 0, $e);
                }
            }
            $this->invokeStats($options, $request, $startTime, null, $e);

            /** @var PromiseInterface<ResponseInterface, mixed> */
            return P\Create::rejectionFor($e);
        }
    }

    private function isOnStatsException(\Throwable $e): bool
    {
        if ($this->onStatsException !== $e) {
            return false;
        }

        $this->onStatsException = null;

        return true;
    }

    private static function isConnectTimeoutError(string $message): bool
    {
        foreach (self::CONNECT_TIMEOUT_ERRORS as $timeoutError) {
            if (Psr7\Utils::caselessContains($message, $timeoutError)) {
                return true;
            }
        }

        return false;
    }

    private static function isConnectionError(string $message): bool
    {
        foreach (self::CONNECTION_ERRORS as $connectionError) {
            if (Psr7\Utils::caselessContains($message, $connectionError)) {
                return true;
            }
        }

        return false;
    }

    private static function isSendError(string $message): bool
    {
        // A failed write ("Send of N bytes failed ...") implies an established connection.
        return Psr7\Utils::caselessContains($message, 'bytes failed with errno=');
    }

    private static function isNetworkError(string $message): bool
    {
        foreach (self::NETWORK_ERRORS as $networkError) {
            if (Psr7\Utils::caselessContains($message, $networkError)) {
                return true;
            }
        }

        return false;
    }

    private function invokeStats(
        #[\SensitiveParameter]
        array $options,
        #[\SensitiveParameter]
        RequestInterface $request,
        ?float $startTime,
        #[\SensitiveParameter]
        ?ResponseInterface $response = null,
        #[\SensitiveParameter]
        ?\Throwable $error = null
    ): void {
        if (isset($options['on_stats'])) {
            $stats = new TransferStats($request, $response, Clock::now() - $startTime, $error, []);
            try {
                ($options['on_stats'])($stats);
            } catch (\Throwable $e) {
                $this->onStatsException = $e;

                throw $e;
            }
        }
    }

    /**
     * @param resource $stream
     *
     * @return PromiseInterface<ResponseInterface, mixed>
     */
    private function createResponse(
        #[\SensitiveParameter]
        RequestInterface $request,
        #[\SensitiveParameter]
        array $options,
        $stream,
        ?float $startTime
    ): PromiseInterface {
        $hdrs = $this->lastHeaders;
        $this->lastHeaders = [];
        $deadline = $this->lastDeadline;
        $this->lastDeadline = null;

        try {
            [$ver, $status, $reason, $headers] = HeaderProcessor::parseHeaders($hdrs);
        } catch (\Throwable $e) {
            return $this->rejectResponseCreation($options, $request, $startTime, $e);
        }

        $canHaveBody = HeaderProcessor::responseCanHaveBody($request->getMethod(), $status);
        $framingFailure = null;
        $declaredLength = null;
        try {
            $declaredLength = HeaderProcessor::validateResponseFraming($request->getMethod(), $status, $headers);
            if (empty($options['stream'])) {
                HeaderProcessor::assertContentLengthWithinPlatformLimit($declaredLength);
            }
        } catch (\RuntimeException $e) {
            $framingFailure = $e;
        }

        if ($framingFailure === null) {
            try {
                $headers = $this->decodeChunkedResponse($headers, $stream, $canHaveBody);
            } catch (\RuntimeException $e) {
                $framingFailure = $e;
            }
        }

        $streamFactory = self::requireStreamFactory($options[RequestOptions::STREAM_FACTORY] ?? new Psr7\HttpFactory());
        $responseFactory = self::requireResponseFactory($options[RequestOptions::RESPONSE_FACTORY] ?? new Psr7\HttpFactory());

        // Wrap the transport resource with the configured stream factory, and
        // decorate buffered transfers with the deadline source before optional
        // content decoding layers an InflateStream on top, so every read that
        // pulls from the transport observes the deadline.
        $resource = $stream;
        $stream = $streamFactory->createStreamFromResource($stream);
        if ($framingFailure === null && $deadline !== null && empty($options['stream'])) {
            $stream = self::createDeadlineSource($stream, $resource, $deadline, $options);
        }

        $encodedBody = null;
        if ($framingFailure === null) {
            [$stream, $headers, $encodedBody] = self::checkDecode($options, $headers, $stream, $declaredLength);
        }

        $sink = $framingFailure !== null || !$canHaveBody
            ? $streamFactory->createStream('')
            : $this->createSink($stream, $options);

        try {
            $response = $responseFactory->createResponse($status, $reason ?? '')->withProtocolVersion($ver);
            foreach ($headers as $name => $value) {
                $response = $response->withAddedHeader((string) $name, $value);
            }
            $response = $response->withBody($sink);
        } catch (\Throwable $e) {
            return $this->rejectResponseCreation($options, $request, $startTime, $e);
        }

        if ($framingFailure !== null) {
            try {
                $stream->close();
            } catch (\Exception $e) {
                // Best-effort release; a failing transport close must not
                // mask the framing rejection.
            }

            $reason = $framingFailure instanceof \OverflowException
                ? new ResponseException($framingFailure->getMessage(), $request, $response, $framingFailure)
                : new ResponseTransferException($framingFailure->getMessage(), $request, $response, $framingFailure);
            $this->invokeStats($options, $request, $startTime, $response, $reason);

            /** @var PromiseInterface<ResponseInterface, mixed> */
            return P\Create::rejectionFor($reason);
        }

        // The header phase inside fopen() can only bound the time between
        // packets, so a header block that trickled in past the deadline is
        // rejected here, once it is complete. The transport is closed so a
        // streamed response cannot hold the connection open through the
        // rejection.
        if ($deadline !== null && Clock::now() >= $deadline) {
            try {
                $stream->close();
            } catch (\Exception $e) {
                // Best-effort release; a failing transport close must not
                // mask the timeout rejection.
            }

            $reason = new ResponseTimeoutException('Timed out while receiving the response headers', $request, $response);
            $this->invokeStats($options, $request, $startTime, $response, $reason);

            /** @var PromiseInterface<ResponseInterface, mixed> */
            return P\Create::rejectionFor($reason);
        }

        if (isset($options['on_headers'])) {
            try {
                $options['on_headers']($response, $request);
            } catch (\Throwable $e) {
                $reason = new ResponseException('An error was encountered during the on_headers event', $request, $response, $e);
                $this->invokeStats($options, $request, $startTime, $response, $reason);

                /** @var PromiseInterface<ResponseInterface, mixed> */
                return P\Create::rejectionFor($reason);
            }
        }

        if (!$canHaveBody) {
            // RFC 9110 / RFC 9112 section 6.3: octets after the header section
            // of a HEAD, 1xx, 204, 304, or CONNECT-2xx response are not part
            // of this response's body. Never read them, and release the
            // transport as soon as the headers are complete.
            try {
                $stream->close();
            } catch (\Exception $e) {
                // Best-effort release; a failing transport close must not fail
                // a fully received no-content response.
            }
        } elseif ($sink !== $stream) {
            try {
                $this->drain($request, $response, $stream, $sink, $declaredLength, $encodedBody);
            } catch (ResponseException $e) {
                $this->invokeStats($options, $request, $startTime, $response, $e);

                /** @var PromiseInterface<ResponseInterface, mixed> */
                return P\Create::rejectionFor($e);
            }
        }

        $this->invokeStats($options, $request, $startTime, $response, null);

        /** @var PromiseInterface<ResponseInterface, mixed> */
        return P\Create::promiseFor($response);
    }

    /**
     * @return PromiseInterface<ResponseInterface, mixed>
     */
    private function rejectResponseCreation(
        #[\SensitiveParameter]
        array $options,
        #[\SensitiveParameter]
        RequestInterface $request,
        ?float $startTime,
        #[\SensitiveParameter]
        \Throwable $previous
    ): PromiseInterface {
        $reason = new RequestException(
            'An error was encountered while creating the response',
            $request,
            0,
            $previous
        );

        $this->invokeStats($options, $request, $startTime, null, $reason);

        /** @var PromiseInterface<ResponseInterface, mixed> */
        return P\Create::rejectionFor($reason);
    }

    private function createSink(
        StreamInterface $stream,
        #[\SensitiveParameter]
        array $options
    ): StreamInterface {
        if (!empty($options['stream'])) {
            return $stream;
        }

        $streamFactory = self::requireStreamFactory($options[RequestOptions::STREAM_FACTORY] ?? new Psr7\HttpFactory());
        $hasSink = isset($options['sink']);
        $sink = $hasSink ? $options['sink'] : Psr7\Utils::tryFopen('php://temp', 'r+');

        if ($hasSink && \is_resource($sink)) {
            return self::streamForResourceSink(Psr7\Utils::streamFor($sink));
        }

        if (\is_string($sink)) {
            return new Psr7\LazyOpenStream($sink, 'w+');
        }

        if (!\is_resource($sink)) {
            return Psr7\Utils::streamFor($sink);
        }

        return $streamFactory->createStreamFromResource($sink);
    }

    /**
     * Decorates a caller-owned sink stream so that closing the response body
     * detaches Guzzle's wrapper without closing the original PHP resource.
     */
    private static function streamForResourceSink(StreamInterface $stream): StreamInterface
    {
        return Psr7\FnStream::decorate($stream, [
            'close' => static function () use ($stream): void {
                $stream->detach();
            },
        ]);
    }

    /**
     * @param mixed $factory
     */
    private static function requireStreamFactory($factory): StreamFactoryInterface
    {
        if (!$factory instanceof StreamFactoryInterface) {
            throw new InvalidArgumentException(\sprintf(
                '%s must be an instance of %s',
                RequestOptions::STREAM_FACTORY,
                StreamFactoryInterface::class
            ));
        }

        return $factory;
    }

    /**
     * @param mixed $factory
     */
    private static function requireResponseFactory($factory): ResponseFactoryInterface
    {
        if (!$factory instanceof ResponseFactoryInterface) {
            throw new InvalidArgumentException(\sprintf(
                '%s must be an instance of %s',
                RequestOptions::RESPONSE_FACTORY,
                ResponseFactoryInterface::class
            ));
        }

        return $factory;
    }

    /**
     * Applies the HTTP wrapper's normal chunked-response handling.
     *
     * @param array<string, string[]> $headers
     * @param resource                $stream
     * @param bool                    $decodeBody Whether to attach the dechunk filter
     *
     * @return array<string, string[]>
     *
     * @throws \RuntimeException when the dechunk filter cannot be attached
     */
    private function decodeChunkedResponse(array $headers, $stream, bool $decodeBody): array
    {
        $decodedHeaders = $headers;
        $decode = false;

        foreach ($headers as $name => $values) {
            if (!Psr7\Utils::caselessEquals((string) $name, 'Transfer-Encoding')) {
                continue;
            }

            $remaining = [];
            foreach ($values as $value) {
                // Match PHP's legacy auto_decode prefix check so moving the
                // filter does not change which responses are dechunked.
                if (Psr7\Utils::caselessEquals(\substr($value, 0, 7), 'chunked')) {
                    $decode = true;
                } else {
                    $remaining[] = $value;
                }
            }

            if ($remaining === []) {
                unset($decodedHeaders[$name]);
            } else {
                $decodedHeaders[$name] = $remaining;
            }
        }

        if (!$decode) {
            return $headers;
        }

        if ($decodeBody) {
            $this->createResource(static function () use ($stream) {
                return \stream_filter_append($stream, 'dechunk', \STREAM_FILTER_READ);
            });
        }

        return $decodedHeaders;
    }

    /**
     * @return array{0: StreamInterface, 1: array, 2: ?EncodedBodyStream}
     */
    private static function checkDecode(
        #[\SensitiveParameter]
        array $options,
        array $headers,
        StreamInterface $stream,
        ?string $declaredLength
    ): array {
        $encodedBody = null;

        // Automatically decode responses when instructed.
        if (isset($options['decode_content']) && $options['decode_content'] !== false) {
            $normalizedKeys = Utils::normalizeHeaderKeys($headers);
            if (isset($normalizedKeys['content-encoding'])) {
                $encoding = $headers[$normalizedKeys['content-encoding']];
                if ($encoding[0] === 'gzip' || $encoding[0] === 'deflate') {
                    if (empty($options['stream']) && $declaredLength !== null && $declaredLength !== '0') {
                        $encodedBody = new EncodedBodyStream($stream, $declaredLength);
                        $stream = $encodedBody;
                    }

                    $stream = new Psr7\InflateStream($stream);
                    $headers['x-encoded-content-encoding'] = $headers[$normalizedKeys['content-encoding']];

                    // Remove content-encoding header
                    unset($headers[$normalizedKeys['content-encoding']]);

                    // The decoded length cannot be known without inflating the
                    // stream, so keep the original length for inspection and
                    // drop the now-unknown Content-Length header.
                    $encodedContentLength = HeaderProcessor::removeHeader('Content-Length', $headers);
                    if ($encodedContentLength !== []) {
                        $headers['x-encoded-content-length'] = $encodedContentLength;
                    }
                }
            }
        }

        return [$stream, $headers, $encodedBody];
    }

    /**
     * Drains the source stream into the "sink" client option.
     *
     * @throws \RuntimeException when the sink option is invalid.
     */
    private function drain(
        #[\SensitiveParameter]
        RequestInterface $request,
        #[\SensitiveParameter]
        ResponseInterface $response,
        StreamInterface $source,
        StreamInterface $sink,
        ?string $declaredResponseBodyLength,
        ?EncodedBodyStream $encodedBody = null
    ): StreamInterface {
        try {
            // Buffered paths reject unrepresentable lengths before draining.
            $declaredLength = HeaderProcessor::contentLengthToInt($declaredResponseBodyLength);
            $declaredLength = $declaredLength !== null && $declaredLength > 0 ? $declaredLength : null;
            $copyLimit = $encodedBody === null ? $declaredLength ?? -1 : -1;

            try {
                $target = $this->createResponseSink($request, $response, $sink);
                // If a content-length header is provided, then stop reading once
                // that number of bytes has been read. This can prevent infinitely
                // reading from a stream when dealing with servers that do not
                // honor Connection: Close headers.
                $copied = Psr7\Utils::copyToStream($source, $target, $copyLimit);
            } catch (ResponseException $e) {
                throw $e;
            } catch (TimeoutException $e) {
                throw new ResponseTimeoutException(
                    'Timed out while transferring the response body',
                    $request,
                    $response,
                    $e
                );
            } catch (\OverflowException $e) {
                throw new ResponseException($e->getMessage(), $request, $response, $e);
            } catch (\Exception $e) {
                // Any other response-body transfer failure surfaces as a
                // ResponseTransferException carrying the response.
                throw new ResponseTransferException(
                    $e->getMessage() !== '' ? $e->getMessage() : 'Failed while transferring the response body',
                    $request,
                    $response,
                    $e
                );
            }

            $receivedLength = $encodedBody !== null ? $encodedBody->getBytesRead() : $copied;
            if ($declaredLength !== null && $receivedLength < $declaredLength) {
                throw new ResponseTransferException(
                    'Response body ended before the declared Content-Length was reached',
                    $request,
                    $response
                );
            }

            try {
                if ($sink->isSeekable()) {
                    $sink->rewind();
                }
            } catch (\Exception $e) {
                throw new ResponseException(
                    $e->getMessage() !== '' ? $e->getMessage() : 'Failed to rewind the response body',
                    $request,
                    $response,
                    $e
                );
            }

            return $sink;
        } finally {
            try {
                $source->close();
            } catch (\Exception $e) {
                // Best-effort cleanup after the response body has been received.
            }
        }
    }

    private function createResponseSink(
        RequestInterface $request,
        ResponseInterface $response,
        StreamInterface $sink
    ): StreamInterface {
        return Psr7\FnStream::decorate($sink, [
            'close' => static function (): void {
            },
            'write' => static function (string $data) use ($request, $response, $sink): int {
                try {
                    $written = $sink->write($data);
                } catch (TimeoutException $e) {
                    throw new ResponseException(
                        'Timed out while writing the response body',
                        $request,
                        $response,
                        $e
                    );
                } catch (\Exception $e) {
                    throw new ResponseException(
                        $e->getMessage() !== '' ? $e->getMessage() : 'Failed to write the response body',
                        $request,
                        $response,
                        $e
                    );
                }

                if ($written <= 0) {
                    throw new ResponseException('Unable to write to stream', $request, $response);
                }

                return $written;
            },
            'getMetadata' => static function (?string $key = null) use ($sink) {
                // Force timed_out to false so Utils::writeAll() can't reclassify a sink-write
                // failure as a transport timeout. Sink write failures are ResponseException;
                // source-read timeouts are ResponseTimeoutException.
                if ($key === 'timed_out') {
                    return false;
                }

                return $sink->getMetadata($key);
            },
        ]);
    }

    /**
     * Decorates the transport stream so each buffered-body read observes the
     * remaining wall-clock budget of the "timeout" request option, bounded
     * by the "read_timeout" idle timeout when that is shorter. The transport
     * is switched to non-blocking mode because filtered reads (chunked and
     * compressed responses) otherwise block until a full buffer arrives,
     * which would keep the deadline from being enforced between small pieces
     * of data.
     *
     * @param resource $resource
     */
    private static function createDeadlineSource(
        StreamInterface $stream,
        $resource,
        float $deadline,
        #[\SensitiveParameter]
        array $options
    ): StreamInterface {
        $idleTimeout = isset($options['read_timeout'])
            ? Timeout::toMilliseconds($options['read_timeout'], 'read_timeout')
            : self::DEFAULT_IDLE_TIMEOUT_MS;

        \stream_set_blocking($resource, false);

        return new DeadlineSourceStream($stream, $deadline, $idleTimeout > 0 ? $idleTimeout / 1000 : null);
    }

    /**
     * Create a resource and check to ensure it was created successfully
     *
     * @param callable(): (resource|false) $callback Callable that returns a stream resource, or false when resource creation fails.
     *
     * @return resource
     *
     * @throws \RuntimeException when the callback returns false or resource creation emits an error.
     */
    private function createResource(callable $callback)
    {
        $errors = [];
        \set_error_handler(static function (int $_, string $msg, string $file, int $line) use (&$errors): bool {
            $errors[] = [
                'message' => $msg,
                'file' => $file,
                'line' => $line,
            ];

            return true;
        });

        try {
            $resource = $callback();
        } finally {
            \restore_error_handler();
        }

        if (!$resource) {
            $details = [];
            foreach ($errors as $err) {
                foreach ($err as $key => $value) {
                    $details[] = \sprintf('[%s] %s', $key, Psr7\DiagnosticValue::escape((string) $value));
                }
            }

            $message = 'Error creating resource';
            if ($details !== []) {
                $message .= ': '.\implode('; ', $details);
            }

            throw new \RuntimeException($message);
        }

        return $resource;
    }

    /**
     * @return resource
     */
    private function createStream(
        #[\SensitiveParameter]
        RequestInterface $request,
        #[\SensitiveParameter]
        array $options,
        string $body
    ) {
        // HTTP/1.1 streams using the PHP stream wrapper require a
        // Connection: close header
        if ($request->getProtocolVersion() === '1.1'
            && !$request->hasHeader('Connection')
        ) {
            $request = $request->withHeader('Connection', 'close');
        }

        // Ensure SSL is verified by default
        if (!isset($options['verify'])) {
            $options['verify'] = true;
        }

        $params = [];
        $context = $this->getDefaultContext($request, $body);

        if (isset($options['on_headers']) && !\is_callable($options['on_headers'])) {
            throw new InvalidArgumentException('on_headers must be callable');
        }

        $idleTimeout = isset($options['read_timeout'])
            ? Timeout::toMilliseconds($options['read_timeout'], 'read_timeout')
            : self::DEFAULT_IDLE_TIMEOUT_MS;

        $timeout = isset($options['timeout'])
            ? Timeout::toMilliseconds($options['timeout'], 'timeout')
            : 0;

        self::assertTlsVersionRangeForOptions($request, $options);

        $this->applyHandlerOptions($request, $context, $options, $params);

        // Resolve the proxy unconditionally (option first, then environment),
        // so an env-configured proxy applies even when no proxy option is set.
        $this->applyProxy($request, $context, $options['proxy'] ?? null);

        if (isset($options['stream_context'])) {
            $streamContext = $options['stream_context'];
            if (!\is_array($streamContext)) {
                throw new InvalidArgumentException('stream_context must be an array');
            }
            self::rejectConflictingStreamContextOptions($streamContext);
            self::rejectUnsupportedStreamContextOptions($streamContext);
            $context = \array_replace_recursive($context, $streamContext);
        }

        $this->addDefaultTlsMinimum($request, $context);

        // The context timeout governs connecting and the header-phase packet
        // gaps: the idle timeout, tightened to the deadline when that is
        // lower; -1 disables it so default_socket_timeout is never consulted.
        if ($timeout > 0 && ($idleTimeout <= 0 || $timeout < $idleTimeout)) {
            $context['http']['timeout'] = $timeout / 1000;
        } elseif ($idleTimeout > 0) {
            $context['http']['timeout'] = $idleTimeout / 1000;
        } else {
            $context['http']['timeout'] = -1;
        }

        $uri = $this->resolveHost($request, $options);

        $contextResource = $this->createResource(
            static function () use ($context, $params) {
                return \stream_context_create($context, $params);
            }
        );

        return $this->createResource(
            function () use ($uri, $contextResource, $idleTimeout, $timeout) {
                $this->lastDeadline = $timeout > 0 ? Clock::now() + $timeout / 1000 : null;

                // Blank the from ini setting for the transfer so ambient
                // configuration cannot leak into a From header; the wrapper
                // cannot omit the header, so a configured ini sends it empty.
                $iniFrom = \function_exists('ini_set') ? \ini_set('from', '') : false;
                try {
                    $resource = @\fopen((string) $uri, 'r', false, $contextResource);
                } finally {
                    if ($iniFrom !== false) {
                        \ini_set('from', $iniFrom);
                    }
                }

                // PHP 8.5 deprecates the local $http_response_header variable.
                if (function_exists('http_get_last_response_headers')) {
                    $http_response_header = \http_get_last_response_headers();
                }

                $this->lastHeaders = $http_response_header ?? [];

                if (false === $resource) {
                    return false;
                }

                // Arm reads with the idle timeout, replacing the context
                // value the deadline may have tightened; -1 disables it.
                if ($idleTimeout > 0) {
                    $sec = \intdiv($idleTimeout, 1000);
                    $usec = ($idleTimeout % 1000) * 1000;
                    \stream_set_timeout($resource, $sec, $usec);
                } else {
                    \stream_set_timeout($resource, -1);
                }

                return $resource;
            }
        );
    }

    private static function assertRequestUriSupported(
        #[\SensitiveParameter]
        RequestInterface $request,
        #[\SensitiveParameter]
        array $options
    ): void {
        $uri = $request->getUri();
        $scheme = $uri->getScheme();
        if ($scheme === '') {
            throw new RequestException(
                'URI must include a scheme and host. Use an absolute URI, a network-path reference starting with //, or configure a base_uri.',
                $request
            );
        }

        if (!\in_array($scheme, ['http', 'https'], true)) {
            throw new RequestException(\sprintf("The scheme '%s' is not supported.", Psr7\DiagnosticValue::escape($scheme)), $request);
        }

        $protocols = Utils::normalizeProtocols($options['protocols'] ?? ['http', 'https']);
        if (!\in_array($scheme, $protocols, true)) {
            throw new RequestException(\sprintf('The scheme "%s" is not allowed by the protocols request option.', Psr7\DiagnosticValue::escape($scheme)), $request);
        }

        if ($uri->getHost() === '') {
            throw new RequestException(
                'URI must include a scheme and host. Use an absolute URI, a network-path reference starting with //, or configure a base_uri.',
                $request
            );
        }
    }

    private function applyHandlerOptions(
        #[\SensitiveParameter]
        RequestInterface $request,
        #[\SensitiveParameter]
        array &$context,
        #[\SensitiveParameter]
        array $options,
        array &$params
    ): void {
        foreach ($options as $key => $value) {
            if ($key === 'crypto_method') {
                $this->applyCryptoMethodOption($context, $value);
            } elseif ($key === 'crypto_method_max') {
                $this->applyCryptoMethodMaxOption($context, $value);
            } elseif ($key === 'verify') {
                $this->applyVerifyOption($context, $value);
            } elseif ($key === 'cert') {
                $this->applyCertOption($context, $value);
            } elseif ($key === 'cert_type') {
                $this->applyCertTypeOption($value);
            } elseif ($key === 'ssl_key') {
                $this->applySslKeyOption($context, $value);
            } elseif ($key === 'ssl_key_type') {
                $this->applySslKeyTypeOption($value);
            } elseif ($key === 'progress') {
                $this->applyProgressOption($value, $params);
            } elseif ($key === 'debug') {
                $this->applyDebugOption($request, $value, $params);
            }
        }
    }

    private function resolveHost(
        #[\SensitiveParameter]
        RequestInterface $request,
        #[\SensitiveParameter]
        array $options
    ): UriInterface {
        $uri = $request->getUri();

        $host = $uri->getHost();
        $hostForIpCheck = \str_starts_with($host, '[') && \str_ends_with($host, ']')
            ? \substr($host, 1, -1)
            : $host;
        if (isset($options['force_ip_resolve']) && !\filter_var($hostForIpCheck, \FILTER_VALIDATE_IP)) {
            if ('v4' === $options['force_ip_resolve']) {
                $records = \dns_get_record($uri->getHost(), \DNS_A);
                if (false === $records || !isset($records[0]['ip'])) {
                    throw new ConnectException(\sprintf("Could not resolve IPv4 address for host '%s'", Psr7\DiagnosticValue::escape($uri->getHost())), $request);
                }

                return $uri->withHost($records[0]['ip']);
            }
            if ('v6' === $options['force_ip_resolve']) {
                $records = \dns_get_record($uri->getHost(), \DNS_AAAA);
                if (false === $records || !isset($records[0]['ipv6'])) {
                    throw new ConnectException(\sprintf("Could not resolve IPv6 address for host '%s'", Psr7\DiagnosticValue::escape($uri->getHost())), $request);
                }

                return $uri->withHost('['.$records[0]['ipv6'].']');
            }
        }

        return $uri;
    }

    private function addDefaultTlsMinimum(RequestInterface $request, array &$context): void
    {
        if ('https' !== $request->getUri()->getScheme() || !isset($context['ssl']) || !\is_array($context['ssl'])) {
            return;
        }

        if (
            \array_key_exists('crypto_method', $context['ssl'])
            || \array_key_exists('min_proto_version', $context['ssl'])
        ) {
            return;
        }

        $context['ssl']['min_proto_version'] = \STREAM_CRYPTO_PROTO_TLSv1_2;
    }

    private function getDefaultContext(
        #[\SensitiveParameter]
        RequestInterface $request,
        string $body
    ): array {
        $headers = '';
        foreach ($request->getHeaders() as $name => $value) {
            // The first-class Proxy-Authorization field never enters the
            // stream context header block: PHP streams have no proxy-only
            // header channel, so applyProxy() rejects the field when a proxy
            // is selected and it is omitted on direct and bypassed routes.
            if (Psr7\Utils::caselessEquals((string) $name, 'Proxy-Authorization')) {
                continue;
            }

            foreach ($value as $val) {
                $headers .= "$name: $val\r\n";
            }
        }

        $context = [
            'http' => [
                'auto_decode' => false,
                'method' => $request->getMethod(),
                'header' => $headers,
                'protocol_version' => $request->getProtocolVersion(),
                'ignore_errors' => true,
                'follow_location' => 0,
            ],
            'ssl' => [
                'peer_name' => $request->getUri()->getHost(),
            ],
        ];

        // An empty context user_agent stops the HTTP stream wrapper from
        // appending a User-Agent header from the user_agent ini setting, so a
        // request without the header sends none, like the cURL handlers.
        if (!$request->hasHeader('User-Agent')) {
            $context['http']['user_agent'] = '';
        }

        if ('' !== $body) {
            $context['http']['content'] = $body;
            // Prevent the HTTP handler from adding a Content-Type header.
            if (!$request->hasHeader('Content-Type')) {
                $context['http']['header'] .= "Content-Type:\r\n";
            }
        }

        $context['http']['header'] = \rtrim($context['http']['header'], "\r\n");

        return $context;
    }

    private static function rejectUnsupportedRequestOptions(
        #[\SensitiveParameter]
        RequestInterface $request,
        #[\SensitiveParameter]
        array $options
    ): void {
        if (
            \array_key_exists('curl', $options)
            && $options['curl'] !== null
            && $options['curl'] !== []
        ) {
            throw new InvalidArgumentException('Passing the "curl" request option to the stream handler is not supported because the stream handler ignores cURL options.');
        }

        if (\array_key_exists('expect', $options) && $options['expect'] !== false && $request->hasHeader('Expect')) {
            throw new InvalidArgumentException('Passing the "expect" request option to the stream handler is not supported when it adds an Expect header because the stream handler does not support Expect: 100-Continue.');
        }

        if (isset($options['on_trailers'])) {
            throw new InvalidArgumentException('Passing the "on_trailers" request option to the stream handler is not supported because the stream handler cannot observe trailers.');
        }
    }

    private function rejectStreamingWithConnectionCaps(
        #[\SensitiveParameter]
        array $options
    ): void {
        if ($this->connectionCapsConfigured && !empty($options['stream'])) {
            throw new InvalidArgumentException('Enabling the "stream" request option on a stream handler configured with the "max_host_connections" or "max_total_connections" option is not supported because streamed connections cannot be capped.');
        }
    }

    private static function rejectConflictingStreamContextOptions(
        #[\SensitiveParameter]
        array $streamContext
    ): void {
        $conflictingOptions = self::conflictingStreamContextOptions();

        foreach ($streamContext as $wrapper => $contextOptions) {
            if (!\is_string($wrapper) || !isset($conflictingOptions[$wrapper]) || !\is_array($contextOptions)) {
                continue;
            }

            foreach ($contextOptions as $option => $_) {
                if (!\is_string($option) || !\array_key_exists($option, $conflictingOptions[$wrapper])) {
                    continue;
                }

                $replacement = $conflictingOptions[$wrapper][$option];
                throw new InvalidArgumentException(\sprintf(
                    'Passing stream_context.%s.%s in the "stream_context" request option is not supported because it conflicts with Guzzle-managed request handling. Use %s instead.',
                    Psr7\DiagnosticValue::escape($wrapper),
                    Psr7\DiagnosticValue::escape($option),
                    $replacement
                ));
            }
        }
    }

    private static function rejectUnsupportedStreamContextOptions(
        #[\SensitiveParameter]
        array $streamContext
    ): void {
        $unsupportedOptions = self::unsupportedStreamContextOptions($streamContext);
        if ($unsupportedOptions === []) {
            return;
        }

        throw new InvalidArgumentException(\sprintf(
            'Passing PHP stream context options outside the built-in stream handler allow-list to the "stream_context" request option is not supported. Unsupported option%s: %s.',
            \count($unsupportedOptions) === 1 ? '' : 's',
            \implode(', ', $unsupportedOptions)
        ));
    }

    /**
     * @return string[]
     */
    private static function unsupportedStreamContextOptions(array $streamContext): array
    {
        $supportedOptions = self::supportedStreamContextOptions();
        $conflictingOptions = self::conflictingStreamContextOptions();
        $unsupportedOptions = [];

        foreach ($streamContext as $wrapper => $contextOptions) {
            if (!\is_string($wrapper) || !isset($supportedOptions[$wrapper])) {
                if (\is_array($contextOptions)) {
                    foreach ($contextOptions as $option => $_) {
                        if (\is_string($wrapper) && \is_string($option) && isset($conflictingOptions[$wrapper]) && \array_key_exists($option, $conflictingOptions[$wrapper])) {
                            continue;
                        }

                        $unsupportedOptions[] = \sprintf('stream_context.%s.%s', Psr7\DiagnosticValue::escape((string) $wrapper), Psr7\DiagnosticValue::escape((string) $option));
                    }
                } else {
                    $unsupportedOptions[] = \sprintf('stream_context.%s', Psr7\DiagnosticValue::escape((string) $wrapper));
                }

                continue;
            }

            if (!\is_array($contextOptions)) {
                $unsupportedOptions[] = \sprintf('stream_context.%s', Psr7\DiagnosticValue::escape($wrapper));

                continue;
            }

            foreach ($contextOptions as $option => $_) {
                if (\is_string($option) && isset($conflictingOptions[$wrapper]) && \array_key_exists($option, $conflictingOptions[$wrapper])) {
                    continue;
                }

                if (!\is_string($option) || !\array_key_exists($option, $supportedOptions[$wrapper])) {
                    $unsupportedOptions[] = \sprintf('stream_context.%s.%s', Psr7\DiagnosticValue::escape($wrapper), Psr7\DiagnosticValue::escape((string) $option));
                }
            }
        }

        return $unsupportedOptions;
    }

    /**
     * @return array<string, array<string, true>>
     */
    private static function supportedStreamContextOptions(): array
    {
        return [
            'http' => [
                'request_fulluri' => true,
            ],
            'socket' => [
                'bindto' => true,
                'tcp_nodelay' => true,
            ],
            'ssl' => [
                'SNI_enabled' => true,
                'capture_peer_cert' => true,
                'capture_peer_cert_chain' => true,
                'ciphers' => true,
                'disable_compression' => true,
                'no_ticket' => true,
                'peer_fingerprint' => true,
                'security_level' => true,
                'verify_depth' => true,
            ],
        ];
    }

    /**
     * @return array<string, array<string, string>>
     */
    private static function conflictingStreamContextOptions(): array
    {
        return [
            'http' => [
                'auto_decode' => 'Guzzle response framing and decoding',
                'content' => 'the request body',
                'follow_location' => 'the "allow_redirects" request option',
                'header' => 'the request headers',
                'max_redirects' => 'the "allow_redirects" request option',
                'method' => 'the request method',
                'protocol_version' => 'the request protocol version',
                'proxy' => 'the "proxy" request option',
                'timeout' => 'the "timeout" and "read_timeout" request options',
            ],
            'ssl' => [
                'allow_self_signed' => 'the "verify" request option',
                'cafile' => 'the "verify" request option',
                'capath' => 'the "verify" request option',
                'crypto_method' => 'the "crypto_method" request option',
                'local_cert' => 'the "cert" request option',
                'local_pk' => 'the "ssl_key" request option',
                'max_proto_version' => 'the "crypto_method_max" request option',
                'min_proto_version' => 'the "crypto_method" request option',
                'passphrase' => 'the "cert" or "ssl_key" request option',
                'peer_name' => 'the request URI',
                'verify_peer' => 'the "verify" request option',
                'verify_peer_name' => 'the "verify" request option',
            ],
        ];
    }

    private function assertTransportSharingSupported(): void
    {
        if ($this->transportSharingMode === TransportSharing::PERSISTENT_REQUIRE) {
            throw new InvalidArgumentException('The "transport_sharing" option requires persistent transport sharing, which is only available through cURL share handles.');
        }

        if ($this->transportSharingMode === TransportSharing::HANDLER_REQUIRE) {
            throw new InvalidArgumentException('The "transport_sharing" option requires transport sharing, but the stream handler does not support it.');
        }
    }

    /**
     * @param mixed $value as passed via Request transfer options.
     *
     * @return array{0: string, 1: string|null}
     */
    private static function normalizeTlsFileOption(
        string $option,
        #[\SensitiveParameter]
        $value
    ): array {
        $passphrase = null;

        if (\is_array($value)) {
            if (!isset($value[0]) || !\is_string($value[0])) {
                throw new InvalidArgumentException(\sprintf('Invalid %s request option', $option));
            }
            if (isset($value[1])) {
                if (!\is_string($value[1])) {
                    throw new InvalidArgumentException(\sprintf('Invalid %s request option', $option));
                }
                $passphrase = $value[1];
            }
            $value = $value[0];
        }

        if (!\is_string($value)) {
            throw new InvalidArgumentException(\sprintf('Invalid %s request option', $option));
        }

        return [$value, $passphrase];
    }

    private static function setTlsPassphrase(
        #[\SensitiveParameter]
        array &$options,
        #[\SensitiveParameter]
        ?string $passphrase,
        string $option
    ): void {
        if ($passphrase === null) {
            return;
        }

        if (isset($options['ssl']['passphrase']) && $options['ssl']['passphrase'] !== $passphrase) {
            throw new InvalidArgumentException(\sprintf('Cannot use different passphrases for cert and ssl_key with the stream handler; %s conflicts with an existing TLS passphrase.', $option));
        }

        $options['ssl']['passphrase'] = $passphrase;
    }

    /**
     * @param mixed $value as passed via Request transfer options.
     */
    private static function assertStreamTlsType(string $option, $value): void
    {
        if (!\is_string($value) || $value === '') {
            throw new InvalidArgumentException(\sprintf('%s must be a non-empty string', $option));
        }

        if (Psr7\Utils::asciiToUpper($value) !== 'PEM') {
            throw new InvalidArgumentException(\sprintf('The stream handler only supports "PEM" for the %s request option.', $option));
        }
    }

    /**
     * @param mixed $value as passed via Request transfer options.
     */
    private function applyProxy(
        #[\SensitiveParameter]
        RequestInterface $request,
        #[\SensitiveParameter]
        array &$context,
        #[\SensitiveParameter]
        $value
    ): void {
        $proxy = ProxyEnv::resolveProxySelection($request->getUri(), $value);
        $proxyUri = $proxy->getProxy();
        if ($proxyUri === null) {
            return;
        }

        // Validate the whole proxy authority up front (ProxyOptions leans on
        // Psr7\Rfc3986), so a malformed proxy fails the same way on every
        // handler. PHP's HTTP stream wrapper can only carry HTTP over a
        // TCP-family socket, so reject any scheme it can never execute instead
        // of letting PHP fail with a misleading "unable to find the socket
        // transport" error. The supported set is a scheme-less value, http, or
        // a TCP-family transport (tcp://, ssl://, tls://, tlsv1.*) the build has.
        $scheme = ProxyOptions::proxyScheme($proxyUri);

        if ($scheme === 'https') {
            throw new InvalidArgumentException('HTTPS proxies are not supported by the stream handler.');
        }

        if (\in_array($scheme, ['socks4', 'socks4a', 'socks5', 'socks5h'], true)) {
            throw new InvalidArgumentException('SOCKS proxies are not supported by the stream handler.');
        }

        // Only http or a TCP-family raw transport (tcp, ssl, tls, tlsv1.*) can
        // carry HTTP through the stream wrapper; udp/unix/udg/ftp/ws and typos
        // cannot on any build, so reject them as a caller error rather than
        // install an unusable proxy.
        if ($scheme !== 'http' && !self::isRawTransportName($scheme)) {
            throw new InvalidArgumentException(\sprintf('The "%s" proxy scheme is not supported by the stream handler.', Psr7\DiagnosticValue::escape($scheme)));
        }

        // A recognized SSL/TLS transport may still be absent from this build
        // (tls:// without OpenSSL, tlsv1.3:// on OpenSSL < 1.1.1); that is
        // build-specific, so it is a RequestException, distinct from the caller
        // error above. http maps to tcp and tcp is always registered, so only
        // the SSL/TLS family reaches the stream_get_transports() lookup.
        if (!\in_array($scheme, ['http', 'tcp'], true) && !\in_array($scheme, \stream_get_transports(), true)) {
            throw new RequestException(\sprintf('The "%s" proxy transport is not available in this PHP build.', Psr7\DiagnosticValue::escape($scheme)), $request);
        }

        $parsed = $this->parseProxy($proxyUri, $scheme);

        // PHP streams do not expose separate proxy and origin header lists;
        // the wrapper's CONNECT handling copies one textual match out of a
        // generic user-header buffer and forwards the rest to the tunneled
        // origin, so arbitrary first-class Proxy-Authorization values cannot
        // be routed safely. Fail closed before any stream is created.
        if ($request->hasHeader('Proxy-Authorization')) {
            throw new InvalidArgumentException('Proxy-Authorization request headers are not supported through the stream handler; configure credentials in the proxy URI or use a cURL handler.');
        }

        $context['http']['proxy'] = $parsed['proxy'];

        if ($parsed['auth']) {
            if (!isset($context['http']['header'])) {
                $context['http']['header'] = '';
            }
            $context['http']['header'] .= "\r\nProxy-Authorization: {$parsed['auth']}";
        }
    }

    /**
     * Whether the scheme is a TCP-family transport name that can carry HTTP
     * for a proxy (tcp, ssl, tls, tlsv1.*), regardless of build support.
     */
    private static function isRawTransportName(string $scheme): bool
    {
        return \in_array($scheme, ['tcp', 'ssl', 'tls'], true)
            || \preg_match('/^tlsv\d+(?:\.\d+)?$/D', $scheme) === 1;
    }

    /**
     * Parses the given proxy URL to make it compatible with the format PHP's
     * stream context expects.
     */
    private function parseProxy(string $url, string $scheme): array
    {
        // applyProxy() has already validated the scheme, so only an http
        // proxy needs translating to the tcp:// form the wrapper expects; a
        // port-less proxy defaults to 1080 to match libcurl's HTTP default.
        if ($scheme !== 'http') {
            return [
                'proxy' => $url,
                'auth' => null,
            ];
        }

        $parsed = \parse_url(\strpos($url, '://') === false ? 'http://'.$url : $url);
        if (\is_array($parsed) && isset($parsed['host'])) {
            $port = $parsed['port'] ?? 1080;
            $user = $parsed['user'] ?? '';
            $pass = $parsed['pass'] ?? '';
            $auth = ($user !== '' || $pass !== '') ? 'Basic '.\base64_encode("{$user}:{$pass}") : null;

            return [
                'proxy' => "tcp://{$parsed['host']}:{$port}",
                'auth' => $auth,
            ];
        }

        return [
            'proxy' => $url,
            'auth' => null,
        ];
    }

    /**
     * @param mixed $value as passed via Request transfer options.
     */
    private function applyCryptoMethodOption(
        #[\SensitiveParameter]
        array &$context,
        $value
    ): void {
        $context['ssl']['min_proto_version'] = TlsVersion::streamProtocolVersion('crypto_method', $value);
    }

    /**
     * @param mixed $value as passed via Request transfer options.
     */
    private function applyCryptoMethodMaxOption(
        #[\SensitiveParameter]
        array &$context,
        $value
    ): void {
        $context['ssl']['max_proto_version'] = TlsVersion::streamProtocolVersion('crypto_method_max', $value);
    }

    private static function assertTlsVersionRangeForOptions(
        #[\SensitiveParameter]
        RequestInterface $request,
        #[\SensitiveParameter]
        array $options
    ): void {
        if (!isset($options['crypto_method_max'])) {
            return;
        }

        $cryptoMethod = $options['crypto_method'] ?? null;
        if ($cryptoMethod === null && 'https' === $request->getUri()->getScheme()) {
            $cryptoMethod = \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        }

        TlsVersion::assertRange($cryptoMethod, $options['crypto_method_max']);
    }

    /**
     * @param mixed $value as passed via Request transfer options.
     */
    private function applyVerifyOption(
        #[\SensitiveParameter]
        array &$context,
        $value
    ): void {
        if ($value === false) {
            $context['ssl']['verify_peer'] = false;
            $context['ssl']['verify_peer_name'] = false;

            return;
        }

        if (\is_string($value)) {
            $context['ssl']['cafile'] = $value;
            if (!\file_exists($value)) {
                throw new \RuntimeException(\sprintf('SSL CA bundle not found: %s', Psr7\DiagnosticValue::escape($value)));
            }
        } elseif ($value !== true) {
            throw new InvalidArgumentException('Invalid verify request option');
        }

        $context['ssl']['verify_peer'] = true;
        $context['ssl']['verify_peer_name'] = true;
        $context['ssl']['allow_self_signed'] = false;
    }

    /**
     * @param mixed $value as passed via Request transfer options.
     */
    private function applyCertOption(
        #[\SensitiveParameter]
        array &$context,
        #[\SensitiveParameter]
        $value
    ): void {
        [$value, $passphrase] = self::normalizeTlsFileOption('cert', $value);

        if (!\file_exists($value)) {
            throw new \RuntimeException(\sprintf('SSL certificate not found: %s', Psr7\DiagnosticValue::escape($value)));
        }

        self::setTlsPassphrase($context, $passphrase, 'cert');
        $context['ssl']['local_cert'] = $value;
    }

    /**
     * @param mixed $value as passed via Request transfer options.
     */
    private function applyCertTypeOption($value): void
    {
        self::assertStreamTlsType('cert_type', $value);
    }

    /**
     * @param mixed $value as passed via Request transfer options.
     */
    private function applySslKeyOption(
        #[\SensitiveParameter]
        array &$context,
        #[\SensitiveParameter]
        $value
    ): void {
        [$value, $passphrase] = self::normalizeTlsFileOption('ssl_key', $value);

        if (!\file_exists($value)) {
            throw new \RuntimeException(\sprintf('SSL private key not found: %s', Psr7\DiagnosticValue::escape($value)));
        }

        self::setTlsPassphrase($context, $passphrase, 'ssl_key');
        $context['ssl']['local_pk'] = $value;
    }

    /**
     * @param mixed $value as passed via Request transfer options.
     */
    private function applySslKeyTypeOption($value): void
    {
        self::assertStreamTlsType('ssl_key_type', $value);
    }

    /**
     * @param mixed $value as passed via Request transfer options.
     */
    private function applyProgressOption($value, array &$params): void
    {
        if (!\is_callable($value)) {
            throw new InvalidArgumentException('progress client option must be callable');
        }

        self::addNotification(
            $params,
            static function ($code, $a, $b, $c, $transferred, $total) use ($value): void {
                if ($code == \STREAM_NOTIFY_PROGRESS) {
                    // The upload progress cannot be determined. Use 0 for cURL compatibility:
                    // https://curl.se/libcurl/c/CURLOPT_PROGRESSFUNCTION.html
                    $value(
                        TransferByteCounter::progressValueToInt($total),
                        TransferByteCounter::progressValueToInt($transferred),
                        0,
                        0
                    );
                }
            }
        );
    }

    /**
     * @param mixed $value as passed via Request transfer options.
     */
    private function applyDebugOption(
        #[\SensitiveParameter]
        RequestInterface $request,
        $value,
        array &$params
    ): void {
        if ($value === false) {
            return;
        }

        static $map = [
            \STREAM_NOTIFY_CONNECT => 'CONNECT',
            \STREAM_NOTIFY_AUTH_REQUIRED => 'AUTH_REQUIRED',
            \STREAM_NOTIFY_AUTH_RESULT => 'AUTH_RESULT',
            \STREAM_NOTIFY_MIME_TYPE_IS => 'MIME_TYPE_IS',
            \STREAM_NOTIFY_FILE_SIZE_IS => 'FILE_SIZE_IS',
            \STREAM_NOTIFY_REDIRECTED => 'REDIRECTED',
            \STREAM_NOTIFY_PROGRESS => 'PROGRESS',
            \STREAM_NOTIFY_FAILURE => 'FAILURE',
            \STREAM_NOTIFY_COMPLETED => 'COMPLETED',
            \STREAM_NOTIFY_RESOLVE => 'RESOLVE',
        ];
        static $args = ['severity', 'message', 'message_code', 'bytes_transferred', 'bytes_max'];

        $value = Utils::debugResource($value);
        $ident = $request->getMethod().' '.$request->getUri()->withFragment('');
        self::addNotification(
            $params,
            static function (int $code, ...$passed) use ($ident, $value, $map, $args): void {
                \fprintf($value, '<%s> [%s] ', $ident, $map[$code]);
                foreach (\array_filter($passed) as $i => $v) {
                    \fwrite($value, $args[$i].': "'.$v.'" ');
                }
                \fwrite($value, "\n");
            }
        );
    }

    private static function addNotification(array &$params, callable $notify): void
    {
        // Wrap the existing function if needed.
        if (!isset($params['notification'])) {
            $params['notification'] = $notify;
        } else {
            $params['notification'] = self::callArray([
                $params['notification'],
                $notify,
            ]);
        }
    }

    private static function callArray(array $functions): callable
    {
        return static function (...$args) use ($functions): void {
            foreach ($functions as $fn) {
                $fn(...$args);
            }
        };
    }
}
