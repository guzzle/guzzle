<?php

namespace GuzzleHttp\Handler;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise as P;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\LazyOpenStream;
use GuzzleHttp\TransferStats;
use GuzzleHttp\TransportSharing;
use GuzzleHttp\Utils;
use Psr\Http\Message\RequestInterface;
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
     * @deprecated
     */
    public const LOW_CURL_VERSION_NUMBER = '7.21.2';

    /**
     * @var resource[]|\CurlHandle[]
     */
    private $handles = [];

    /**
     * @var int Total number of idle handles to keep in cache
     */
    private $maxHandles;

    /**
     * @var resource|\CurlShareHandle|null
     */
    private $shareHandle;

    /**
     * @var string
     */
    private $shareMode;

    /**
     * @param int                            $maxHandles  Maximum number of idle handles.
     * @param resource|\CurlShareHandle|null $shareHandle
     */
    public function __construct(int $maxHandles, string $shareMode = TransportSharing::NONE, $shareHandle = null)
    {
        $this->maxHandles = $maxHandles;
        $this->shareMode = CurlShareHandleState::normalizeMode($shareMode, 'transport_sharing');

        if ($this->shareMode === TransportSharing::NONE && $shareHandle !== null) {
            throw new \InvalidArgumentException('A cURL share handle cannot be provided when transport sharing is disabled.');
        }

        if ($this->shareMode !== TransportSharing::NONE && $shareHandle === null) {
            throw new \InvalidArgumentException('A cURL share handle is required when transport sharing is enabled.');
        }

        if ($shareHandle !== null && !self::isCurlShareHandle($shareHandle)) {
            throw new \InvalidArgumentException('A cURL share handle must be an instance of CurlShareHandle or a curl_share resource.');
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

        return $value instanceof \CurlShareHandle;
    }

    public function create(RequestInterface $request, array $options): EasyHandle
    {
        $protocolVersion = $request->getProtocolVersion();

        if ('' === $protocolVersion) {
            \trigger_deprecation('guzzlehttp/guzzle', '7.11', 'Sending a request with an empty protocol version is deprecated; guzzlehttp/guzzle 8.0 will reject empty protocol versions.');

            $protocolVersion = '1.1';
            $request = \GuzzleHttp\Psr7\Utils::modifyRequest($request, ['version' => $protocolVersion]);
        }

        if ('2' === $protocolVersion || '2.0' === $protocolVersion) {
            if (!self::supportsHttp2()) {
                throw new ConnectException('HTTP/2 is supported by the cURL handler, however libcurl is built without HTTP/2 support.', $request);
            }
        } elseif ('1.0' !== $protocolVersion && '1.1' !== $protocolVersion) {
            throw new ConnectException(sprintf('HTTP/%s is not supported by the cURL handler.', $protocolVersion), $request);
        }

        if (isset($options['curl']['body_as_string'])) {
            $options['_body_as_string'] = $options['curl']['body_as_string'];
            unset($options['curl']['body_as_string']);
        }

        self::triggerUnsupportedRequestOptionDeprecations($options);
        $this->rejectRequestLevelShareConflict($options);
        self::triggerConflictingCurlOptionDeprecations($options);

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

        $conf[\CURLOPT_HEADERFUNCTION] = $this->createHeaderFn($easy);
        if ($this->shareHandle !== null) {
            if (!\defined('CURLOPT_SHARE')) {
                throw new \InvalidArgumentException('The configured cURL share handle requires CURLOPT_SHARE, but it is not available in the installed PHP cURL extension.');
            }

            $conf[(int) \constant('CURLOPT_SHARE')] = $this->shareHandle;
        }

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

        throw new \InvalidArgumentException('The request-level CURLOPT_SHARE cURL option cannot be combined with configured transport sharing.');
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

    private static function triggerConflictingCurlOptionDeprecations(array $options): void
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
                \trigger_deprecation(
                    'guzzlehttp/guzzle',
                    '7.11',
                    \sprintf(
                        'Passing %s in the "curl" request option is deprecated; guzzlehttp/guzzle 8.0 will reject this option because it conflicts with Guzzle-managed request handling. Use %s instead.',
                        $name,
                        $replacement
                    )
                );

                continue;
            }

            \trigger_deprecation(
                'guzzlehttp/guzzle',
                '7.11',
                \sprintf(
                    'Passing %s in the "curl" request option is deprecated; guzzlehttp/guzzle 8.0 will reject this option because it conflicts with Guzzle-managed cURL internals.',
                    $name
                )
            );
        }
    }

    private static function triggerUnsupportedRequestOptionDeprecations(array $options): void
    {
        if (\array_key_exists('stream_context', $options)) {
            \trigger_deprecation('guzzlehttp/guzzle', '7.11', 'Passing the "stream_context" request option to a cURL handler is deprecated; guzzlehttp/guzzle 8.0 will reject this option because cURL handlers ignore PHP stream context options.');
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

    private static function supportsHttp2(): bool
    {
        static $supportsHttp2 = null;

        if (null === $supportsHttp2) {
            $supportsHttp2 = self::supportsTls12()
                && defined('CURL_VERSION_HTTP2')
                && (\CURL_VERSION_HTTP2 & \curl_version()['features']);
        }

        return $supportsHttp2;
    }

    private static function supportsTls12(): bool
    {
        static $supportsTls12 = null;

        if (null === $supportsTls12) {
            $supportsTls12 = \CURL_SSLVERSION_TLSv1_2 & \curl_version()['features'];
        }

        return $supportsTls12;
    }

    private static function supportsTls13(): bool
    {
        static $supportsTls13 = null;

        if (null === $supportsTls13) {
            $supportsTls13 = defined('CURL_SSLVERSION_TLSv1_3')
                && (\CURL_SSLVERSION_TLSv1_3 & \curl_version()['features']);
        }

        return $supportsTls13;
    }

    public function release(EasyHandle $easy): void
    {
        $resource = $easy->handle;
        unset($easy->handle);

        if (\count($this->handles) >= $this->maxHandles) {
            if (PHP_VERSION_ID < 80000) {
                \curl_close($resource);
            }
        } else {
            // Remove all callback functions as they can hold onto references
            // and are not cleaned up by curl_reset. Using curl_setopt_array
            // does not work for some reason, so removing each one
            // individually.
            \curl_setopt($resource, \CURLOPT_HEADERFUNCTION, null);
            \curl_setopt($resource, \CURLOPT_READFUNCTION, null);
            \curl_setopt($resource, \CURLOPT_WRITEFUNCTION, null);
            \curl_setopt($resource, \CURLOPT_PROGRESSFUNCTION, null);
            \curl_reset($resource);
            $this->handles[] = $resource;
        }
    }

    /**
     * Completes a cURL transaction, either returning a response promise or a
     * rejected promise.
     *
     * @param callable(RequestInterface, array): PromiseInterface $handler
     * @param CurlFactoryInterface                                $factory Dictates how the handle is released
     */
    public static function finish(callable $handler, EasyHandle $easy, CurlFactoryInterface $factory): PromiseInterface
    {
        if (isset($easy->options['on_stats'])) {
            self::invokeStats($easy);
        }

        if (!$easy->response || $easy->errno) {
            return self::finishError($handler, $easy, $factory);
        }

        // Return the response if it is present and there is no error.
        $factory->release($easy);

        // Rewind the body of the response if possible.
        $body = $easy->response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }

        return new FulfilledPromise($easy->response);
    }

    private static function invokeStats(EasyHandle $easy): void
    {
        $curlStats = \curl_getinfo($easy->handle);
        $curlStats['appconnect_time'] = \curl_getinfo($easy->handle, \CURLINFO_APPCONNECT_TIME);
        $stats = new TransferStats(
            $easy->request,
            $easy->response,
            $curlStats['total_time'],
            $easy->errno,
            $curlStats
        );
        ($easy->options['on_stats'])($stats);
    }

    /**
     * @param callable(RequestInterface, array): PromiseInterface $handler
     */
    private static function finishError(callable $handler, EasyHandle $easy, CurlFactoryInterface $factory): PromiseInterface
    {
        // Get error information and release the handle to the factory.
        $ctx = [
            'errno' => $easy->errno,
            'error' => \curl_error($easy->handle),
            'appconnect_time' => \curl_getinfo($easy->handle, \CURLINFO_APPCONNECT_TIME),
        ] + \curl_getinfo($easy->handle);
        $ctx[self::CURL_VERSION_STR] = self::getCurlVersion();
        $factory->release($easy);

        // Retry when nothing is present or when curl failed to rewind.
        if (empty($easy->options['_err_message']) && (!$easy->errno || $easy->errno == 65)) {
            return self::retryFailedRewind($handler, $easy, $ctx);
        }

        return self::createRejection($easy, $ctx);
    }

    private static function getCurlVersion(): string
    {
        static $curlVersion = null;

        if (null === $curlVersion) {
            $curlVersion = \curl_version()['version'];
        }

        return $curlVersion;
    }

    private static function createRejection(EasyHandle $easy, array $ctx): PromiseInterface
    {
        static $connectionErrors = [
            \CURLE_OPERATION_TIMEOUTED => true,
            \CURLE_COULDNT_RESOLVE_HOST => true,
            \CURLE_COULDNT_CONNECT => true,
            \CURLE_SSL_CONNECT_ERROR => true,
            \CURLE_GOT_NOTHING => true,
        ];

        if ($easy->createResponseException) {
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

        // Create a connection exception if it was a specific error code.
        $error = isset($connectionErrors[$easy->errno])
            ? new ConnectException($message, $easy->request, null, $ctx)
            : new RequestException($message, $easy->request, $easy->response, null, $ctx);

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

        if (\defined('CURLOPT_PROTOCOLS')) {
            $conf[\CURLOPT_PROTOCOLS] = self::curlProtocolMask($protocols);
        }

        $version = $easy->request->getProtocolVersion();

        if ('2' === $version || '2.0' === $version) {
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
            throw new \InvalidArgumentException(\sprintf('%s must be a non-empty string', $option));
        }

        return \strtoupper($type);
    }

    private static function shouldValidateSslKeyFile(?string $type): bool
    {
        return $type !== 'ENG' && $type !== 'PROV';
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
            if (!\strcasecmp((string) $key, $name)) {
                unset($options['_headers'][$key]);

                return;
            }
        }
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

        if (!isset($options['sink'])) {
            // Use a default temp stream if no sink was set.
            $options['sink'] = \GuzzleHttp\Psr7\Utils::tryFopen('php://temp', 'w+');
        }
        $sink = $options['sink'];
        if (!\is_string($sink)) {
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
            $timeoutRequiresNoSignal |= $options['timeout'] < 1;
            $conf[\CURLOPT_TIMEOUT_MS] = $options['timeout'] * 1000;
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
            $timeoutRequiresNoSignal |= $options['connect_timeout'] < 1;
            $conf[\CURLOPT_CONNECTTIMEOUT_MS] = $options['connect_timeout'] * 1000;
        }

        if ($timeoutRequiresNoSignal && \strtoupper(\substr(\PHP_OS, 0, 3)) !== 'WIN') {
            $conf[\CURLOPT_NOSIGNAL] = true;
        }

        if (isset($options['proxy'])) {
            if (!\is_array($options['proxy'])) {
                $conf[\CURLOPT_PROXY] = $options['proxy'];
            } else {
                $scheme = $easy->request->getUri()->getScheme();
                if (isset($options['proxy'][$scheme])) {
                    if (
                        isset($options['proxy']['no'])
                        && Utils::isUriInNoProxy($easy->request->getUri(), $options['proxy']['no'])
                    ) {
                        unset($conf[\CURLOPT_PROXY]);
                    } else {
                        $conf[\CURLOPT_PROXY] = $options['proxy'][$scheme];
                    }
                }
            }
        }

        if (isset($options['crypto_method'])) {
            $protocolVersion = $easy->request->getProtocolVersion();

            // If HTTP/2, upgrade TLS 1.0 and 1.1 to 1.2
            if ('2' === $protocolVersion || '2.0' === $protocolVersion) {
                if (
                    \STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT === $options['crypto_method']
                    || \STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT === $options['crypto_method']
                    || \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT === $options['crypto_method']
                ) {
                    $conf[\CURLOPT_SSLVERSION] = \CURL_SSLVERSION_TLSv1_2;
                } elseif (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') && \STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT === $options['crypto_method']) {
                    if (!self::supportsTls13()) {
                        throw new \InvalidArgumentException('Invalid crypto_method request option: TLS 1.3 not supported by your version of cURL');
                    }
                    $conf[\CURLOPT_SSLVERSION] = \CURL_SSLVERSION_TLSv1_3;
                } else {
                    throw new \InvalidArgumentException('Invalid crypto_method request option: unknown version provided');
                }
            } elseif (\STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT === $options['crypto_method']) {
                $conf[\CURLOPT_SSLVERSION] = \CURL_SSLVERSION_TLSv1_0;
            } elseif (\STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT === $options['crypto_method']) {
                $conf[\CURLOPT_SSLVERSION] = \CURL_SSLVERSION_TLSv1_1;
            } elseif (\STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT === $options['crypto_method']) {
                if (!self::supportsTls12()) {
                    throw new \InvalidArgumentException('Invalid crypto_method request option: TLS 1.2 not supported by your version of cURL');
                }
                $conf[\CURLOPT_SSLVERSION] = \CURL_SSLVERSION_TLSv1_2;
            } elseif (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') && \STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT === $options['crypto_method']) {
                if (!self::supportsTls13()) {
                    throw new \InvalidArgumentException('Invalid crypto_method request option: TLS 1.3 not supported by your version of cURL');
                }
                $conf[\CURLOPT_SSLVERSION] = \CURL_SSLVERSION_TLSv1_3;
            } else {
                throw new \InvalidArgumentException('Invalid crypto_method request option: unknown version provided');
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
                    throw new \InvalidArgumentException('Invalid ssl_key request option');
                }
                if (isset($options['ssl_key'][1])) {
                    if (!\is_string($options['ssl_key'][1])) {
                        throw new \InvalidArgumentException('Invalid ssl_key request option');
                    }
                    $conf[\CURLOPT_SSLKEYPASSWD] = $options['ssl_key'][1];
                }
                $sslKey = $options['ssl_key'][0];
            }

            $sslKey = $sslKey ?? $options['ssl_key'];

            if (!\is_string($sslKey)) {
                throw new \InvalidArgumentException('Invalid ssl_key request option');
            }

            if (self::shouldValidateSslKeyFile($sslKeyType) && !\file_exists($sslKey)) {
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
            $conf[\CURLOPT_PROGRESSFUNCTION] = static function ($resource, int $downloadSize, int $downloaded, int $uploadSize, int $uploaded) use ($progress) {
                $progress($downloadSize, $downloaded, $uploadSize, $uploaded);
            };
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
     * @param callable(RequestInterface, array): PromiseInterface $handler
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
                        $onHeaders($easy->response);
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
        foreach ($this->handles as $id => $handle) {
            if (PHP_VERSION_ID < 80000) {
                \curl_close($handle);
            }

            unset($this->handles[$id]);
        }
    }
}
