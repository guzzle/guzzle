<?php

namespace GuzzleHttp\Handler;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TimeoutException;
use GuzzleHttp\Promise as P;
use GuzzleHttp\Promise\PromiseInterface;
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
 *
 * @final
 */
class StreamHandler
{
    /**
     * @var array
     */
    private $lastHeaders = [];

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
        // Sleep if there is a delay specified.
        if (isset($options['delay'])) {
            \usleep($options['delay'] * 1000);
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

        $startTime = isset($options['on_stats']) ? Utils::currentTime() : null;

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

    private function invokeStats(
        array $options,
        RequestInterface $request,
        ?float $startTime,
        ?ResponseInterface $response = null,
        ?\Throwable $error = null
    ): void {
        if (isset($options['on_stats'])) {
            $stats = new TransferStats($request, $response, Utils::currentTime() - $startTime, $error, []);
            ($options['on_stats'])($stats);
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
                /** @var PromiseInterface<ResponseInterface, mixed> */
                return P\Create::rejectionFor(
                    new RequestException('An error was encountered during the on_headers event', $request, $response, $e)
                );
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
     * @param callable $callback Callable that returns stream resource
     *
     * @return resource
     *
     * @throws \RuntimeException on error
     */
    private function createResource(callable $callback)
    {
        $errors = [];
        \set_error_handler(static function ($_, $msg, $file, $line) use (&$errors): bool {
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
        static $methods;
        if (!$methods) {
            $methods = \array_flip(\get_class_methods(__CLASS__));
        }

        if (!\in_array($request->getUri()->getScheme(), ['http', 'https'])) {
            throw new RequestException(\sprintf("The scheme '%s' is not supported.", $request->getUri()->getScheme()), $request);
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

        if (!empty($options)) {
            foreach ($options as $key => $value) {
                $method = "add_{$key}";
                if (isset($methods[$method])) {
                    $this->{$method}($request, $context, $value, $params);
                }
            }
        }

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

    /**
     * @param mixed $value as passed via Request transfer options.
     */
    private function add_proxy(RequestInterface $request, array &$options, $value, array &$params): void
    {
        $uri = null;

        if (!\is_array($value)) {
            if (!\is_string($value)) {
                throw new \InvalidArgumentException('proxy must be a string or array');
            }

            $uri = $value;
        } else {
            $scheme = $request->getUri()->getScheme();
            if (isset($value[$scheme])) {
                if (!\is_string($value[$scheme])) {
                    throw new \InvalidArgumentException('proxy values must be strings');
                }

                $noProxy = isset($value['no']) ? Utils::normalizeNoProxy($value['no']) : [];
                if ($noProxy === [] || !Utils::isUriInNoProxy($request->getUri(), $noProxy)) {
                    $uri = $value[$scheme];
                }
            }
        }

        if ($uri === null || $uri === '') {
            return;
        }

        $parsed = $this->parse_proxy($uri);
        $options['http']['proxy'] = $parsed['proxy'];

        if ($parsed['auth']) {
            if (!isset($options['http']['header'])) {
                $options['http']['header'] = [];
            }
            $options['http']['header'] .= "\r\nProxy-Authorization: {$parsed['auth']}";
        }
    }

    /**
     * Parses the given proxy URL to make it compatible with the format PHP's stream context expects.
     */
    private function parse_proxy(string $url): array
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
    private function add_timeout(RequestInterface $request, array &$options, $value, array &$params): void
    {
        $timeout = Utils::timeoutToMilliseconds($value, 'timeout');

        if ($timeout > 0) {
            $options['http']['timeout'] = $timeout / 1000;
        }
    }

    /**
     * @param mixed $value as passed via Request transfer options.
     */
    private function add_crypto_method(RequestInterface $request, array &$options, $value, array &$params): void
    {
        if ($value === \STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT) {
            $options['ssl']['min_proto_version'] = \STREAM_CRYPTO_PROTO_TLSv1_0;

            return;
        }

        if ($value === \STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT) {
            $options['ssl']['min_proto_version'] = \STREAM_CRYPTO_PROTO_TLSv1_1;

            return;
        }

        if ($value === \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT) {
            $options['ssl']['min_proto_version'] = \STREAM_CRYPTO_PROTO_TLSv1_2;

            return;
        }

        if ($value === \STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT) {
            $options['ssl']['min_proto_version'] = \STREAM_CRYPTO_PROTO_TLSv1_3;

            return;
        }

        throw new \InvalidArgumentException('Invalid crypto_method request option: unknown version provided');
    }

    /**
     * @param mixed $value as passed via Request transfer options.
     */
    private function add_verify(RequestInterface $request, array &$options, $value, array &$params): void
    {
        if ($value === false) {
            $options['ssl']['verify_peer'] = false;
            $options['ssl']['verify_peer_name'] = false;

            return;
        }

        if (\is_string($value)) {
            $options['ssl']['cafile'] = $value;
            if (!\file_exists($value)) {
                throw new \RuntimeException("SSL CA bundle not found: $value");
            }
        } elseif ($value !== true) {
            throw new \InvalidArgumentException('Invalid verify request option');
        }

        $options['ssl']['verify_peer'] = true;
        $options['ssl']['verify_peer_name'] = true;
        $options['ssl']['allow_self_signed'] = false;
    }

    /**
     * @param mixed $value as passed via Request transfer options.
     */
    private function add_cert(RequestInterface $request, array &$options, $value, array &$params): void
    {
        if (\is_array($value)) {
            if (!isset($value[0]) || !\is_string($value[0])) {
                throw new \InvalidArgumentException('Invalid cert request option');
            }
            if (isset($value[1])) {
                if (!\is_string($value[1])) {
                    throw new \InvalidArgumentException('Invalid cert request option');
                }
                $options['ssl']['passphrase'] = $value[1];
            }
            $value = $value[0];
        }

        if (!\is_string($value)) {
            throw new \InvalidArgumentException('Invalid cert request option');
        }

        if (!\file_exists($value)) {
            throw new \RuntimeException("SSL certificate not found: {$value}");
        }

        $options['ssl']['local_cert'] = $value;
    }

    /**
     * @param mixed $value as passed via Request transfer options.
     */
    private function add_progress(RequestInterface $request, array &$options, $value, array &$params): void
    {
        if (!\is_callable($value)) {
            throw new \InvalidArgumentException('progress client option must be callable');
        }

        self::addNotification(
            $params,
            static function ($code, $a, $b, $c, $transferred, $total) use ($value) {
                if ($code == \STREAM_NOTIFY_PROGRESS) {
                    // The upload progress cannot be determined. Use 0 for cURL compatibility:
                    // https://curl.se/libcurl/c/CURLOPT_PROGRESSFUNCTION.html
                    $value($total, $transferred, 0, 0);
                }
            }
        );
    }

    /**
     * @param mixed $value as passed via Request transfer options.
     */
    private function add_debug(RequestInterface $request, array &$options, $value, array &$params): void
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
        return static function (...$args) use ($functions) {
            foreach ($functions as $fn) {
                $fn(...$args);
            }
        };
    }
}
