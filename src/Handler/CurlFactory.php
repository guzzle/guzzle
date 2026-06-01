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
use GuzzleHttp\Promise as P;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\ProxyOptions;
use GuzzleHttp\Psr7\Exception\TimeoutException;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\LazyOpenStream;
use GuzzleHttp\TransferStats;
use GuzzleHttp\TransportSharing;
use GuzzleHttp\Utils;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

/**
 * Creates curl resources from a request
 */
final class CurlFactory implements CurlFactoryInterface
{
    private const CURL_CONNECTION_ERRORS = [
        5 => true,   // CURLE_COULDNT_RESOLVE_PROXY
        6 => true,   // CURLE_COULDNT_RESOLVE_HOST
        7 => true,   // CURLE_COULDNT_CONNECT
        35 => true,  // CURLE_SSL_CONNECT_ERROR
        51 => true,  // CURLE_PEER_FAILED_VERIFICATION before libcurl 7.62.0
        60 => true,  // CURLE_SSL_CACERT / modern CURLE_PEER_FAILED_VERIFICATION
        83 => true,  // CURLE_SSL_ISSUER_ERROR
        90 => true,  // CURLE_SSL_PINNEDPUBKEYNOTMATCH
        91 => true,  // CURLE_SSL_INVALIDCERTSTATUS
        96 => true,  // CURLE_QUIC_CONNECT_ERROR
        97 => true,  // CURLE_PROXY
        98 => true,  // CURLE_SSL_CLIENTCERT
        101 => true, // CURLE_ECH_REQUIRED
    ];

    private const CURL_NETWORK_ERRORS = [
        16 => true, // CURLE_HTTP2
        52 => true, // CURLE_GOT_NOTHING
        55 => true, // CURLE_SEND_ERROR
        56 => true, // CURLE_RECV_ERROR
        92 => true, // CURLE_HTTP2_STREAM
        95 => true, // CURLE_HTTP3
    ];

    private const CURL_RESPONSE_TRANSFER_ERRORS = [
        18 => true, // CURLE_PARTIAL_FILE
        61 => true, // CURLE_BAD_CONTENT_ENCODING
    ];

    private const CURL_CONNECT_TIMEOUT_ERRORS = [
        'Connection timed out',
        'Connection timeout',
        'Connection time-out',
        'Resolving timed out',
        'name lookup timed out',
        'Proxy CONNECT aborted due to timeout',
        'SSL connection timeout',
    ];

    /**
     * libcurl's CURL_READFUNC_ABORT value.
     */
    private const CURL_READFUNC_ABORT = 0x10000000;

    /**
     * libcurl's CURLE_SEND_FAIL_REWIND value.
     */
    private const CURLE_SEND_FAIL_REWIND = 65;

    /**
     * @var resource[]|\CurlHandle[]
     */
    private array $handles = [];

    /**
     * @var int Total number of idle handles to keep in cache
     */
    private int $maxHandles;

    private bool $closed = false;

    /**
     * @var resource|\CurlShareHandle|\CurlSharePersistentHandle|null
     */
    private $shareHandle;

    private string $shareMode;

    /**
     * @param int                                                       $maxHandles  Maximum number of idle handles.
     * @param resource|\CurlShareHandle|\CurlSharePersistentHandle|null $shareHandle
     */
    public function __construct(int $maxHandles, string $shareMode = TransportSharing::NONE, $shareHandle = null)
    {
        $this->maxHandles = $maxHandles;
        $this->shareMode = CurlShareHandleState::normalizeMode($shareMode, 'transport_sharing');

        if ($this->shareMode === TransportSharing::NONE && $shareHandle !== null) {
            throw new InvalidArgumentException('A cURL share handle cannot be provided when transport sharing is disabled.');
        }

        if ($this->shareMode !== TransportSharing::NONE && $shareHandle === null) {
            throw new InvalidArgumentException('A cURL share handle is required when transport sharing is enabled.');
        }

        if ($shareHandle !== null && !self::isCurlShareHandle($shareHandle)) {
            throw new InvalidArgumentException('A cURL share handle must be an instance of CurlShareHandle, CurlSharePersistentHandle, or a curl_share resource.');
        }

        $this->shareHandle = $shareHandle;
    }

    /**
     * @param mixed $value
     */
    private static function isCurlShareHandle($value): bool
    {
        if (\PHP_VERSION_ID < 80000) {
            return \is_resource($value) && \get_resource_type($value) === 'curl_share';
        }

        if ($value instanceof \CurlShareHandle) {
            return true;
        }

        return \class_exists('CurlSharePersistentHandle')
            && $value instanceof \CurlSharePersistentHandle;
    }

    public function create(RequestInterface $request, array $options): EasyHandle
    {
        $this->assertOpen();

        $protocolVersion = $request->getProtocolVersion();

        if ('' === $protocolVersion) {
            throw new RequestException('HTTP protocol version must not be empty.', $request);
        }

        if (1 !== \preg_match('/^\d+(?:\.\d+)?$/D', $protocolVersion)) {
            throw new RequestException('HTTP protocol version must be a valid HTTP version number.', $request);
        }

        CurlVersion::ensureSupported($request);

        if ('3' === $protocolVersion || '3.0' === $protocolVersion) {
            if (!CurlVersion::supportsHttp3()) {
                throw new RequestException('HTTP/3 is supported by the cURL handler, however the installed PHP cURL extension or libcurl does not support HTTP/3.', $request);
            }
        } elseif ('2' === $protocolVersion || '2.0' === $protocolVersion) {
            if (!CurlVersion::supportsHttp2()) {
                throw new RequestException('HTTP/2 is supported by the cURL handler, however libcurl is built without HTTP/2 support.', $request);
            }
        } elseif ('1.0' !== $protocolVersion && '1.1' !== $protocolVersion) {
            throw new RequestException(sprintf('HTTP/%s is not supported by the cURL handler.', $protocolVersion), $request);
        }

        if (isset($options['curl']['body_as_string'])) {
            $options['_body_as_string'] = $options['curl']['body_as_string'];
            unset($options['curl']['body_as_string']);
        }

        self::rejectUnsupportedRequestOptions($options);
        self::assertOnStatsCallable($options);
        $this->rejectRequestLevelShareConflict($options);
        $this->rejectPersistentRequireConnectionReuseConflicts($options);
        self::rejectConflictingCurlOptions($options);

        $contentLength = self::requestContentLength($request);
        if ($contentLength !== null) {
            $request = $request->withHeader('Content-Length', $contentLength);
        }

        $easy = new EasyHandle();
        $easy->request = $request;
        $easy->options = $options;
        $conf = $this->getDefaultConf($easy);
        $this->applyMethod($easy, $conf, $contentLength);
        $this->applyHandlerOptions($easy, $conf);
        $this->applyHeaders($easy, $conf);
        unset($conf['_headers']);

        // Add handler options from the request configuration options
        if (isset($options['curl'])) {
            $conf = \array_replace($conf, $options['curl']);
        }

        $conf[\CURLOPT_HEADERFUNCTION] = $this->createHeaderFn($easy);
        if ($this->shareHandle !== null) {
            if (!\defined('CURLOPT_SHARE')) {
                throw new InvalidArgumentException('The configured cURL share handle requires CURLOPT_SHARE, but it is not available in the installed PHP cURL extension.');
            }

            $conf[(int) \constant('CURLOPT_SHARE')] = $this->shareHandle;
        }

        $handle = $this->handles ? \array_pop($this->handles) : \curl_init();
        if (false === $handle) {
            throw new RequestException('Can not initialize cURL handle.', $request);
        }
        $easy->handle = $handle;

        try {
            $this->applyCurlOptions($handle, $conf);
        } catch (\Throwable $e) {
            if (PHP_VERSION_ID < 80000 && \is_resource($handle)) {
                \curl_close($handle);
            }
            unset($easy->handle);

            throw $e;
        }

        return $easy;
    }

