<?php

namespace GuzzleHttp\Handler;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise as P;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7;
use GuzzleHttp\TransferStats;
use GuzzleHttp\TransportSharing;
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
    private const CONNECTION_ERRORS = [
        'php_network_getaddresses:',
        'getaddrinfo',
        'gethostbyname failed',
        'Connection refused',
        'No connection could be made because the target machine actively refused it',
        "couldn't connect to host", // error on HHVM
        'connection attempt failed',
        'connect() failed',
        'Connection timed out',
        'Operation timed out',
        'Network is unreachable',
        'No route to host',
        'Host is unreachable',
        'Host is down',
        'Cannot connect to HTTPS server through proxy',
    ];

    /**
     * @var array
     */
    private $lastHeaders = [];

    /**
     * Sends an HTTP request.
     *
     * @param RequestInterface $request Request to send.
     * @param array            $options Request transfer options.
     */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        // Sleep if there is a delay specified.
        if (isset($options['delay'])) {
            \usleep($options['delay'] * 1000);
        }

        $protocolVersion = $request->getProtocolVersion();

        if ('' === $protocolVersion) {
            \trigger_deprecation('guzzlehttp/guzzle', '7.11', 'Sending a request with an empty protocol version is deprecated; guzzlehttp/guzzle 8.0 will reject empty protocol versions.');

            $protocolVersion = '1.1';
            $request = Psr7\Utils::modifyRequest($request, ['version' => $protocolVersion]);
        }

        if ('1.0' !== $protocolVersion && '1.1' !== $protocolVersion) {
            throw new ConnectException(sprintf('HTTP/%s is not supported by the stream handler.', $protocolVersion), $request);
        }

        $startTime = isset($options['on_stats']) ? Utils::currentTime() : null;

        self::triggerUnsupportedRequestOptionDeprecations($request, $options);

        try {
            // Does not support the expect header.
            $request = $request->withoutHeader('Expect');

            // Append a content-length header if body size is zero to match
            // the behavior of `CurlHandler`
            if (
                (
                    0 === \strcasecmp('PUT', $request->getMethod())
                    || 0 === \strcasecmp('POST', $request->getMethod())
                )
                && 0 === $request->getBody()->getSize()
            ) {
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
            if (self::isConnectionError($e->getMessage())) {
                $e = new ConnectException($e->getMessage(), $request, $e);
            } else {
                $e = $e instanceof RequestException ? $e : new RequestException($e->getMessage(), $request, null, $e);
            }
            $this->invokeStats($options, $request, $startTime, null, $e);

            return P\Create::rejectionFor($e);
        }
    }

    private static function isConnectionError(string $message): bool
    {
        foreach (self::CONNECTION_ERRORS as $connectionError) {
            if (false !== \strpos($message, $connectionError)) {
                return true;
            }
        }

        return false;
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

        if (\strcasecmp('HEAD', $request->getMethod())) {
            $sink = $this->createSink($stream, $options);
        }

        try {
            $response = new Psr7\Response($status, $headers, $sink, $ver, $reason);
        } catch (\Throwable $e) {
            return $this->rejectResponseCreation($options, $request, $startTime, $e);
        }

        if (isset($options['on_headers'])) {
            try {
                $options['on_headers']($response);
            } catch (\Throwable $e) {
                return P\Create::rejectionFor(
                    new RequestException('An error was encountered during the on_headers event', $request, $response, $e)
                );
            }
        }

        // Do not drain when the request is a HEAD request because they have
        // no body.
        if ($sink !== $stream) {
            $this->drain($stream, $sink, $response->getHeaderLine('Content-Length'));
        }

        $this->invokeStats($options, $request, $startTime, $response, null);

        return new FulfilledPromise($response);
    }

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

        return P\Create::rejectionFor($reason);
    }

    private function createSink(StreamInterface $stream, array $options): StreamInterface
    {
        if (!empty($options['stream'])) {
            return $stream;
        }

        $sink = $options['sink'] ?? Psr7\Utils::tryFopen('php://temp', 'r+');

        return \is_string($sink) ? new Psr7\LazyOpenStream($sink, 'w+') : Psr7\Utils::streamFor($sink);
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

                    // The decoded length cannot be known without inflating the
                    // stream, so keep the original length for inspection and
                    // drop the now-unknown Content-Length header.
                    if (isset($normalizedKeys['content-length'])) {
                        $headers['x-encoded-content-length'] = $headers[$normalizedKeys['content-length']];
                        unset($headers[$normalizedKeys['content-length']]);
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
    private function drain(StreamInterface $source, StreamInterface $sink, string $contentLength): StreamInterface
    {
        // If a content-length header is provided, then stop reading once
        // that number of bytes has been read. This can prevent infinitely
        // reading from a stream when dealing with servers that do not honor
        // Connection: Close headers.
        Psr7\Utils::copyToStream(
            $source,
            $sink,
            (\strlen($contentLength) > 0 && (int) $contentLength > 0) ? (int) $contentLength : -1
        );

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

        if (isset($options['on_headers']) && !\is_callable($options['on_headers'])) {
            throw new \InvalidArgumentException('on_headers must be callable');
        }

        if (!empty($options)) {
            foreach ($options as $key => $value) {
                $method = "add_{$key}";
                if (isset($methods[$method])) {
                    $this->{$method}($request, $context, $value, $params);
                }
            }
        }

        if (isset($options['stream_context'])) {
            if (!\is_array($options['stream_context'])) {
                throw new \InvalidArgumentException('stream_context must be an array');
            }
            $context = \array_replace_recursive($context, $options['stream_context']);
        }

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
            function () use ($uri, $contextResource, $context, $options, $request) {
                $resource = @\fopen((string) $uri, 'r', false, $contextResource);

                // See https://wiki.php.net/rfc/deprecations_php_8_5#deprecate_the_http_response_header_predefined_variable
                if (function_exists('http_get_last_response_headers')) {
                    $http_response_header = \http_get_last_response_headers();
                }

                $this->lastHeaders = $http_response_header ?? [];

                if (false === $resource) {
                    throw new ConnectException(sprintf('Connection refused for URI %s', $uri), $request, null, $context);
                }

                if (isset($options['read_timeout'])) {
                    $readTimeout = $options['read_timeout'];
                    $sec = (int) $readTimeout;
                    $usec = ($readTimeout - $sec) * 100000;
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

    private static function triggerUnsupportedRequestOptionDeprecations(RequestInterface $request, array $options): void
    {
        if (\array_key_exists('transport_sharing', $options)) {
            $transportSharingMode = CurlShareHandleState::normalizeMode($options['transport_sharing'], 'transport_sharing');

            if ($transportSharingMode === TransportSharing::HANDLER_REQUIRE) {
                throw new \InvalidArgumentException('The "transport_sharing" option requires transport sharing, but the stream handler does not support it.');
            }
        }

        if (
            \array_key_exists('curl', $options)
            && $options['curl'] !== null
            && $options['curl'] !== []
            && !self::isCurlOptionGeneratedByAuth($options)
        ) {
            \trigger_deprecation('guzzlehttp/guzzle', '7.11', 'Passing the "curl" request option to the stream handler is deprecated; guzzlehttp/guzzle 8.0 will reject this option because the stream handler ignores cURL options.');
        }

        if (self::usesDigestAuth($options)) {
            \trigger_deprecation('guzzlehttp/guzzle', '7.11', 'Passing digest authentication to the stream handler is deprecated; guzzlehttp/guzzle 8.0 will reject digest authentication with the stream handler because it is only supported by cURL handlers.');
        }

        if (\array_key_exists('expect', $options) && $options['expect'] !== false && $request->hasHeader('Expect')) {
            \trigger_deprecation('guzzlehttp/guzzle', '7.11', 'Passing the "expect" request option to the stream handler is deprecated when it adds an Expect header; guzzlehttp/guzzle 8.0 will reject this option because the stream handler does not support Expect: 100-Continue.');
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
    private function add_proxy(RequestInterface $request, array &$options, $value, array &$params): void
    {
        $uri = null;

        if (!\is_array($value)) {
            $uri = $value;
        } else {
            $scheme = $request->getUri()->getScheme();
            if (isset($value[$scheme])) {
                if (
                    !isset($value['no'])
                    || !Utils::isUriInNoProxy($request->getUri(), $value['no'])
                ) {
                    $uri = $value[$scheme];
                }
            }
        }

        if (!$uri) {
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
        if ($value > 0) {
            $options['http']['timeout'] = $value;
        }
    }

    /**
     * @param mixed $value as passed via Request transfer options.
     */
    private function add_crypto_method(RequestInterface $request, array &$options, $value, array &$params): void
    {
        if (
            $value === \STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT
            || $value === \STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT
            || $value === \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
            || (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') && $value === \STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)
        ) {
            $options['http']['crypto_method'] = $value;

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
        [$value, $passphrase] = self::normalizeTlsFileOption('cert', $value);

        if (!\file_exists($value)) {
            throw new \RuntimeException("SSL certificate not found: {$value}");
        }

        self::setTlsPassphrase($options, $passphrase, 'cert');
        $options['ssl']['local_cert'] = $value;
    }

    /**
     * @param mixed $value as passed via Request transfer options.
     */
    private function add_cert_type(RequestInterface $request, array &$options, $value, array &$params): void
    {
        self::assertStreamTlsType('cert_type', $value);
    }

    /**
     * @param mixed $value as passed via Request transfer options.
     */
    private function add_ssl_key(RequestInterface $request, array &$options, $value, array &$params): void
    {
        [$value, $passphrase] = self::normalizeTlsFileOption('ssl_key', $value);

        if (!\file_exists($value)) {
            throw new \RuntimeException("SSL private key not found: {$value}");
        }

        self::setTlsPassphrase($options, $passphrase, 'ssl_key');
        $options['ssl']['local_pk'] = $value;
    }

    /**
     * @param mixed $value as passed via Request transfer options.
     */
    private function add_ssl_key_type(RequestInterface $request, array &$options, $value, array &$params): void
    {
        self::assertStreamTlsType('ssl_key_type', $value);
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
