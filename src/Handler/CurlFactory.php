<?php

namespace GuzzleHttp\Handler;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TimeoutException;
use GuzzleHttp\Promise as P;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\LazyOpenStream;
use GuzzleHttp\TransferStats;
use GuzzleHttp\Utils;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

/**
 * Creates curl resources from a request
 *
 * @final
 */
class CurlFactory implements CurlFactoryInterface
{
    public const CURL_VERSION_STR = 'curl_version';

    /**
     * @var resource[]|\CurlHandle[]
     */
    private $handles = [];

    /**
     * @var int Total number of idle handles to keep in cache
     */
    private $maxHandles;

    /**
     * @var bool
     */
    private $closed = false;

    /**
     * @param int $maxHandles Maximum number of idle handles.
     */
    public function __construct(int $maxHandles)
    {
        $this->maxHandles = $maxHandles;
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

        $easy = new EasyHandle();
        $easy->request = $request;
        $easy->options = $options;
        $conf = $this->getDefaultConf($easy);
        $this->applyMethod($easy, $conf);
        $this->applyHandlerOptions($easy, $conf);
        $this->applyHeaders($easy, $conf);
        unset($conf['_headers']);

        // Add handler options from the request configuration options
        if (isset($options['curl'])) {
            $conf = \array_replace($conf, $options['curl']);
        }

        if ('3' === $protocolVersion || '3.0' === $protocolVersion) {
            $conf[\CURLOPT_SSLVERSION] = \CURL_SSLVERSION_TLSv1_3;
        }

        $conf[\CURLOPT_HEADERFUNCTION] = $this->createHeaderFn($easy);
        $handle = $this->handles ? \array_pop($this->handles) : \curl_init();
        if (false === $handle) {
            throw new \RuntimeException('Can not initialize cURL handle.');
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
                throw new \InvalidArgumentException(\sprintf(
                    'Invalid cURL option %s.',
                    self::formatCurlOption($option)
                ));
            }

            try {
                $success = curl_setopt($handle, $option, $value);
            } catch (\Throwable $e) {
                throw new \InvalidArgumentException(
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
                throw new \InvalidArgumentException(\sprintf(
                    'Unable to set cURL option %s.',
                    self::formatCurlOption($option)
                ));
            }
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
     * @param callable(RequestInterface, array): PromiseInterface<ResponseInterface, mixed> $handler
     * @param CurlFactoryInterface                                                          $factory Dictates how the handle is released
     *
     * @return PromiseInterface<ResponseInterface, mixed>
     */
    public static function finish(callable $handler, EasyHandle $easy, CurlFactoryInterface $factory): PromiseInterface
    {
        /** @var callable|null $onStats */
        $onStats = $easy->options['on_stats'] ?? null;
        $stats = $onStats !== null ? self::createStats($easy) : null;

        if (!$easy->response || $easy->errno) {
            return self::finishError($handler, $easy, $factory, $stats, $onStats);
        }

        /** @var ResponseInterface $response */
        $response = $easy->response;

        // Return the response if it is present and there is no error.
        $factory->release($easy);

        if ($onStats !== null) {
            $onStats($stats);
        }

        // Rewind the body of the response if possible.
        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
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

        return new TransferStats(
            $easy->request,
            $easy->response,
            $curlStats['total_time'],
            $easy->errno,
            $curlStats
        );
    }

    /**
     * @param callable(RequestInterface, array): PromiseInterface<ResponseInterface, mixed> $handler
     * @param callable|null                                                                 $onStats
     *
     * @return PromiseInterface<ResponseInterface, mixed>
     */
    private static function finishError(callable $handler, EasyHandle $easy, CurlFactoryInterface $factory, ?TransferStats $stats, $onStats): PromiseInterface
    {
        // Get error information and release the handle to the factory.
        $ctx = self::createErrorContext($easy);
        $factory->release($easy);

        if ($onStats !== null) {
            $onStats($stats);
        }

        // Retry when nothing is present or when curl failed to rewind.
        if (empty($easy->options['_err_message']) && (!$easy->errno || $easy->errno == 65)) {
            return self::retryFailedRewind($handler, $easy, $ctx);
        }

        return self::createRejection($easy, $ctx);
    }

    private static function createErrorContext(EasyHandle $easy): array
    {
        $ctx = [
            'errno' => $easy->errno,
            'error' => \curl_error($easy->handle),
        ];

        if (!$easy->createResponseException) {
            $ctx['appconnect_time'] = \curl_getinfo($easy->handle, \CURLINFO_APPCONNECT_TIME);
            $ctx += \curl_getinfo($easy->handle);
        }

        CurlVersion::addToHandlerContext($ctx);

        return $ctx;
    }

    /**
     * @return PromiseInterface<ResponseInterface, mixed>
     */
    private static function createRejection(EasyHandle $easy, array $ctx): PromiseInterface
    {
        static $connectionErrors = [
            \CURLE_COULDNT_RESOLVE_HOST => true,
            \CURLE_COULDNT_RESOLVE_PROXY => true,
            \CURLE_COULDNT_CONNECT => true,
            \CURLE_SSL_CONNECT_ERROR => true,
            \CURLE_GOT_NOTHING => true,
        ];
        static $networkErrorsWithoutResponse;
        if ($networkErrorsWithoutResponse === null) {
            $networkErrorsWithoutResponse = [
                \CURLE_SEND_ERROR => true,
                \CURLE_RECV_ERROR => true,
            ];

            foreach ([
                'CURLE_PROXY',
                'CURLE_QUIC_CONNECT_ERROR',
                'CURLE_HTTP2',
                'CURLE_HTTP2_STREAM',
                'CURLE_HTTP3',
                'CURLE_PEER_FAILED_VERIFICATION',
                'CURLE_SSL_CACERT',
                'CURLE_SSL_PEER_CERTIFICATE',
                'CURLE_SSL_PINNEDPUBKEYNOTMATCH',
                'CURLE_SSL_INVALIDCERTSTATUS',
                'CURLE_SSL_CLIENTCERT',
            ] as $constant) {
                if (\defined($constant)) {
                    $networkErrorsWithoutResponse[(int) \constant($constant)] = true;
                }
            }
        }

        if ($easy->createResponseException) {
            /** @var PromiseInterface<ResponseInterface, mixed> */
            return P\Create::rejectionFor(
                new RequestException(
                    'An error was encountered while creating the response',
                    $easy->request,
                    null,
                    $easy->createResponseException,
                    $ctx
                )
            );
        }

        // If an exception was encountered during the onHeaders event, then
        // return a rejected promise that wraps that exception.
        if ($easy->onHeadersException) {
            /** @var PromiseInterface<ResponseInterface, mixed> */
            return P\Create::rejectionFor(
                new RequestException(
                    'An error was encountered during the on_headers event',
                    $easy->request,
                    $easy->response,
                    $easy->onHeadersException,
                    $ctx
                )
            );
        }

        if ($easy->progressException) {
            /** @var PromiseInterface<ResponseInterface, mixed> */
            return P\Create::rejectionFor(
                new RequestException(
                    'An error was encountered during the progress event',
                    $easy->request,
                    $easy->response,
                    $easy->progressException,
                    $ctx
                )
            );
        }

        if ($easy->progressAborted && $easy->errno === \CURLE_ABORTED_BY_CALLBACK) {
            /** @var PromiseInterface<ResponseInterface, mixed> */
            return P\Create::rejectionFor(
                new RequestException(
                    'The transfer was aborted by the progress callback',
                    $easy->request,
                    $easy->response,
                    null,
                    $ctx
                )
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

        $isNetworkError = isset($connectionErrors[$easy->errno])
            || (!$easy->response && isset($networkErrorsWithoutResponse[$easy->errno]));

        if ($easy->errno === \CURLE_OPERATION_TIMEOUTED) {
            $error = new TimeoutException($message, $easy->request, null, $ctx);
        } elseif ($isNetworkError) {
            $error = new ConnectException($message, $easy->request, null, $ctx);
        } else {
            $error = new RequestException($message, $easy->request, $easy->response, null, $ctx);
        }

        /** @var PromiseInterface<ResponseInterface, mixed> */
        return P\Create::rejectionFor($error);
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

        $conf[\CURLOPT_PROTOCOLS] = \CURLPROTO_HTTP | \CURLPROTO_HTTPS;

        $version = $easy->request->getProtocolVersion();

        if ('3' === $version || '3.0' === $version) {
            if (!\defined('CURL_HTTP_VERSION_3')) {
                throw new \RuntimeException('HTTP/3 is not supported by this cURL installation.');
            }
            $conf[\CURLOPT_HTTP_VERSION] = (int) \constant('CURL_HTTP_VERSION_3');
        } elseif ('2' === $version || '2.0' === $version) {
            $conf[\CURLOPT_HTTP_VERSION] = \CURL_HTTP_VERSION_2_0;
        } elseif ('1.1' === $version) {
            $conf[\CURLOPT_HTTP_VERSION] = \CURL_HTTP_VERSION_1_1;
        } else {
            $conf[\CURLOPT_HTTP_VERSION] = \CURL_HTTP_VERSION_1_0;
        }

        return $conf;
    }

    private function applyMethod(EasyHandle $easy, array &$conf): void
    {
        $body = $easy->request->getBody();
        $size = $body->getSize();

        if ($size === null || $size > 0) {
            $this->applyBody($easy->request, $easy->options, $conf);

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

    private function applyBody(RequestInterface $request, array $options, array &$conf): void
    {
        $size = $request->hasHeader('Content-Length')
            ? (int) $request->getHeaderLine('Content-Length')
            : null;

        // Send the body as a string if the size is less than 1MB OR if the
        // [curl][body_as_string] request value is set.
        if (($size !== null && $size < 1000000) || !empty($options['_body_as_string'])) {
            $conf[\CURLOPT_POSTFIELDS] = (string) $request->getBody();
            // Don't duplicate the Content-Length header
            $this->removeHeader('Content-Length', $conf);
            $this->removeHeader('Transfer-Encoding', $conf);
        } else {
            $conf[\CURLOPT_UPLOAD] = true;
            if ($size !== null) {
                $conf[\CURLOPT_INFILESIZE] = $size;
                $this->removeHeader('Content-Length', $conf);
            }
            $body = $request->getBody();
            if ($body->isSeekable()) {
                $body->rewind();
            }
            $conf[\CURLOPT_READFUNCTION] = static function ($ch, $fd, $length) use ($body) {
                return $body->read($length);
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
            if (!\strcasecmp($key, $name)) {
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
                        throw new \InvalidArgumentException("SSL CA bundle not found: {$options['verify']}");
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
            throw new \RuntimeException(\sprintf('Directory %s does not exist for sink value of %s', \dirname($sink), $sink));
        } else {
            $sink = new LazyOpenStream($sink, 'w+');
        }
        $easy->sink = $sink;
        $conf[\CURLOPT_WRITEFUNCTION] = static function ($ch, $write) use ($sink): int {
            return $sink->write($write);
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

        $selectedProxy = null;

        if (isset($options['proxy'])) {
            $proxy = $options['proxy'];
            if (!\is_array($proxy)) {
                if (!\is_string($proxy)) {
                    throw new \InvalidArgumentException('proxy must be a string or array');
                }

                $selectedProxy = $proxy;
                $conf[\CURLOPT_PROXY] = $proxy;
                $conf[\CURLOPT_NOPROXY] = '';
            } else {
                $scheme = $easy->request->getUri()->getScheme();
                if (isset($proxy[$scheme])) {
                    if (!\is_string($proxy[$scheme])) {
                        throw new \InvalidArgumentException('proxy values must be strings');
                    }

                    $uri = $easy->request->getUri();
                    $noProxy = isset($proxy['no']) ? Utils::normalizeNoProxy($proxy['no']) : [];
                    if ($noProxy !== [] && Utils::isUriInNoProxy($uri, $noProxy)) {
                        $conf[\CURLOPT_PROXY] = '';
                        $conf[\CURLOPT_NOPROXY] = '*';
                    } else {
                        $selectedProxy = $proxy[$scheme];
                        $conf[\CURLOPT_PROXY] = $proxy[$scheme];
                        $conf[\CURLOPT_NOPROXY] = '';
                    }
                }
            }
        }

        $proxyForConnectionReuse = self::getEffectiveProxyForConnectionReuse($selectedProxy, $options);
        if ($proxyForConnectionReuse !== null && self::requiresFreshConnectionForAuthenticatedProxy($easy->request, $proxyForConnectionReuse, $options)) {
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

            if ($isHttp3) {
                // HTTP/3 runs over QUIC and requires TLS 1.3.
                if (
                    \STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT === $cryptoMethod
                    || \STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT === $cryptoMethod
                    || \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT === $cryptoMethod
                    || \STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT === $cryptoMethod
                ) {
                    $conf[\CURLOPT_SSLVERSION] = \CURL_SSLVERSION_TLSv1_3;
                } else {
                    throw new \InvalidArgumentException('Invalid crypto_method request option: unknown version provided');
                }
            } elseif ($isHttp2) {
                // If HTTP/2, upgrade TLS 1.0 and 1.1 to 1.2.
                if (
                    \STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT === $cryptoMethod
                    || \STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT === $cryptoMethod
                    || \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT === $cryptoMethod
                ) {
                    $conf[\CURLOPT_SSLVERSION] = \CURL_SSLVERSION_TLSv1_2;
                } elseif (\STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT === $cryptoMethod) {
                    if (!CurlVersion::supportsTls13()) {
                        throw new \InvalidArgumentException('Invalid crypto_method request option: TLS 1.3 not supported by your version of cURL');
                    }
                    $conf[\CURLOPT_SSLVERSION] = \CURL_SSLVERSION_TLSv1_3;
                } else {
                    throw new \InvalidArgumentException('Invalid crypto_method request option: unknown version provided');
                }
            } elseif (\STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT === $cryptoMethod) {
                $conf[\CURLOPT_SSLVERSION] = \CURL_SSLVERSION_TLSv1_0;
            } elseif (\STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT === $cryptoMethod) {
                $conf[\CURLOPT_SSLVERSION] = \CURL_SSLVERSION_TLSv1_1;
            } elseif (\STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT === $cryptoMethod) {
                $conf[\CURLOPT_SSLVERSION] = \CURL_SSLVERSION_TLSv1_2;
            } elseif (\STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT === $cryptoMethod) {
                if (!CurlVersion::supportsTls13()) {
                    throw new \InvalidArgumentException('Invalid crypto_method request option: TLS 1.3 not supported by your version of cURL');
                }
                $conf[\CURLOPT_SSLVERSION] = \CURL_SSLVERSION_TLSv1_3;
            } else {
                throw new \InvalidArgumentException('Invalid crypto_method request option: unknown version provided');
            }
        }

        if (isset($options['cert'])) {
            $cert = $options['cert'];
            if (\is_array($cert)) {
                if (!isset($cert[0]) || !\is_string($cert[0])) {
                    throw new \InvalidArgumentException('Invalid cert request option');
                }
                if (isset($cert[1])) {
                    if (!\is_string($cert[1])) {
                        throw new \InvalidArgumentException('Invalid cert request option');
                    }
                    $conf[\CURLOPT_SSLCERTPASSWD] = $cert[1];
                }
                $cert = $cert[0];
            }
            if (!\is_string($cert)) {
                throw new \InvalidArgumentException('Invalid cert request option');
            }
            if (!\file_exists($cert)) {
                throw new \InvalidArgumentException("SSL certificate not found: {$cert}");
            }
            // OpenSSL (versions 0.9.3 and later) also support "P12" for PKCS#12-encoded files.
            // see https://curl.se/libcurl/c/CURLOPT_SSLCERTTYPE.html
            $ext = pathinfo($cert, \PATHINFO_EXTENSION);
            if (preg_match('#^(der|p12)$#i', $ext)) {
                $conf[\CURLOPT_SSLCERTTYPE] = strtoupper($ext);
            }
            $conf[\CURLOPT_SSLCERT] = $cert;
        }

        if (isset($options['ssl_key'])) {
            if (\is_array($options['ssl_key'])) {
                if (\count($options['ssl_key']) === 2) {
                    [$sslKey, $conf[\CURLOPT_SSLKEYPASSWD]] = $options['ssl_key'];
                } else {
                    [$sslKey] = $options['ssl_key'];
                }
            }

            $sslKey = $sslKey ?? $options['ssl_key'];

            if (!\file_exists($sslKey)) {
                throw new \InvalidArgumentException("SSL private key not found: {$sslKey}");
            }
            $conf[\CURLOPT_SSLKEY] = $sslKey;
        }

        if (isset($options['progress'])) {
            $progress = $options['progress'];
            if (!\is_callable($progress)) {
                throw new \InvalidArgumentException('progress client option must be callable');
            }
            $conf[\CURLOPT_NOPROGRESS] = false;
            $progressCallback = static function ($resource, $downloadSize, $downloaded, $uploadSize, $uploaded) use ($easy, $progress): int {
                try {
                    if ($progress($downloadSize, $downloaded, $uploadSize, $uploaded)) {
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
     * @param callable(RequestInterface, array): PromiseInterface<ResponseInterface, mixed> $handler
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
        } catch (\RuntimeException $e) {
            $ctx['error'] = 'The connection unexpectedly failed without '
                .'providing an error. The request would have been retried, '
                .'but attempting to rewind the request body failed. '
                .'Exception: '.$e;

            return self::createRejection($easy, $ctx);
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
                throw new \InvalidArgumentException('on_headers must be callable');
            }
        } else {
            $onHeaders = null;
        }

        return static function ($ch, $h) use (
            $onHeaders,
            $easy,
            &$startingResponse
        ) {
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
                if ($onHeaders !== null) {
                    try {
                        $onHeaders($easy->response, $easy->request);
                    } catch (\Throwable $e) {
                        // Associate the exception with the handle and trigger
                        // a curl header write error by returning 0.
                        $easy->onHeadersException = $e;

                        return -1;
                    }
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