    /**
     * @param resource|\CurlHandle     $handle
     * @param array<int|string, mixed> $conf
     */
    private function applyCurlOptions($handle, array $conf): void
    {
        foreach ($conf as $option => $value) {
            if (!\is_int($option)) {
                throw new InvalidArgumentException(\sprintf(
                    'Invalid cURL option %s.',
                    self::formatCurlOption($option)
                ));
            }

            try {
                $success = curl_setopt($handle, $option, $value);
            } catch (\Throwable $e) {
                throw new InvalidArgumentException(
                    \sprintf(
                        'Unable to set cURL option %s: %s',
                        self::formatCurlOption($option),
                        $e->getMessage()
                    ),
                    0,
                    $e
                );
            }

            if (!$success) {
                throw new InvalidArgumentException(\sprintf(
                    'Unable to set cURL option %s.',
                    self::formatCurlOption($option)
                ));
            }
        }
    }

    private function rejectRequestLevelShareConflict(array $options): void
    {
        if ($this->shareHandle === null) {
            return;
        }

        if (
            !\defined('CURLOPT_SHARE')
            || !isset($options['curl'])
            || !\is_array($options['curl'])
            || !\array_key_exists((int) \constant('CURLOPT_SHARE'), $options['curl'])
        ) {
            return;
        }

        throw new InvalidArgumentException('The request-level CURLOPT_SHARE cURL option cannot be combined with configured transport sharing.');
    }

    private function rejectPersistentRequireConnectionReuseConflicts(array $options): void
    {
        if (
            $this->shareMode !== TransportSharing::PERSISTENT_REQUIRE
            || !isset($options['curl'])
            || !\is_array($options['curl'])
        ) {
            return;
        }

        if (!empty($options['curl'][\CURLOPT_FRESH_CONNECT])) {
            throw new InvalidArgumentException('The CURLOPT_FRESH_CONNECT cURL option cannot be used when persistent cURL sharing is required because it disables connection reuse.');
        }

        if (!empty($options['curl'][\CURLOPT_FORBID_REUSE])) {
            throw new InvalidArgumentException('The CURLOPT_FORBID_REUSE cURL option cannot be used when persistent cURL sharing is required because it disables connection reuse.');
        }
    }

    /**
     * @param int|string $option
     */
    private static function formatCurlOption($option): string
    {
        if (!\is_int($option)) {
            return \sprintf('"%s"', $option);
        }

        static $names = null;

        if (null === $names) {
            $names = [];
            foreach (\get_defined_constants(true)['curl'] ?? [] as $name => $value) {
                if (\is_int($value) && \strpos($name, 'CURLOPT_') === 0 && !isset($names[$value])) {
                    $names[$value] = $name;
                }
            }
        }

        if (isset($names[$option])) {
            return \sprintf('%s (%d)', $names[$option], $option);
        }

        return (string) $option;
    }

    private static function rejectConflictingCurlOptions(array $options): void
    {
        if (!isset($options['curl']) || !\is_array($options['curl']) || $options['curl'] === []) {
            return;
        }

        $conflictingOptions = self::conflictingCurlOptions();

        foreach ($options['curl'] as $option => $_) {
            if (!\array_key_exists($option, $conflictingOptions)) {
                continue;
            }

            $name = self::formatCurlOption($option);
            $replacement = $conflictingOptions[$option];
            if ($replacement !== null) {
                throw new InvalidArgumentException(\sprintf(
                    'Passing %s in the "curl" request option is not supported because it conflicts with Guzzle-managed request handling. Use %s instead.',
                    $name,
                    $replacement
                ));
            }

            throw new InvalidArgumentException(\sprintf(
                'Passing %s in the "curl" request option is not supported because it conflicts with Guzzle-managed cURL internals.',
                $name
            ));
        }
    }

    private static function rejectUnsupportedRequestOptions(array $options): void
    {
        if (
            \array_key_exists('transport_sharing', $options)
            && CurlShareHandleState::normalizeMode($options['transport_sharing'], 'transport_sharing') !== TransportSharing::NONE
        ) {
            throw new InvalidArgumentException('The "transport_sharing" option is a client constructor option, not a request option. Configure transport sharing when creating the Client, CurlHandler, or CurlMultiHandler.');
        }

        if (\array_key_exists('stream_context', $options)) {
            throw new InvalidArgumentException('Passing the "stream_context" request option to a cURL handler is not supported because cURL handlers ignore PHP stream context options.');
        }
    }

    private static function assertOnStatsCallable(array $options): void
    {
        if (isset($options['on_stats']) && !\is_callable($options['on_stats'])) {
            throw new InvalidArgumentException('on_stats must be callable');
        }
    }

