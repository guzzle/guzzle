<?php

declare(strict_types=1);

namespace GuzzleHttp\Handler;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TimeoutException;
use GuzzleHttp\Promise as P;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\ProxyOptions;
use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\Exception\TimeoutException as Psr7TimeoutException;
use GuzzleHttp\TransferStats;
use GuzzleHttp\Utils;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

/**
 * HTTP handler that uses PHP's HTTP stream wrapper.
 */
final class StreamHandler
{
    private array $lastHeaders = [];

    private ?\Throwable $onStatsException = null;

    /**
     * Sends an HTTP request.
     *
     * @param RequestInterface $request Request to send.
     * @param array            $options Request transfer options.
     *
     * @return PromiseInterface<ResponseInterface, mixed>
     */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $this->onStatsException = null;

        // Sleep if there is a delay specified.
        if (isset($options['delay'])) {
            \usleep((int) ($options['delay'] * 1000));
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
            throw new \InvalidArgumentException('on_stats must be callable');
        }

        $startTime = isset($options['on_stats']) ? Utils::currentTime() : null;

        self::rejectUnsupportedRequestOptions($request, $options);

        try {
            // Does not support the expect header.
            $request = $request->withoutHeader('Expect');

            // Append a content-length header if body size is zero to match
            // the behavior of `CurlHandler`
            if (($request->getMethod() === 'PUT' || $request->getMethod() === 'POST') && 0 === $request->getBody()->getSize()) {
                $request = $request->withHeader('Content-Length', '0');
            }

            return $this->createResponse(
                $request,
                $options,
                $this->createStream($request, $options),
                $startTime
            );
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\Exception $e) {
            if ($this->isOnStatsException($e)) {
                throw $e;
            }

            // Determine if the error was a networking error.
            $message = $e->getMessage();
            // This list can probably get more comprehensive.
            if (!$e instanceof ConnectException) {
                if (false !== \strpos($message, 'getaddrinfo') // DNS lookup failed
                    || false !== \strpos($message, 'Connection refused')
                    || false !== \strpos($message, "couldn't connect to host") // error on HHVM
                    || false !== \strpos($message, 'connection attempt failed')
                ) {
                    $e = new ConnectException($e->getMessage(), $request, $e);
                } else {
                    $e = RequestException::wrapException($request, $e);
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

    private function invokeStats(
        array $options,
        RequestInterface $request,
        ?float $startTime,
        ?ResponseInterface $response = null,
        ?\Throwable $error = null
    ): void {
        if (isset($options['on_stats'])) {
            $stats = new TransferStats($request, $response, Utils::currentTime() - $startTime, $error, []);
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
    private function createResponse(RequestInterface $request, array $options, $stream, ?float $startTime): PromiseInterface
    {
        $hdrs = $this->lastHeaders;
        $this->lastHeaders = [];

        try {
            [$ver, $status, $reason, $headers] = HeaderProcessor::parseHeaders($hdrs);
        } catch (\Throwable $e) {
            return $this->rejectResponseCreation($options, $request, $startTime, $e);
        }

        [$stream, $headers] = $this->checkDecode($options, $headers, $stream);
        $stream = Psr7\Utils::streamFor($stream);
        $sink = $stream;

        if ($request->getMethod() !== 'HEAD') {
            $sink = $this->createSink($stream, $options);
        }

        try {
            $response = new Psr7\Response($status, $headers, $sink, $ver, $reason);
        } catch (\Throwable $e) {
            return $this->rejectResponseCreation($options, $request, $startTime, $e);
        }

        if (isset($options['on_headers'])) {
            try {
                $options['on_headers']($response, $request);
            } catch (\Throwable $e) {
                $reason = new RequestException('An error was encountered during the on_headers event', $request, $response, $e);
                $this->invokeStats($options, $request, $startTime, $response, $reason);

                /** @var PromiseInterface<ResponseInterface, mixed> */
                return P\Create::rejectionFor($reason);
            }
        }

        // Do not drain when the request is a HEAD request because they have
        // no body.
        if ($sink !== $stream) {
            $this->drain($request, $stream, $sink, $response->getHeaderLine('Content-Length'));
        }

        $this->invokeStats($options, $request, $startTime, $response, null);

        /** @var PromiseInterface<ResponseInterface, mixed> */
        return P\Create::promiseFor($response);
    }

    /**
     * @return PromiseInterface<ResponseInterface, mixed>
     */
    private function rejectResponseCreation(
        array $options,
        RequestInterface $request,
        ?float $startTime,
        \Throwable $previous
    ): PromiseInterface {
        $reason = new RequestException(
            'An error was encountered while creating the response',
            $request,
            null,
            $previous
        );

        $this->invokeStats($options, $request, $startTime, null, $reason);

        /** @var PromiseInterface<ResponseInterface, mixed> */
        return P\Create::rejectionFor($reason);
    }

    private function createSink(StreamInterface $stream, array $options): StreamInterface
    {
        if (!empty($options['stream'])) {
            return $stream;
        }

        $hasSink = isset($options['sink']);
        $sink = $hasSink ? $options['sink'] : Psr7\Utils::tryFopen('php://temp', 'r+');

        if ($hasSink && \is_resource($sink)) {
            return self::streamForResourceSink($sink);
        }

        return \is_string($sink) ? new Psr7\LazyOpenStream($sink, 'w+') : Psr7\Utils::streamFor($sink);
    }

    /**
     * Creates a response body stream for a caller-owned sink resource.
     *
     * Closing the response body must detach Guzzle's wrapper without closing
     * the original PHP resource.
     *
     * @param resource $resource
     */
    private static function streamForResourceSink($resource): StreamInterface
    {
        $stream = Psr7\Utils::streamFor($resource);

        return Psr7\FnStream::decorate($stream, [
            'close' => static function () use ($stream): void {
                $stream->detach();
            },
        ]);
    }

    /**
     * @param resource $stream
     */
    private function checkDecode(array $options, array $headers, $stream): array
    {
        // Automatically decode responses when instructed.
        if (!empty($options['decode_content'])) {
            $normalizedKeys = Utils::normalizeHeaderKeys($headers);
            if (isset($normalizedKeys['content-encoding'])) {
                $encoding = $headers[$normalizedKeys['content-encoding']];
                if ($encoding[0] === 'gzip' || $encoding[0] === 'deflate') {
                    $stream = new Psr7\InflateStream(Psr7\Utils::streamFor($stream));
                    $headers['x-encoded-content-encoding'] = $headers[$normalizedKeys['content-encoding']];

                    // Remove content-encoding header
                    unset($headers[$normalizedKeys['content-encoding']]);

                    // Fix content-length header
                    if (isset($normalizedKeys['content-length'])) {
                        $headers['x-encoded-content-length'] = $headers[$normalizedKeys['content-length']];
                        $length = (int) $stream->getSize();
                        if ($length === 0) {
                            unset($headers[$normalizedKeys['content-length']]);
                        } else {
                            $headers[$normalizedKeys['content-length']] = [(string) $length];
                        }
                    }
                }
            }
        }

        return [$stream, $headers];
    }

    /**
     * Drains the source stream into the "sink" client option.
     *
     * @param string $contentLength Header specifying the amount of
     *                              data to read.
     *
     * @throws \RuntimeException when the sink option is invalid.
     */
    private function drain(
        RequestInterface $request,
        StreamInterface $source,
        StreamInterface $sink,
        string $contentLength
    ): StreamInterface {
        // If a content-length header is provided, then stop reading once
        // that number of bytes has been read. This can prevent infinitely
        // reading from a stream when dealing with servers that do not honor
        // Connection: Close headers.
        try {
            Psr7\Utils::copyToStream(
                $source,
                $sink,
                (\strlen($contentLength) > 0 && (int) $contentLength > 0) ? (int) $contentLength : -1
            );
        } catch (Psr7TimeoutException $e) {
            throw new TimeoutException(
                'The stream handler timed out while transferring the response body',
                $request,
                $e,
                ['timed_out' => true]
            );
        }

        $sink->seek(0);
        $source->close();

        return $sink;
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
            $message = 'Error creating resource: ';
            foreach ($errors as $err) {
                foreach ($err as $key => $value) {
                    $message .= "[$key] $value".\PHP_EOL;
                }
            }
            throw new \RuntimeException(\trim($message));
        }

        return $resource;
    }

    /**
     * @return resource
     */
    private function createStream(RequestInterface $request, array $options)
    {
        $scheme = $request->getUri()->getScheme();
        if (!\in_array($scheme, ['http', 'https'], true)) {
            throw new RequestException(\sprintf("The scheme '%s' is not supported.", $scheme), $request);
        }

        $protocols = Utils::normalizeProtocols($options['protocols'] ?? ['http', 'https']);
        if (!\in_array($scheme, $protocols, true)) {
            throw new RequestException(\sprintf('The scheme "%s" is not allowed by the protocols request option.', $scheme), $request);
        }

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
        $context = $this->getDefaultContext($request);
        $streamContextHasTlsSettings = self::hasStreamContextTlsSettings($options);

        if (isset($options['on_headers']) && !\is_callable($options['on_headers'])) {
            throw new \InvalidArgumentException('on_headers must be callable');
        }

        $readTimeout = isset($options['read_timeout'])
            ? Utils::timeoutToMilliseconds($options['read_timeout'], 'read_timeout')
            : null;

        $this->applyHandlerOptions($request, $context, $options, $params);

        if (isset($options['stream_context'])) {
            $streamContext = $options['stream_context'];
            if (!\is_array($streamContext)) {
                throw new \InvalidArgumentException('stream_context must be an array');
            }
            $context = \array_replace_recursive($context, $streamContext);

            $sslContext = $streamContext['ssl'] ?? null;
            if ($streamContextHasTlsSettings && \is_array($sslContext) && !\array_key_exists('min_proto_version', $sslContext)) {
                unset($context['ssl']['min_proto_version']);
            }
        }

        $this->addDefaultTlsMinimum($request, $context);

        // Microsoft NTLM authentication only supported with curl handler
        if (isset($options['auth'][2]) && 'ntlm' === $options['auth'][2]) {
            throw new \InvalidArgumentException('Microsoft NTLM authentication only supported with curl handler');
        }

        $uri = $this->resolveHost($request, $options);

        $contextResource = $this->createResource(
            static function () use ($context, $params) {
                return \stream_context_create($context, $params);
            }
        );

        return $this->createResource(
            function () use ($uri, $contextResource, $context, $request, $readTimeout) {
                $resource = @\fopen((string) $uri, 'r', false, $contextResource);

                // See https://wiki.php.net/rfc/deprecations_php_8_5#deprecate_the_http_response_header_predefined_variable
                if (function_exists('http_get_last_response_headers')) {
                    $http_response_header = \http_get_last_response_headers();
                }

                $this->lastHeaders = $http_response_header ?? [];

                if (false === $resource) {
                    throw new ConnectException(sprintf('Connection refused for URI %s', $uri), $request, null, $context);
                }

                if ($readTimeout !== null) {
                    $sec = \intdiv($readTimeout, 1000);
                    $usec = ($readTimeout % 1000) * 1000;
                    \stream_set_timeout($resource, $sec, $usec);
                }

                return $resource;
            }
        );
    }

    private function applyHandlerOptions(RequestInterface $request, array &$context, array $options, array &$params): void
    {
        foreach ($options as $key => $value) {
            if ($key === 'proxy') {
                $this->applyProxyOption($request, $context, $value);
            } elseif ($key === 'timeout') {
                $this->applyTimeoutOption($context, $value);
            } elseif ($key === 'crypto_method') {
                $this->applyCryptoMethodOption($context, $value);
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

    private function resolveHost(RequestInterface $request, array $options): UriInterface
    {
        $uri = $request->getUri();

        if (isset($options['force_ip_resolve']) && !\filter_var($uri->getHost(), \FILTER_VALIDATE_IP)) {
            if ('v4' === $options['force_ip_resolve']) {
                $records = \dns_get_record($uri->getHost(), \DNS_A);
                if (false === $records || !isset($records[0]['ip'])) {
                    throw new ConnectException(\sprintf("Could not resolve IPv4 address for host '%s'", $uri->getHost()), $request);
                }

                return $uri->withHost($records[0]['ip']);
            }
            if ('v6' === $options['force_ip_resolve']) {
                $records = \dns_get_record($uri->getHost(), \DNS_AAAA);
                if (false === $records || !isset($records[0]['ipv6'])) {
                    throw new ConnectException(\sprintf("Could not resolve IPv6 address for host '%s'", $uri->getHost()), $request);
                }

                return $uri->withHost('['.$records[0]['ipv6'].']');
            }
        }

        return $uri;
    }

    private static function hasStreamContextTlsSettings(array $options): bool
    {
        if (!isset($options['stream_context']) || !\is_array($options['stream_context'])) {
            return false;
        }

        $sslContext = $options['stream_context']['ssl'] ?? null;
        if (!\is_array($sslContext)) {
            return false;
        }

        return \array_key_exists('crypto_method', $sslContext)
            || \array_key_exists('min_proto_version', $sslContext)
            || \array_key_exists('max_proto_version', $sslContext);
    }

    private function addDefaultTlsMinimum(RequestInterface $request, array &$context): void
    {
        if ('https' !== $request->getUri()->getScheme() || !isset($context['ssl']) || !\is_array($context['ssl'])) {
            return;
        }

        if (
            \array_key_exists('crypto_method', $context['ssl'])
            || \array_key_exists('min_proto_version', $context['ssl'])
            || \array_key_exists('max_proto_version', $context['ssl'])
        ) {
            return;
        }

        $context['ssl']['min_proto_version'] = \STREAM_CRYPTO_PROTO_TLSv1_2;
    }

    private function getDefaultContext(RequestInterface $request): array
    {
        $headers = '';
        foreach ($request->getHeaders() as $name => $value) {
            foreach ($value as $val) {
                $headers .= "$name: $val\r\n";
            }
        }

        $context = [
            'http' => [
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

        $body = (string) $request->getBody();

        if ('' !== $body) {
            $context['http']['content'] = $body;
            // Prevent the HTTP handler from adding a Content-Type header.
            if (!$request->hasHeader('Content-Type')) {
                $context['http']['header'] .= "Content-Type:\r\n";
            }
        }

        $context['http']['header'] = \rtrim($context['http']['header']);

        return $context;
    }

    private static function rejectUnsupportedRequestOptions(RequestInterface $request, array $options): void
    {
        if (
            \array_key_exists('curl_share', $options)
            && CurlShareHandleState::normalizeMode($options['curl_share'], 'curl_share') !== CurlShare::NONE
        ) {
            throw new \InvalidArgumentException('The "curl_share" option is not supported by the stream handler because the stream handler does not support cURL sharing.');
        }

        if (
            \array_key_exists('curl', $options)
            && $options['curl'] !== null
            && $options['curl'] !== []
            && !self::isCurlOptionGeneratedByAuth($options)
        ) {
            throw new \InvalidArgumentException('Passing the "curl" request option to the stream handler is not supported because the stream handler ignores cURL options.');
        }

        if (self::usesDigestAuth($options)) {
            throw new \InvalidArgumentException('Digest authentication is not supported by the stream handler because it is only supported by cURL handlers.');
        }

        if (\array_key_exists('expect', $options) && $options['expect'] !== false && $request->hasHeader('Expect')) {
            throw new \InvalidArgumentException('Passing the "expect" request option to the stream handler is not supported when it adds an Expect header because the stream handler does not support Expect: 100-Continue.');
        }
    }

    private static function isCurlOptionGeneratedByAuth(array $options): bool
    {
        if (!isset($options['curl']) || !\is_array($options['curl']) || !isset($options['auth'][2]) || !\is_string($options['auth'][2])) {
            return false;
        }

        if (!\defined('CURLOPT_HTTPAUTH') || !\defined('CURLOPT_USERPWD')) {
            return false;
        }

        $type = \strtolower($options['auth'][2]);
        if ($type === 'digest') {
            $httpAuth = \defined('CURLAUTH_DIGEST') ? \constant('CURLAUTH_DIGEST') : null;
        } elseif ($type === 'ntlm') {
            $httpAuth = \defined('CURLAUTH_NTLM') ? \constant('CURLAUTH_NTLM') : null;
        } else {
            return false;
        }

        return $httpAuth !== null
            && \count($options['curl']) === 2
            && isset($options['curl'][\CURLOPT_HTTPAUTH], $options['curl'][\CURLOPT_USERPWD])
            && $options['curl'][\CURLOPT_HTTPAUTH] === $httpAuth;
    }

    private static function usesDigestAuth(array $options): bool
    {
        return isset($options['auth'][2])
            && \is_string($options['auth'][2])
            && \strtolower($options['auth'][2]) === 'digest';
    }

    /**
     * @param mixed $value as passed via Request transfer options.
     *
     * @return array{0: string, 1: string|null}
     */
    private static function normalizeTlsFileOption(string $option, $value): array
    {
        $passphrase = null;

        if (\is_array($value)) {
            if (!isset($value[0]) || !\is_string($value[0])) {
                throw new \InvalidArgumentException(\sprintf('Invalid %s request option', $option));
            }
            if (isset($value[1])) {
                if (!\is_string($value[1])) {
                    throw new \InvalidArgumentException(\sprintf('Invalid %s request option', $option));
                }
                $passphrase = $value[1];
            }
            $value = $value[0];
        }

        if (!\is_string($value)) {
            throw new \InvalidArgumentException(\sprintf('Invalid %s request option', $option));
        }

        return [$value, $passphrase];
    }

    private static function setTlsPassphrase(array &$options, ?string $passphrase, string $option): void
    {
        if ($passphrase === null) {
            return;
        }

        if (isset($options['ssl']['passphrase']) && $options['ssl']['passphrase'] !== $passphrase) {
            throw new \InvalidArgumentException(\sprintf('Cannot use different passphrases for cert and ssl_key with the stream handler; %s conflicts with an existing TLS passphrase.', $option));
        }

        $options['ssl']['passphrase'] = $passphrase;
    }

    /**
     * @param mixed $value as passed via Request transfer options.
     */
    private static function assertStreamTlsType(string $option, $value): void
    {
        if (!\is_string($value) || $value === '') {
            throw new \InvalidArgumentException(\sprintf('%s must be a non-empty string', $option));
        }

        if (\strtoupper($value) !== 'PEM') {
            throw new \InvalidArgumentException(\sprintf('The stream handler only supports "PEM" for the %s request option.', $option));
        }
    }

    /**
     * @param mixed $value as passed via Request transfer options.
     */
    private function applyProxyOption(RequestInterface $request, array &$context, $value): void
    {
        $proxy = ProxyOptions::resolve($request->getUri(), $value);
        $proxyUri = $proxy->getProxy();
        if ($proxyUri === null) {
            return;
        }

        $parsed = $this->parseProxy($proxyUri);
        $context['http']['proxy'] = $parsed['proxy'];

        if ($parsed['auth']) {
            if (!isset($context['http']['header'])) {
                $context['http']['header'] = [];
            }
            $context['http']['header'] .= "\r\nProxy-Authorization: {$parsed['auth']}";
        }
    }

    /**
     * Parses the given proxy URL to make it compatible with the format PHP's stream context expects.
     */
    private function parseProxy(string $url): array
    {
        $parsed = \parse_url($url);

        if ($parsed !== false && isset($parsed['scheme']) && $parsed['scheme'] === 'http') {
            if (isset($parsed['host']) && isset($parsed['port'])) {
                $auth = null;
                if (isset($parsed['user']) && isset($parsed['pass'])) {
                    $auth = \base64_encode("{$parsed['user']}:{$parsed['pass']}");
                }

                return [
                    'proxy' => "tcp://{$parsed['host']}:{$parsed['port']}",
                    'auth' => $auth ? "Basic {$auth}" : null,
                ];
            }
        }

        // Return proxy as-is.
        return [
            'proxy' => $url,
            'auth' => null,
        ];
    }

    /**
     * @param mixed $value as passed via Request transfer options.
     */
    private function applyTimeoutOption(array &$context, $value): void
    {
        $timeout = Utils::timeoutToMilliseconds($value, 'timeout');

        if ($timeout > 0) {
            $context['http']['timeout'] = $timeout / 1000;
        }
    }

    /**
     * @param mixed $value as passed via Request transfer options.
     */
    private function applyCryptoMethodOption(array &$context, $value): void
    {
        if ($value === \STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT) {
            $context['ssl']['min_proto_version'] = \STREAM_CRYPTO_PROTO_TLSv1_0;

            return;
        }

        if ($value === \STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT) {
            $context['ssl']['min_proto_version'] = \STREAM_CRYPTO_PROTO_TLSv1_1;

            return;
        }

        if ($value === \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT) {
            $context['ssl']['min_proto_version'] = \STREAM_CRYPTO_PROTO_TLSv1_2;

            return;
        }

        if ($value === \STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT) {
            $context['ssl']['min_proto_version'] = \STREAM_CRYPTO_PROTO_TLSv1_3;

            return;
        }

        throw new \InvalidArgumentException('Invalid crypto_method request option: unknown version provided');
    }

    /**
     * @param mixed $value as passed via Request transfer options.
     */
    private function applyVerifyOption(array &$context, $value): void
    {
        if ($value === false) {
            $context['ssl']['verify_peer'] = false;
            $context['ssl']['verify_peer_name'] = false;

            return;
        }

        if (\is_string($value)) {
            $context['ssl']['cafile'] = $value;
            if (!\file_exists($value)) {
                throw new \RuntimeException("SSL CA bundle not found: $value");
            }
        } elseif ($value !== true) {
            throw new \InvalidArgumentException('Invalid verify request option');
        }

        $context['ssl']['verify_peer'] = true;
        $context['ssl']['verify_peer_name'] = true;
        $context['ssl']['allow_self_signed'] = false;
    }

    /**
     * @param mixed $value as passed via Request transfer options.
     */
    private function applyCertOption(array &$context, $value): void
    {
        [$value, $passphrase] = self::normalizeTlsFileOption('cert', $value);

        if (!\file_exists($value)) {
            throw new \RuntimeException("SSL certificate not found: {$value}");
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
    private function applySslKeyOption(array &$context, $value): void
    {
        [$value, $passphrase] = self::normalizeTlsFileOption('ssl_key', $value);

        if (!\file_exists($value)) {
            throw new \RuntimeException("SSL private key not found: {$value}");
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
            throw new \InvalidArgumentException('progress client option must be callable');
        }

        self::addNotification(
            $params,
            static function ($code, $a, $b, $c, $transferred, $total) use ($value): void {
                if ($code == \STREAM_NOTIFY_PROGRESS) {
                    // The upload progress cannot be determined. Use 0 for cURL compatibility:
                    // https://curl.se/libcurl/c/CURLOPT_PROGRESSFUNCTION.html
                    $value((int) $total, (int) $transferred, 0, 0);
                }
            }
        );
    }

    /**
     * @param mixed $value as passed via Request transfer options.
     */
    private function applyDebugOption(RequestInterface $request, $value, array &$params): void
    {
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