    /**
     * @return array<int, string|null>
     */
    private static function conflictingCurlOptions(): array
    {
        static $options = null;

        if ($options !== null) {
            return $options;
        }

        $options = [];

        self::addConflictingCurlOption($options, 'CURLOPT_SHARE', 'the "transport_sharing" client option or cURL handler option');
        self::addConflictingCurlOption($options, 'CURLOPT_URL', 'the request URI');
        self::addConflictingCurlOption($options, 'CURLOPT_PORT', 'the request URI');
        self::addConflictingCurlOption($options, 'CURLOPT_CUSTOMREQUEST', 'the request method');
        self::addConflictingCurlOption($options, 'CURLOPT_HTTPGET', 'the request method');
        self::addConflictingCurlOption($options, 'CURLOPT_POST', 'the request method and body');
        self::addConflictingCurlOption($options, 'CURLOPT_PUT', 'the request method and body');
        self::addConflictingCurlOption($options, 'CURLOPT_NOBODY', 'the request method');
        self::addConflictingCurlOption($options, 'CURLOPT_UPLOAD', 'the request body');
        self::addConflictingCurlOption($options, 'CURLOPT_POSTFIELDS', 'the request body');
        self::addConflictingCurlOption($options, 'CURLOPT_READFUNCTION', 'the request body');
        self::addConflictingCurlOption($options, 'CURLOPT_READDATA', 'the request body');
        self::addConflictingCurlOption($options, 'CURLOPT_INFILE', 'the request body');
        self::addConflictingCurlOption($options, 'CURLOPT_INFILESIZE', 'the request body');
        self::addConflictingCurlOption($options, 'CURLOPT_INFILESIZE_LARGE', 'the request body');
        self::addConflictingCurlOption($options, 'CURLOPT_HTTPHEADER', 'the request headers');
        self::addConflictingCurlOption($options, 'CURLOPT_USERAGENT', 'the request headers');
        self::addConflictingCurlOption($options, 'CURLOPT_REFERER', 'the request headers');
        self::addConflictingCurlOption($options, 'CURLOPT_HEADERFUNCTION', 'the "on_headers" request option');
        self::addConflictingCurlOption($options, 'CURLOPT_WRITEFUNCTION', 'the "sink" request option');
        self::addConflictingCurlOption($options, 'CURLOPT_FILE', 'the "sink" request option');
        self::addConflictingCurlOption($options, 'CURLOPT_RETURNTRANSFER', null);
        self::addConflictingCurlOption($options, 'CURLOPT_HEADER', null);
        self::addConflictingCurlOption($options, 'CURLOPT_TIMEOUT', 'the "timeout" request option');
        self::addConflictingCurlOption($options, 'CURLOPT_TIMEOUT_MS', 'the "timeout" request option');
        self::addConflictingCurlOption($options, 'CURLOPT_CONNECTTIMEOUT', 'the "connect_timeout" request option');
        self::addConflictingCurlOption($options, 'CURLOPT_CONNECTTIMEOUT_MS', 'the "connect_timeout" request option');
        self::addConflictingCurlOption($options, 'CURLOPT_NOSIGNAL', 'the "timeout" or "connect_timeout" request option');
        self::addConflictingCurlOption($options, 'CURLOPT_NOPROGRESS', 'the "progress" request option');
        self::addConflictingCurlOption($options, 'CURLOPT_PROGRESSFUNCTION', 'the "progress" request option');
        self::addConflictingCurlOption($options, 'CURLOPT_XFERINFOFUNCTION', 'the "progress" request option');
        self::addConflictingCurlOption($options, 'CURLOPT_VERBOSE', 'the "debug" request option');
        self::addConflictingCurlOption($options, 'CURLOPT_STDERR', 'the "debug" request option');
        self::addConflictingCurlOption($options, 'CURLOPT_PROXY', 'the "proxy" request option');
        self::addConflictingCurlOption($options, 'CURLOPT_NOPROXY', 'the "proxy" request option');
        self::addConflictingCurlOption($options, 'CURLOPT_FOLLOWLOCATION', 'the "allow_redirects" request option');
        self::addConflictingCurlOption($options, 'CURLOPT_MAXREDIRS', 'the "allow_redirects" request option');
        self::addConflictingCurlOption($options, 'CURLOPT_POSTREDIR', 'the "allow_redirects" request option');
        self::addConflictingCurlOption($options, 'CURLOPT_REDIR_PROTOCOLS', 'the "allow_redirects" request option');
        self::addConflictingCurlOption($options, 'CURLOPT_REDIR_PROTOCOLS_STR', 'the "allow_redirects" request option');
        self::addConflictingCurlOption($options, 'CURLOPT_PROTOCOLS', 'the "protocols" request option');
        self::addConflictingCurlOption($options, 'CURLOPT_PROTOCOLS_STR', 'the "protocols" request option');
        self::addConflictingCurlOption($options, 'CURLOPT_HTTP09_ALLOWED', null);
        self::addConflictingCurlOption($options, 'CURLOPT_HTTP_VERSION', 'the request protocol version');
        self::addConflictingCurlOption($options, 'CURLOPT_IPRESOLVE', 'the "force_ip_resolve" request option');
        self::addConflictingCurlOption($options, 'CURLOPT_SSL_VERIFYPEER', 'the "verify" request option');
        self::addConflictingCurlOption($options, 'CURLOPT_SSL_VERIFYHOST', 'the "verify" request option');
        self::addConflictingCurlOption($options, 'CURLOPT_CAINFO', 'the "verify" request option');
        self::addConflictingCurlOption($options, 'CURLOPT_CAPATH', 'the "verify" request option');
        self::addConflictingCurlOption($options, 'CURLOPT_SSLVERSION', 'the "crypto_method" request option');
        self::addConflictingCurlOption($options, 'CURLOPT_SSLCERT', 'the "cert" request option');
        self::addConflictingCurlOption($options, 'CURLOPT_SSLCERTPASSWD', 'the "cert" request option');
        self::addConflictingCurlOption($options, 'CURLOPT_SSLCERTTYPE', 'the "cert_type" request option');
        self::addConflictingCurlOption($options, 'CURLOPT_SSLKEY', 'the "ssl_key" request option');
        self::addConflictingCurlOption($options, 'CURLOPT_SSLKEYPASSWD', 'the "ssl_key" request option');
        self::addConflictingCurlOption($options, 'CURLOPT_KEYPASSWD', 'the "ssl_key" request option');
        self::addConflictingCurlOption($options, 'CURLOPT_SSLKEYTYPE', 'the "ssl_key_type" request option');
        self::addConflictingCurlOption($options, 'CURLOPT_COOKIE', 'the "Cookie" request header or Guzzle cookie middleware');
        self::addConflictingCurlOption($options, 'CURLOPT_COOKIEFILE', 'Guzzle cookie middleware');
        self::addConflictingCurlOption($options, 'CURLOPT_COOKIEJAR', 'Guzzle cookie middleware');
        self::addConflictingCurlOption($options, 'CURLOPT_COOKIELIST', 'Guzzle cookie middleware');
        self::addConflictingCurlOption($options, 'CURLOPT_COOKIESESSION', 'Guzzle cookie middleware');

        return $options;
    }

    /**
     * @param array<int, string|null> $options
     */
    private static function addConflictingCurlOption(array &$options, string $constant, ?string $replacement): void
    {
        if (!\defined($constant)) {
            return;
        }

        $value = \constant($constant);
        if (\is_int($value)) {
            $options[$value] = $replacement;
        }
    }

    public function release(EasyHandle $easy): void
    {
        $this->assertOpen();

        $resource = $easy->handle;
        unset($easy->handle);

        if (\count($this->handles) >= $this->maxHandles) {
            $this->discardHandle($resource);
        } else {
            // Remove all callback functions as they can hold onto references
            // and are not cleaned up by curl_reset. Using curl_setopt_array
            // does not work for some reason, so removing each one
            // individually.
            $this->clearEasyHandleCallbacks($resource);
            \curl_reset($resource);
            $this->handles[] = $resource;
        }
    }

    /**
     * Closes idle cURL handles owned by this factory.
     *
     * After closing, the factory is terminal and must not be reused.
     */
    public function close(): void
    {
        $this->doClose(true);
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            // Programmer misuse (reusing a closed factory), not a transfer failure;
            // intentionally a LogicException outside the GuzzleException hierarchy.
            throw new \BadMethodCallException('Cannot use the cURL factory after it has been closed.');
        }
    }

    private function doClose(bool $explicit): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $failure = null;

        try {
            foreach ($this->handles as $id => $handle) {
                try {
                    $this->discardHandle($handle);
                } catch (\Throwable $e) {
                    if ($failure === null) {
                        $failure = $e;
                    }
                } finally {
                    unset($this->handles[$id]);
                }
            }
        } finally {
            $this->shareMode = TransportSharing::NONE;
            $this->shareHandle = null;
        }

        if ($explicit && $failure !== null) {
            throw $failure;
        }
    }

    /**
     * @param resource|\CurlHandle $handle
     */
    private function discardHandle($handle): void
    {
        $failure = null;

        try {
            $this->clearEasyHandleCallbacks($handle);
        } catch (\Throwable $e) {
            $failure = $e;
        }

        try {
            if (PHP_VERSION_ID < 80000 && \is_resource($handle)) {
                \curl_close($handle);
            }
        } catch (\Throwable $e) {
            if ($failure === null) {
                $failure = $e;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * @param resource|\CurlHandle $handle
     */
    private function clearEasyHandleCallbacks($handle): void
    {
        curl_setopt($handle, \CURLOPT_HEADERFUNCTION, null);
        curl_setopt($handle, \CURLOPT_READFUNCTION, null);
        curl_setopt($handle, \CURLOPT_WRITEFUNCTION, null);
        curl_setopt($handle, \CURLOPT_PROGRESSFUNCTION, null);

        if (\defined('CURLOPT_XFERINFOFUNCTION')) {
            curl_setopt($handle, (int) \constant('CURLOPT_XFERINFOFUNCTION'), null);
        }
    }

    /**
     * Completes a cURL transaction, either returning a response promise or a
     * rejected promise.
     *
     * @param callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed> $handler
     * @param CurlFactoryInterface                                                                            $factory Dictates how the handle is released
     *
     * @return PromiseInterface<ResponseInterface, mixed>
     */
    public static function finish(callable $handler, EasyHandle $easy, CurlFactoryInterface $factory): PromiseInterface
    {
        /** @var (callable(TransferStats): mixed)|null $onStats */
        $onStats = $easy->options['on_stats'] ?? null;
        $stats = $onStats !== null ? self::createStats($easy) : null;

        if (self::shouldFinishWithError($easy)) {
            return self::finishError($handler, $easy, $factory, $stats, $onStats);
        }

        /** @var ResponseInterface $response */
        $response = $easy->response;

        // Return the response if it is present and there is no error.
        $factory->release($easy);

        // Rewind the body of the response if possible. Failures here are local
        // response finalization errors, not response-transfer failures.
        $body = $response->getBody();
        try {
            if ($body->isSeekable()) {
                $body->rewind();
            }
        } catch (\Exception $e) {
            $reason = new ResponseException(
                $e->getMessage() !== '' ? $e->getMessage() : 'Failed to rewind the response body',
                $easy->request,
                $response,
                $e
            );

            if ($onStats !== null && $stats !== null) {
                // Report the ResponseException rather than errno 0 to match the
                // stream handler's response finalization stats.
                $onStats(new TransferStats(
                    $easy->request,
                    $response,
                    $stats->getTransferTime(),
                    $reason,
                    $stats->getHandlerStats()
                ));
            }

            /** @var PromiseInterface<ResponseInterface, mixed> */
            return P\Create::rejectionFor($reason);
        }

        if ($onStats !== null && $stats !== null) {
            $onStats($stats);
        }

        /** @var PromiseInterface<ResponseInterface, mixed> */
        return P\Create::promiseFor($response);
    }

    private static function createStats(EasyHandle $easy): TransferStats
    {
        $curlStats = \curl_getinfo($easy->handle);
        $curlStats['appconnect_time'] = \curl_getinfo($easy->handle, \CURLINFO_APPCONNECT_TIME);

        if ($easy->createResponseException) {
            $curlStats = [
                'total_time' => $curlStats['total_time'],
                'appconnect_time' => $curlStats['appconnect_time'],
            ];
        }

        $handlerErrorData = $easy->responseBodySizeException ?? $easy->errno;

        return new TransferStats(
            $easy->request,
            $easy->response,
            $curlStats['total_time'],
            $handlerErrorData,
            $curlStats
        );
    }

    /**
     * @param callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed> $handler
     * @param (callable(TransferStats): mixed)|null                                                           $onStats
     *
     * @return PromiseInterface<ResponseInterface, mixed>
     */
    private static function finishError(callable $handler, EasyHandle $easy, CurlFactoryInterface $factory, ?TransferStats $stats, ?callable $onStats): PromiseInterface
    {
        // Get error information and release the handle to the factory.
        $ctx = self::createErrorContext($easy);
        $factory->release($easy);

        if ($onStats !== null && $stats !== null) {
            $onStats($stats);
        }

        if (self::shouldRetryFailedRewind($easy)) {
            return self::retryFailedRewind($handler, $easy, $ctx);
        }

        return self::createRejection($easy, $ctx);
    }

    private static function shouldFinishWithError(EasyHandle $easy): bool
    {
        return !$easy->response
            || $easy->errno !== 0
            || self::hasLocalFailure($easy);
    }

    private static function hasLocalFailure(EasyHandle $easy): bool
    {
        return $easy->bodyReadTimeoutException !== null
            || $easy->bodyReadException !== null
            || $easy->sinkWriteTimeoutException !== null
            || $easy->sinkWriteException !== null
            || $easy->sinkWriteIncomplete
            || $easy->responseBodySizeException !== null;
    }

    private static function shouldRetryFailedRewind(EasyHandle $easy): bool
    {
        if (self::hasLocalFailure($easy)) {
            return false;
        }

        if (!empty($easy->options['_err_message'])) {
            return false;
        }

        return $easy->errno === 0 || $easy->errno === self::CURLE_SEND_FAIL_REWIND;
    }

    private static function createErrorContext(EasyHandle $easy): array
    {
        return [
            'errno' => $easy->errno,
            'error' => \curl_error($easy->handle),
        ];
    }

    /**
     * @return PromiseInterface<ResponseInterface, mixed>
     */
    private static function createRejection(EasyHandle $easy, array $ctx, ?\Throwable $previous = null): PromiseInterface
    {
        if ($easy->createResponseException) {
            /** @var PromiseInterface<ResponseInterface, mixed> */
            return P\Create::rejectionFor(
                new RequestException(
                    'An error was encountered while creating the response',
                    $easy->request,
                    0,
                    $easy->createResponseException
                )
            );
        }

        // If an exception was encountered during the onHeaders event, then
        // return a rejected promise that wraps that exception.
        if ($easy->onHeadersException) {
            return self::createRequestOrResponseRejection(
                $easy,
                'An error was encountered during the on_headers event',
                $easy->onHeadersException
            );
        }

        if ($easy->progressException) {
            return self::createRequestOrResponseRejection(
                $easy,
                'An error was encountered during the progress event',
                $easy->progressException
            );
        }

        if ($easy->bodyReadTimeoutException) {
            // Reading the request body stalled, which is a caller-stream failure.
            return self::createRequestOrResponseRejection(
                $easy,
                'Timed out while reading the request body',
                $easy->bodyReadTimeoutException
            );
        }

        if ($easy->bodyReadException) {
            $message = $easy->bodyReadException->getMessage() !== ''
                ? $easy->bodyReadException->getMessage()
                : 'Failed to read the request body';

            return self::createRequestOrResponseRejection($easy, $message, $easy->bodyReadException);
        }

        if ($easy->sinkWriteTimeoutException) {
            // Writing the response body to the caller's sink stalled, which is a
            // caller-stream failure.
            return self::createRequestOrResponseRejection(
                $easy,
                'Timed out while writing the response body',
                $easy->sinkWriteTimeoutException
            );
        }

        if ($easy->sinkWriteException) {
            $message = $easy->sinkWriteException->getMessage() !== ''
                ? $easy->sinkWriteException->getMessage()
                : 'Failed to write the response body';

            return self::createRequestOrResponseRejection($easy, $message, $easy->sinkWriteException);
        }

        if ($easy->sinkWriteIncomplete) {
            return self::createRequestOrResponseRejection($easy, 'Unable to write to stream');
        }

        if ($easy->responseBodySizeException) {
            return self::createRequestOrResponseRejection(
                $easy,
                $easy->responseBodySizeException->getMessage(),
                $easy->responseBodySizeException
            );
        }

        if ($easy->progressAborted && $easy->errno === \CURLE_ABORTED_BY_CALLBACK) {
            return self::createRequestOrResponseRejection(
                $easy,
                'The transfer was aborted by the progress callback'
            );
        }

        $uri = $easy->request->getUri();

        $sanitizedError = self::sanitizeCurlError($ctx['error'] ?? '', $uri);

        $message = \sprintf(
            'cURL error %s: %s (%s)',
            $ctx['errno'],
            $sanitizedError,
            'see https://curl.haxx.se/libcurl/c/libcurl-errors.html'
        );

        if ('' !== $sanitizedError) {
            $redactedUriString = \GuzzleHttp\Psr7\Utils::redactUserInfo($uri)->__toString();
            if ($redactedUriString !== '' && false === \strpos($sanitizedError, $redactedUriString)) {
                $message .= \sprintf(' for %s', $redactedUriString);
            }
        }

        if ($easy->errno === \CURLE_OPERATION_TIMEOUTED) {
            if ($easy->response !== null) {
                $error = new ResponseTimeoutException($message, $easy->request, $easy->response, $previous);
            } elseif (self::isConnectTimeoutError($ctx['error'] ?? '')) {
                $error = new ConnectTimeoutException($message, $easy->request, $previous);
            } else {
                $error = new NetworkTimeoutException($message, $easy->request, $previous);
            }
        } elseif ($easy->response) {
            $error = self::isResponseTransferError($easy->errno)
                ? new ResponseTransferException($message, $easy->request, $easy->response, $previous)
                : new ResponseException($message, $easy->request, $easy->response, $previous);
        } elseif (self::isConnectionError($easy->errno)) {
            $error = new ConnectException($message, $easy->request, $previous);
        } elseif (self::isNetworkError($easy->errno)) {
            $error = new NetworkException($message, $easy->request, $previous);
        } else {
            $error = new RequestException($message, $easy->request, 0, $previous);
        }

        /** @var PromiseInterface<ResponseInterface, mixed> */
        return P\Create::rejectionFor($error);
    }

    /**
     * @return PromiseInterface<ResponseInterface, mixed>
     */
    private static function createRequestOrResponseRejection(
        EasyHandle $easy,
        string $message,
        ?\Throwable $previous = null
    ): PromiseInterface {
        if ($easy->response !== null) {
            /** @var PromiseInterface<ResponseInterface, mixed> */
            return P\Create::rejectionFor(
                new ResponseException($message, $easy->request, $easy->response, $previous)
            );
        }

        /** @var PromiseInterface<ResponseInterface, mixed> */
        return P\Create::rejectionFor(
            new RequestException($message, $easy->request, 0, $previous)
        );
    }

    private static function isConnectionError(int $errno): bool
    {
        return isset(self::CURL_CONNECTION_ERRORS[$errno]);
    }

    private static function isNetworkError(int $errno): bool
    {
        return isset(self::CURL_NETWORK_ERRORS[$errno]);
    }

    private static function isResponseTransferError(int $errno): bool
    {
        return self::isConnectionError($errno)
            || self::isNetworkError($errno)
            || isset(self::CURL_RESPONSE_TRANSFER_ERRORS[$errno]);
    }

    private static function isConnectTimeoutError(string $error): bool
    {
        if ('' === $error) {
            return false;
        }

        foreach (self::CURL_CONNECT_TIMEOUT_ERRORS as $connectTimeoutError) {
            if (\stripos($error, $connectTimeoutError) !== false) {
                return true;
            }
        }

        return \stripos($error, 'Failed to resolve') !== false
            && \stripos($error, 'timeout') !== false;
    }

    private static function sanitizeCurlError(string $error, UriInterface $uri): string
    {
        if ('' === $error) {
            return $error;
        }

        $baseUri = $uri->withQuery('')->withFragment('');
        $baseUriString = $baseUri->__toString();

        if ('' === $baseUriString) {
            return $error;
        }

        $redactedUriString = \GuzzleHttp\Psr7\Utils::redactUserInfo($baseUri)->__toString();

        return str_replace($baseUriString, $redactedUriString, $error);
    }

    private static function requiresFreshConnectionForAuthenticatedProxy(RequestInterface $request, string $proxy, array $options): bool
    {
        if (!self::usesProxyTunnel($request, $options) || !self::isHttpProxyForConnectionReuse($proxy, $options)) {
            return false;
        }

        $proxyForParsing = \strpos($proxy, '://') === false ? 'http://'.$proxy : $proxy;
        $proxyParts = \parse_url($proxyForParsing);
        if (!\is_array($proxyParts)) {
            return false;
        }

        if (self::hasCurlProxyAuthorizationHeader($options)) {
            return true;
        }

        return !CurlVersion::supportsProxyCredentialAwareConnectionReuse()
            && (
                \array_key_exists('user', $proxyParts)
                || \array_key_exists('pass', $proxyParts)
                || self::hasCurlProxyCredentials($options)
            );
    }

    private static function usesProxyTunnel(RequestInterface $request, array $options): bool
    {
        return 'https' === $request->getUri()->getScheme()
            || (
                isset($options['curl'])
                && \array_key_exists(\CURLOPT_HTTPPROXYTUNNEL, $options['curl'])
                && (bool) $options['curl'][\CURLOPT_HTTPPROXYTUNNEL]
            );
    }

    private static function getEffectiveProxyForConnectionReuse(?string $selectedProxy, array $options): ?string
    {
        if (!isset($options['curl']) || !\array_key_exists(\CURLOPT_PROXY, $options['curl'])) {
            return $selectedProxy;
        }

        $proxy = $options['curl'][\CURLOPT_PROXY];

        return \is_string($proxy) && $proxy !== '' ? $proxy : null;
    }

    private static function isHttpProxyForConnectionReuse(string $proxy, array $options): bool
    {
        if (\strpos($proxy, '://') !== false) {
            $proxyParts = \parse_url($proxy);
            if (!\is_array($proxyParts) || !isset($proxyParts['scheme'])) {
                return false;
            }

            $proxyScheme = \strtolower($proxyParts['scheme']);

            return $proxyScheme === 'http' || $proxyScheme === 'https';
        }

        return !self::isSocksProxyType($options['curl'][\CURLOPT_PROXYTYPE] ?? null);
    }

    /**
     * @param mixed $proxyType
     */
    private static function isSocksProxyType($proxyType): bool
    {
        if (!\is_int($proxyType)) {
            return false;
        }

        foreach ([
            'CURLPROXY_SOCKS4' => 4,
            'CURLPROXY_SOCKS5' => 5,
            'CURLPROXY_SOCKS4A' => 6,
            'CURLPROXY_SOCKS5_HOSTNAME' => 7,
        ] as $name => $fallback) {
            $value = \defined($name) ? (int) \constant($name) : $fallback;
            if ($proxyType === $value) {
                return true;
            }
        }

        return false;
    }

    private static function hasCurlProxyCredentials(array $options): bool
    {
        return isset($options['curl'])
            && (
                \array_key_exists(\CURLOPT_PROXYUSERPWD, $options['curl'])
                || \array_key_exists(\CURLOPT_PROXYUSERNAME, $options['curl'])
                || \array_key_exists(\CURLOPT_PROXYPASSWORD, $options['curl'])
            );
    }

    private static function hasCurlProxyAuthorizationHeader(array $options): bool
    {
        if (!\defined('CURLOPT_PROXYHEADER')) {
            return false;
        }

        $option = (int) \constant('CURLOPT_PROXYHEADER');
        if (!isset($options['curl']) || !\array_key_exists($option, $options['curl'])) {
            return false;
        }

        $headers = $options['curl'][$option];
        if (!\is_array($headers)) {
            return false;
        }

        foreach ($headers as $header) {
            if (!\is_string($header)) {
                continue;
            }

            $parts = \explode(':', $header, 2);
            if (\count($parts) !== 2) {
                continue;
            }

            if (
                0 === \strcasecmp(\trim($parts[0]), 'Proxy-Authorization')
                && \trim($parts[1]) !== ''
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int|string, mixed>
     */
    private function getDefaultConf(EasyHandle $easy): array
    {
        $conf = [
            '_headers' => $easy->request->getHeaders(),
            \CURLOPT_CUSTOMREQUEST => $easy->request->getMethod(),
            \CURLOPT_URL => (string) $easy->request->getUri()->withFragment(''),
            \CURLOPT_RETURNTRANSFER => false,
            \CURLOPT_HEADER => false,
            \CURLOPT_CONNECTTIMEOUT => 300,
        ];

        $protocols = Utils::normalizeProtocols($easy->options['protocols'] ?? ['http', 'https']);
        $scheme = $easy->request->getUri()->getScheme();
        if (!\in_array($scheme, $protocols, true)) {
            throw new RequestException(\sprintf('The scheme "%s" is not allowed by the protocols request option.', $scheme), $easy->request);
        }

        if (CurlVersion::supportsProtocolsStr()) {
            $conf[(int) \constant('CURLOPT_PROTOCOLS_STR')] = \implode(',', $protocols);
        } else {
            $conf[\CURLOPT_PROTOCOLS] = self::curlProtocolMask($protocols);
        }

        $version = $easy->request->getProtocolVersion();

        if ('3' === $version || '3.0' === $version) {
            if (!\defined('CURL_HTTP_VERSION_3')) {
                throw new RequestException('HTTP/3 is not supported by this cURL installation.', $easy->request);
            }

            $proxy = ProxyOptions::resolve($easy->request->getUri(), $easy->options['proxy'] ?? null);
            $conf[\CURLOPT_HTTP_VERSION] = $proxy->hasProxy()
                ? (CurlVersion::supportsHttp2() ? \CURL_HTTP_VERSION_2_0 : \CURL_HTTP_VERSION_1_1)
                : (int) \constant('CURL_HTTP_VERSION_3');
        } elseif ('2' === $version || '2.0' === $version) {
            $conf[\CURLOPT_HTTP_VERSION] = \CURL_HTTP_VERSION_2_0;
        } elseif ('1.1' === $version) {
            $conf[\CURLOPT_HTTP_VERSION] = \CURL_HTTP_VERSION_1_1;
        } else {
            $conf[\CURLOPT_HTTP_VERSION] = \CURL_HTTP_VERSION_1_0;
        }

        return $conf;
    }

    /**
     * @param string[] $protocols
     */
    private static function curlProtocolMask(array $protocols): int
    {
        $mask = 0;

        if (\in_array('http', $protocols, true)) {
            $mask |= \CURLPROTO_HTTP;
        }

        if (\in_array('https', $protocols, true)) {
            $mask |= \CURLPROTO_HTTPS;
        }

        return $mask;
    }

    /**
     * @param mixed $type
     */
    private static function normalizeTlsFileType(string $option, $type): string
    {
        if (!\is_string($type) || $type === '') {
            throw new InvalidArgumentException(\sprintf('%s must be a non-empty string', $option));
        }

        return \strtoupper($type);
    }

    private static function shouldValidateSslKeyFile(?string $type): bool
    {
        return $type !== 'ENG' && $type !== 'PROV';
    }

    private static function responseContentLengthOverflows(EasyHandle $easy): bool
    {
        if ($easy->response === null) {
            return false;
        }

        $length = HeaderProcessor::parseContentLengthForResponseBody($easy->request, $easy->response);
        try {
            HeaderProcessor::assertContentLengthWithinPlatformLimit($length);
        } catch (\OverflowException $e) {
            $easy->responseBodySizeException = $e;

            return true;
        }

        return false;
    }

    private function applyMethod(EasyHandle $easy, array &$conf, ?string $contentLength): void
    {
        $body = $easy->request->getBody();
        try {
            $size = $body->getSize();
        } catch (\Exception $e) {
            $message = $e instanceof TimeoutException
                ? 'Timed out while determining the request body size'
                : ($e->getMessage() !== '' ? $e->getMessage() : 'Failed to determine the request body size');

            throw new RequestException($message, $easy->request, 0, $e);
        }

        if ($size === null || $size > 0) {
            $this->applyBody($easy, $conf, $contentLength);

            return;
        }

        $method = $easy->request->getMethod();
        if ($method === 'PUT' || $method === 'POST') {
            // See https://datatracker.ietf.org/doc/html/rfc7230#section-3.3.2
            if (!$easy->request->hasHeader('Content-Length')) {
                $conf[\CURLOPT_HTTPHEADER][] = 'Content-Length: 0';
            }
        } elseif ($method === 'HEAD') {
            $conf[\CURLOPT_NOBODY] = true;
            unset(
                $conf[\CURLOPT_WRITEFUNCTION],
                $conf[\CURLOPT_READFUNCTION],
                $conf[\CURLOPT_FILE],
                $conf[\CURLOPT_INFILE]
            );
        }
    }

    private static function requestContentLength(RequestInterface $request): ?string
    {
        try {
            $length = HeaderProcessor::parseContentLength($request->getHeader('Content-Length'));
        } catch (\RuntimeException $e) {
            throw new RequestException(
                'Invalid Content-Length request header: '.$e->getMessage(),
                $request,
                0,
                $e
            );
        }

        try {
            HeaderProcessor::assertContentLengthWithinPlatformLimit($length);
        } catch (\OverflowException $e) {
            throw new RequestException(
                $e->getMessage(),
                $request,
                0,
                $e
            );
        }

        return $length;
    }

    private function applyBody(EasyHandle $easy, array &$conf, ?string $contentLengthHeader): void
    {
        $request = $easy->request;
        $options = $easy->options;
        $contentLength = HeaderProcessor::contentLengthToInt($contentLengthHeader);

        // Send the body as a string if the size is less than 1MB OR if the
        // [curl][body_as_string] request value is set.
        if (($contentLength !== null && $contentLength < 1000000) || !empty($options['_body_as_string'])) {
            try {
                $conf[\CURLOPT_POSTFIELDS] = (string) $request->getBody();
            } catch (\Exception $e) {
                $message = $e instanceof TimeoutException
                    ? 'Timed out while reading the request body'
                    : ($e->getMessage() !== '' ? $e->getMessage() : 'Failed to read the request body');

                throw new RequestException($message, $request, 0, $e);
            }
            // Don't duplicate the Content-Length header
            $this->removeHeader('Content-Length', $conf);
            $this->removeHeader('Transfer-Encoding', $conf);
        } else {
            $conf[\CURLOPT_UPLOAD] = true;

            if ($contentLengthHeader !== null) {
                // Never let cURL emit our header; it sizes the upload via CURLOPT_INFILESIZE.
                $this->removeHeader('Content-Length', $conf);
            }

            if ($contentLength !== null) {
                $conf[\CURLOPT_INFILESIZE] = $contentLength;
            }

            $body = $request->getBody();
            try {
                if ($body->isSeekable()) {
                    $body->rewind();
                }
            } catch (\Exception $e) {
                $message = $e instanceof TimeoutException
                    ? 'Timed out while rewinding the request body'
                    : ($e->getMessage() !== '' ? $e->getMessage() : 'Failed to rewind the request body');

                throw new RequestException($message, $request, 0, $e);
            }
            /**
             * @return int|string
             */
            $conf[\CURLOPT_READFUNCTION] = static function ($ch, $fd, int $length) use ($easy, $body) {
                try {
                    return $body->read($length);
                } catch (TimeoutException $e) {
                    $easy->bodyReadTimeoutException = $e;

                    return self::CURL_READFUNC_ABORT;
                } catch (\Throwable $e) {
                    $easy->bodyReadException = $e;

                    return self::CURL_READFUNC_ABORT;
                }
            };
        }

        // If the Expect header is not present, prevent curl from adding it
        if (!$request->hasHeader('Expect')) {
            $conf[\CURLOPT_HTTPHEADER][] = 'Expect:';
        }

        // cURL sometimes adds a content-type by default. Prevent this.
        if (!$request->hasHeader('Content-Type')) {
            $conf[\CURLOPT_HTTPHEADER][] = 'Content-Type:';
        }
    }

    private function applyHeaders(EasyHandle $easy, array &$conf): void
    {
        foreach ($conf['_headers'] as $name => $values) {
            foreach ($values as $value) {
                $value = (string) $value;
                if ($value === '') {
                    // cURL requires a special format for empty headers.
                    // See https://github.com/guzzle/guzzle/issues/1882 for more details.
                    $conf[\CURLOPT_HTTPHEADER][] = "$name;";
                } else {
                    $conf[\CURLOPT_HTTPHEADER][] = "$name: $value";
                }
            }
        }

        // Remove the Accept header if one was not set
        if (!$easy->request->hasHeader('Accept')) {
            $conf[\CURLOPT_HTTPHEADER][] = 'Accept:';
        }
    }

    /**
     * Remove a header from the options array.
     *
     * @param string $name    Case-insensitive header to remove
     * @param array  $options Array of options to modify
     */
    private function removeHeader(string $name, array &$options): void
    {
        foreach (\array_keys($options['_headers']) as $key) {
            if (!\strcasecmp((string) $key, $name)) {
                unset($options['_headers'][$key]);

                return;
            }
        }
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
        $stream = \GuzzleHttp\Psr7\Utils::streamFor($resource);

        return FnStream::decorate($stream, [
            'close' => static function () use ($stream): void {
                $stream->detach();
            },
        ]);
    }

    private function applyHandlerOptions(EasyHandle $easy, array &$conf): void
    {
        $options = $easy->options;
        if (isset($options['verify'])) {
            if ($options['verify'] === false) {
                unset($conf[\CURLOPT_CAINFO]);
                $conf[\CURLOPT_SSL_VERIFYHOST] = 0;
                $conf[\CURLOPT_SSL_VERIFYPEER] = false;
            } else {
                $conf[\CURLOPT_SSL_VERIFYHOST] = 2;
                $conf[\CURLOPT_SSL_VERIFYPEER] = true;
                if (\is_string($options['verify'])) {
                    // Throw an error if the file/folder/link path is not valid or doesn't exist.
                    if (!\file_exists($options['verify'])) {
                        throw new InvalidArgumentException("SSL CA bundle not found: {$options['verify']}");
                    }
                    // If it's a directory or a link to a directory use CURLOPT_CAPATH.
                    // If not, it's probably a file, or a link to a file, so use CURLOPT_CAINFO.
                    if (
                        \is_dir($options['verify'])
                        || (
                            \is_link($options['verify']) === true
                            && ($verifyLink = \readlink($options['verify'])) !== false
                            && \is_dir($verifyLink)
                        )
                    ) {
                        $conf[\CURLOPT_CAPATH] = $options['verify'];
                    } else {
                        $conf[\CURLOPT_CAINFO] = $options['verify'];
                    }
                }
            }
        }

        if (!isset($options['curl'][\CURLOPT_ENCODING]) && !empty($options['decode_content'])) {
            $accept = $easy->request->getHeaderLine('Accept-Encoding');
            if ($accept) {
                $conf[\CURLOPT_ENCODING] = $accept;
            } else {
                // The empty string enables all available decoders and implicitly
                // sets a matching 'Accept-Encoding' header.
                $conf[\CURLOPT_ENCODING] = '';
                // But as the user did not specify any encoding preference,
                // let's leave it up to server by preventing curl from sending
                // the header, which will be interpreted as 'Accept-Encoding: *'.
                // https://www.rfc-editor.org/rfc/rfc9110#field.accept-encoding
                $conf[\CURLOPT_HTTPHEADER][] = 'Accept-Encoding:';
            }
        }

        $hasSink = isset($options['sink']);
        if (!$hasSink) {
            // Use a default temp stream if no sink was set.
            $options['sink'] = \GuzzleHttp\Psr7\Utils::tryFopen('php://temp', 'w+');
        }
        $sink = $options['sink'];
        if ($hasSink && \is_resource($sink)) {
            $sink = self::streamForResourceSink($sink);
        } elseif (!\is_string($sink)) {
            $sink = \GuzzleHttp\Psr7\Utils::streamFor($sink);
        } elseif (!\is_dir(\dirname($sink))) {
            // Ensure that the directory exists before failing in curl.
            throw new RequestException(\sprintf('Directory %s does not exist for sink value of %s', \dirname($sink), $sink), $easy->request);
        } else {
            $sink = new LazyOpenStream($sink, 'w+');
        }
        $easy->sink = $sink;
        $conf[\CURLOPT_WRITEFUNCTION] = static function ($ch, string $write) use ($easy, $sink): int {
            $length = \strlen($write);

            try {
                $newResponseBodyBytes = TransferByteCounter::add(
                    $easy->responseBodyBytes,
                    $length,
                    'Response body exceeds the maximum integer size supported on this platform'
                );
            } catch (\OverflowException $e) {
                $easy->responseBodySizeException = $e;

                return 0;
            }

            try {
                $written = $sink->write($write);
            } catch (TimeoutException $e) {
                $easy->sinkWriteTimeoutException = $e;

                return 0;
            } catch (\Throwable $e) {
                $easy->sinkWriteException = $e;

                return 0;
            }

            if ($written !== $length) {
                $easy->sinkWriteIncomplete = true;

                return 0;
            }

            $easy->responseBodyBytes = $newResponseBodyBytes;

            return $written;
        };

        $timeoutRequiresNoSignal = false;
        if (isset($options['timeout'])) {
            $timeout = Utils::timeoutToMilliseconds($options['timeout'], 'timeout');
            $timeoutRequiresNoSignal |= $timeout < 1000;
            $conf[\CURLOPT_TIMEOUT_MS] = $timeout;
        }

        // CURL default value is CURL_IPRESOLVE_WHATEVER
        if (isset($options['force_ip_resolve'])) {
            if ('v4' === $options['force_ip_resolve']) {
                $conf[\CURLOPT_IPRESOLVE] = \CURL_IPRESOLVE_V4;
            } elseif ('v6' === $options['force_ip_resolve']) {
                $conf[\CURLOPT_IPRESOLVE] = \CURL_IPRESOLVE_V6;
            }
        }

        if (isset($options['connect_timeout'])) {
            $connectTimeout = Utils::timeoutToMilliseconds($options['connect_timeout'], 'connect_timeout');
            $timeoutRequiresNoSignal |= $connectTimeout < 1000;
            $conf[\CURLOPT_CONNECTTIMEOUT_MS] = $connectTimeout;
        }

        if ($timeoutRequiresNoSignal && \strtoupper(\substr(\PHP_OS, 0, 3)) !== 'WIN') {
            $conf[\CURLOPT_NOSIGNAL] = true;
        }

        $proxy = ProxyOptions::resolve($easy->request->getUri(), $options['proxy'] ?? null);
        $selectedProxy = $proxy->getProxy();
        if ($selectedProxy !== null) {
            $conf[\CURLOPT_PROXY] = $selectedProxy;
            $conf[\CURLOPT_NOPROXY] = '';
        } elseif ($proxy->shouldDisableProxy()) {
            $conf[\CURLOPT_PROXY] = '';
            $conf[\CURLOPT_NOPROXY] = $proxy->isBypassed() ? '*' : '';
        }

        $proxyForConnectionReuse = self::getEffectiveProxyForConnectionReuse($selectedProxy, $options);
        if ($proxyForConnectionReuse !== null && self::requiresFreshConnectionForAuthenticatedProxy($easy->request, $proxyForConnectionReuse, $options)) {
            if ($this->shareMode === TransportSharing::PERSISTENT_REQUIRE) {
                throw new InvalidArgumentException('Persistent cURL sharing is required, but this request requires a fresh proxy tunnel connection.');
            }

            $conf[\CURLOPT_FRESH_CONNECT] = true;
            $conf[\CURLOPT_FORBID_REUSE] = true;
        }

        $cryptoMethod = $options['crypto_method'] ?? null;

        if (null === $cryptoMethod && 'https' === $easy->request->getUri()->getScheme() && !isset($options['curl'][\CURLOPT_SSLVERSION])) {
            $cryptoMethod = \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        }

        if (null !== $cryptoMethod) {
            $protocolVersion = $easy->request->getProtocolVersion();
            $isHttp3 = '3' === $protocolVersion || '3.0' === $protocolVersion;
            $isHttp2 = '2' === $protocolVersion || '2.0' === $protocolVersion;

            if ($isHttp3 || $isHttp2) {
                // HTTP/2 requires TLS 1.2. HTTP/3 uses the same guard rail
                // because CURLOPT_SSLVERSION also affects fallback transfers.
                if (
                    \STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT === $cryptoMethod
                    || \STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT === $cryptoMethod
                    || \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT === $cryptoMethod
                ) {
                    $conf[\CURLOPT_SSLVERSION] = \CURL_SSLVERSION_TLSv1_2;
                } elseif (\STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT === $cryptoMethod) {
                    if (!CurlVersion::supportsTls13()) {
                        throw new InvalidArgumentException('Invalid crypto_method request option: TLS 1.3 not supported by your version of cURL');
                    }
                    $conf[\CURLOPT_SSLVERSION] = \CURL_SSLVERSION_TLSv1_3;
                } else {
                    throw new InvalidArgumentException('Invalid crypto_method request option: unknown version provided');
                }
            } elseif (\STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT === $cryptoMethod) {
                $conf[\CURLOPT_SSLVERSION] = \CURL_SSLVERSION_TLSv1_0;
            } elseif (\STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT === $cryptoMethod) {
                $conf[\CURLOPT_SSLVERSION] = \CURL_SSLVERSION_TLSv1_1;
            } elseif (\STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT === $cryptoMethod) {
                $conf[\CURLOPT_SSLVERSION] = \CURL_SSLVERSION_TLSv1_2;
            } elseif (\STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT === $cryptoMethod) {
                if (!CurlVersion::supportsTls13()) {
                    throw new InvalidArgumentException('Invalid crypto_method request option: TLS 1.3 not supported by your version of cURL');
                }
                $conf[\CURLOPT_SSLVERSION] = \CURL_SSLVERSION_TLSv1_3;
            } else {
                throw new InvalidArgumentException('Invalid crypto_method request option: unknown version provided');
            }
        }

        $certType = null;
        if (isset($options['cert_type'])) {
            $certType = self::normalizeTlsFileType('cert_type', $options['cert_type']);
            $conf[\CURLOPT_SSLCERTTYPE] = $certType;
        }

        if (isset($options['cert'])) {
            $cert = $options['cert'];
            if (\is_array($cert)) {
                if (!isset($cert[0]) || !\is_string($cert[0])) {
                    throw new InvalidArgumentException('Invalid cert request option');
                }
                if (isset($cert[1])) {
                    if (!\is_string($cert[1])) {
                        throw new InvalidArgumentException('Invalid cert request option');
                    }
                    $conf[\CURLOPT_SSLCERTPASSWD] = $cert[1];
                }
                $cert = $cert[0];
            }
            if (!\is_string($cert)) {
                throw new InvalidArgumentException('Invalid cert request option');
            }
            if (!\file_exists($cert)) {
                throw new InvalidArgumentException("SSL certificate not found: {$cert}");
            }
            // OpenSSL (versions 0.9.3 and later) also support "P12" for PKCS#12-encoded files.
            // see https://curl.se/libcurl/c/CURLOPT_SSLCERTTYPE.html
            $ext = pathinfo($cert, \PATHINFO_EXTENSION);
            if ($certType === null && preg_match('#^(der|p12)$#i', $ext)) {
                $conf[\CURLOPT_SSLCERTTYPE] = strtoupper($ext);
            }
            $conf[\CURLOPT_SSLCERT] = $cert;
        }

        $sslKeyType = null;
        if (isset($options['ssl_key_type'])) {
            $sslKeyType = self::normalizeTlsFileType('ssl_key_type', $options['ssl_key_type']);
            $conf[\CURLOPT_SSLKEYTYPE] = $sslKeyType;
        }

        if (isset($options['ssl_key'])) {
            if (\is_array($options['ssl_key'])) {
                if (!isset($options['ssl_key'][0]) || !\is_string($options['ssl_key'][0])) {
                    throw new InvalidArgumentException('Invalid ssl_key request option');
                }
                if (isset($options['ssl_key'][1])) {
                    if (!\is_string($options['ssl_key'][1])) {
                        throw new InvalidArgumentException('Invalid ssl_key request option');
                    }
                    $conf[\CURLOPT_SSLKEYPASSWD] = $options['ssl_key'][1];
                }
                $sslKey = $options['ssl_key'][0];
            }

            $sslKey = $sslKey ?? $options['ssl_key'];

            if (!\is_string($sslKey)) {
                throw new InvalidArgumentException('Invalid ssl_key request option');
            }

            if (self::shouldValidateSslKeyFile($sslKeyType) && !\file_exists($sslKey)) {
                throw new InvalidArgumentException("SSL private key not found: {$sslKey}");
            }
            $conf[\CURLOPT_SSLKEY] = $sslKey;
        }

        $progress = $options['progress'] ?? null;
        if ($progress !== null && !\is_callable($progress)) {
            throw new InvalidArgumentException('progress client option must be callable');
        }

        // The streaming read callback (set by applyBody) aborts the upload on a
        // body read failure by returning CURL_READFUNC_ABORT, but PHP ignores
        // that integer return before 8.1.17/8.2.4. Install a progress callback
        // so older PHP still has a cross-version abort path; the failure is
        // classified from the stored request-body exception regardless of errno
        // (a truncated request may reach the server first on those versions).
        $abortsOnBodyReadFailure = isset($conf[\CURLOPT_READFUNCTION]);

        if ($progress !== null || $abortsOnBodyReadFailure) {
            /** @var (callable(int, int, int, int): mixed)|null $progress */
            $conf[\CURLOPT_NOPROGRESS] = false;
            $progressCallback = static function ($resource, $downloadSize, $downloaded, $uploadSize, $uploaded) use ($easy, $progress): int {
                // Abort the transfer when the request body read failed (the
                // cross-version abort path, since older PHP ignores the read
                // callback's return). progressAborted is left unset so the
                // failure is classified from the stored request-body exception.
                if ($easy->bodyReadTimeoutException !== null || $easy->bodyReadException !== null) {
                    return 1;
                }

                if ($progress === null) {
                    return 0;
                }

                try {
                    if ($progress(
                        TransferByteCounter::progressValueToInt($downloadSize),
                        TransferByteCounter::progressValueToInt($downloaded),
                        TransferByteCounter::progressValueToInt($uploadSize),
                        TransferByteCounter::progressValueToInt($uploaded)
                    )) {
                        $easy->progressAborted = true;

                        return 1;
                    }

                    return 0;
                } catch (\Throwable $e) {
                    $easy->progressException = $e;

                    return 1;
                }
            };

            if (\defined('CURLOPT_XFERINFOFUNCTION')) {
                $conf[(int) \constant('CURLOPT_XFERINFOFUNCTION')] = $progressCallback;
            } else {
                $conf[\CURLOPT_PROGRESSFUNCTION] = $progressCallback;
            }
        }

        if (!empty($options['debug'])) {
            $conf[\CURLOPT_STDERR] = Utils::debugResource($options['debug']);
            $conf[\CURLOPT_VERBOSE] = true;
        }
    }

    /**
     * This function ensures that a response was set on a transaction. If one
     * was not set, then the request is retried if possible. This error
     * typically means you are sending a payload, curl encountered a
     * "Connection died, retrying a fresh connect" error, tried to rewind the
     * stream, and then encountered a "necessary data rewind wasn't possible"
     * error, causing the request to be sent through curl_multi_info_read()
     * without an error status.
     *
     * @param callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed> $handler
     *
     * @return PromiseInterface<ResponseInterface, mixed>
     */
    private static function retryFailedRewind(callable $handler, EasyHandle $easy, array $ctx): PromiseInterface
    {
        try {
            // Only rewind if the body has been read from.
            $body = $easy->request->getBody();
            if ($body->tell() > 0) {
                $body->rewind();
            }
        } catch (\Exception $e) {
            $ctx['error'] = 'The connection unexpectedly failed without '
                .'providing an error. The request would have been retried, '
                .'but attempting to rewind the request body failed. '
                .'Exception: '.$e;

            return self::createRejection($easy, $ctx, $e);
        }

        // Retry no more than 3 times before giving up.
        if (!isset($easy->options['_curl_retries'])) {
            $easy->options['_curl_retries'] = 1;
        } elseif ($easy->options['_curl_retries'] == 2) {
            $ctx['error'] = 'The cURL request was retried 3 times '
                .'and did not succeed. The most likely reason for the failure '
                .'is that cURL was unable to rewind the body of the request '
                .'and subsequent retries resulted in the same error. Turn on '
                .'the debug option to see what went wrong. See '
                .'https://bugs.php.net/bug.php?id=47204 for more information.';

            return self::createRejection($easy, $ctx);
        } else {
            ++$easy->options['_curl_retries'];
        }

        return $handler($easy->request, $easy->options);
    }

    private function createHeaderFn(EasyHandle $easy): callable
    {
        if (isset($easy->options['on_headers'])) {
            $onHeaders = $easy->options['on_headers'];

            if (!\is_callable($onHeaders)) {
                throw new InvalidArgumentException('on_headers must be callable');
            }
        } else {
            $onHeaders = null;
        }

        $startingResponse = false;

        return static function ($ch, string $h) use (
            $onHeaders,
            $easy,
            &$startingResponse
        ): int {
            $value = \trim($h);
            if ($value === '') {
                $startingResponse = true;
                try {
                    $easy->createResponse();
                } catch (\Throwable $e) {
                    $easy->response = null;
                    $easy->createResponseException = $e;

                    return -1;
                }
                if ($onHeaders !== null && $easy->response !== null) {
                    try {
                        $onHeaders($easy->response, $easy->request);
                    } catch (\Throwable $e) {
                        // Associate the exception with the handle and trigger
                        // a curl header write error by returning 0.
                        $easy->onHeadersException = $e;

                        return -1;
                    }
                }
                if (self::responseContentLengthOverflows($easy)) {
                    return -1;
                }
            } elseif ($startingResponse) {
                $startingResponse = false;
                $easy->headers = [$value];
            } else {
                $easy->headers[] = $value;
            }

            return \strlen($h);
        };
    }

    public function __destruct()
    {
        try {
            $this->doClose(false);
        } catch (\Throwable $e) {
            // Destructors must not throw.
        }
    }
}
