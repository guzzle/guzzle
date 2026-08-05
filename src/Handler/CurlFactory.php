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
use GuzzleHttp\Multiplexing;
use GuzzleHttp\NonSerializableTrait;
use GuzzleHttp\Promise as P;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\ProxyOptions;
use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\Exception\TimeoutException;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\LazyOpenStream;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\TransferStats;
use GuzzleHttp\TransportSharing;
use GuzzleHttp\Utils;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

/**
 * Creates curl resources from a request
 */
final class CurlFactory implements CurlFactoryInterface
{
    use NonSerializableTrait;

    private const DELEGATED_PROXY_TUNNEL_OWNER = 'proxy-tunnel:delegated-to-libcurl';

    /**
     * String-valued proxy credential cURL options whose values feed the
     * connection-reuse section signatures. Stringable values are cast
     * exactly once, before signature computation, so the signature and
     * ext-curl observe the same string; a stateful __toString() could
     * otherwise produce one value for the signature and a different one on
     * the wire, giving two credentials the same section. Numeric options
     * (CURLOPT_PROXYTYPE, CURLOPT_PROXY_SSLVERSION) and blob options
     * (CURLOPT_PROXY_SSLCERT_BLOB) are deliberately excluded.
     */
    private const STRINGABLE_PROXY_CREDENTIAL_OPTIONS = [
        'CURLOPT_PROXYUSERPWD',
        'CURLOPT_PROXYUSERNAME',
        'CURLOPT_PROXYPASSWORD',
        'CURLOPT_PROXY_SSLCERT',
        'CURLOPT_PROXY_SSLKEY',
        'CURLOPT_PROXY_KEYPASSWD',
        'CURLOPT_PROXY_TLSAUTH_USERNAME',
        'CURLOPT_PROXY_TLSAUTH_PASSWORD',
    ];

    private const PERSISTENT_REQUIRE_FRESH_PROXY_TUNNEL_MESSAGE = 'Persistent cURL sharing is required, but this request requires a fresh proxy tunnel connection.';

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
     * Guzzle's default connect timeout in milliseconds, replacing libcurl's
     * 300 seconds.
     */
    private const DEFAULT_CONNECT_TIMEOUT_MS = 60000;

    /**
     * Milliseconds passed to libcurl to disable the connect timeout: libcurl
     * treats zero as its own 300-second default, so disabling means passing
     * the largest value a 32-bit libcurl long accepts.
     */
    private const CONNECT_TIMEOUT_DISABLED_MS = 2147483647;

    /**
     * @var resource[]|\CurlHandle[]
     */
    private array $handles = [];

    /**
     * @var string|null Owner signature of the proxy tunnels that pooled idle
     *                  handles may still hold
     */
    private ?string $proxyTunnelOwner = null;

    /**
     * @var bool Whether an in-domain handle has been pooled since the last purge
     */
    private bool $poolMayHoldTunnels = false;

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
     * @var bool Whether the configured share handle may own a connection
     *           cache populated outside this factory
     */
    private bool $opaqueShareConnectionCache = false;

    /**
     * @param int                                                                            $maxHandles  Maximum number of idle handles.
     * @param resource|\CurlShareHandle|\CurlSharePersistentHandle|CurlShareHandleState|null $shareHandle
     */
    public function __construct(int $maxHandles, string $shareMode = TransportSharing::NONE, $shareHandle = null)
    {
        $this->maxHandles = $maxHandles;
        $this->shareMode = CurlShareHandleState::normalizeMode($shareMode, 'transport_sharing');

        if ($shareHandle instanceof CurlShareHandleState) {
            if ($shareHandle->mode !== $this->shareMode) {
                throw new InvalidArgumentException('The cURL share handle state mode does not match the configured transport sharing mode.');
            }

            // A Guzzle-created handler-lifetime state locks only DNS and TLS
            // session data, so its handle can never own a connection cache. A
            // persistent state's connection cache is worker-global, so other
            // producers in the worker may populate it.
            $this->opaqueShareConnectionCache = $shareHandle->mode === TransportSharing::PERSISTENT_PREFER
                || $shareHandle->mode === TransportSharing::PERSISTENT_REQUIRE;
            $shareHandle = $shareHandle->handle;
        } elseif ($shareHandle !== null) {
            // An externally supplied handle's cached contents cannot be
            // inspected from PHP, so it may own a connection cache populated
            // outside this factory.
            $this->opaqueShareConnectionCache = true;
        }

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

    public function create(
        #[\SensitiveParameter]
        RequestInterface $request,
        #[\SensitiveParameter]
        array $options
    ): EasyHandle {
        $this->assertOpen();
        self::validateRequestUriScheme($request);

        $protocolVersion = $request->getProtocolVersion();

        if ('' === $protocolVersion) {
            throw new RequestException('HTTP protocol version must not be empty.', $request);
        }

        if (1 !== \preg_match('/^\d+(?:\.\d+)?$/D', $protocolVersion)) {
            throw new RequestException('HTTP protocol version must be a valid HTTP version number.', $request);
        }

        CurlVersion::ensureSupported($request);

        $multiplex = self::normalizeMultiplex($options);

        if ('3' === $protocolVersion || '3.0' === $protocolVersion) {
            if (!CurlVersion::supportsHttp3()) {
                if (\in_array($multiplex, [Multiplexing::REQUIRE_EAGER, Multiplexing::REQUIRE_WAIT], true)) {
                    throw new RequestException('Required multiplexing for HTTP/3 needs libcurl 8.13.0 or newer built with HTTP/3 support.', $request);
                }

                throw new RequestException('HTTP/3 is supported by the cURL handler, however the installed PHP cURL extension or libcurl does not support HTTP/3.', $request);
            }
        } elseif ('2' === $protocolVersion || '2.0' === $protocolVersion) {
            if (!CurlVersion::supportsHttp2()) {
                if (\in_array($multiplex, [Multiplexing::REQUIRE_EAGER, Multiplexing::REQUIRE_WAIT], true)) {
                    throw new RequestException('Required multiplexing needs libcurl 8.14.0 or newer built with HTTP/2 support.', $request);
                }

                throw new RequestException('HTTP/2 is supported by the cURL handler, however libcurl 7.65.2 or newer built with HTTP/2 support is required.', $request);
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
        self::assertOnTrailersCallable($options);
        $this->rejectRequestLevelShareConflict($options);
        $this->rejectPersistentRequireConnectionReuseConflicts($options);
        self::rejectUnsupportedCurlOptions($options);
        self::rejectConflictingCurlOptions($options);

        $framing = RequestFraming::analyze($request, $request->getMethod() !== 'HEAD');
        $request = $framing->request;

        $managedProxyAuthorization = self::managedProxyAuthorizationHeaderLines($request);

        $easy = new EasyHandle();
        $easy->request = $request;
        $easy->options = $options;
        $conf = $this->getDefaultConf($easy);
        $this->applyMethod($easy, $conf, $framing);
        $this->applyHandlerOptions($easy, $conf);
        $this->applyHeaders($easy, $conf);
        unset($conf['_headers']);

        // Add handler options from the request configuration options
        if (isset($options['curl'])) {
            $conf = \array_replace($conf, $options['curl']);
        }

        self::normalizeStringableProxyCredentialOptions($conf);

        if (\in_array($multiplex, [Multiplexing::REQUIRE_EAGER, Multiplexing::REQUIRE_WAIT], true)) {
            self::assertRequiredMultiplexAuthSupported($conf);
        }

        self::applyProxyConnectHeaderSuppression($request, $conf);
        self::normalizeCurlHeaderOptions($conf);
        self::applyProxyAuthorizationHeaderHandling($request, $conf, $managedProxyAuthorization);
        // Validate the managed lines appended above too: a custom
        // RequestInterface can return unvalidated header values.
        self::normalizeCurlHeaderOptions($conf);

        if ($this->shareHandle !== null) {
            // Conservative blanket mode: a configured share handle hides the
            // pooled connections' provenance, so sectioned reuse cannot reason
            // about them.
            $this->forceFreshConnectionForAuthenticatedProxy($request, $conf);
        } else {
            $signature = self::proxyTunnelSignature($request, $conf);
            $easy->proxyTunnelSignature = $signature;
            if ($signature !== null && $signature !== $this->proxyTunnelOwner) {
                if ($this->poolMayHoldTunnels) {
                    // Pooled idle handles may hold a different owner's tunnel.
                    $this->discardIdleHandles();
                    $this->poolMayHoldTunnels = false;
                }
                // The first in-domain owner latches without purging: the pool
                // provably holds no in-domain tunnel yet.
                $this->proxyTunnelOwner = $signature;
            }
        }

        $easy->effectiveProxy = self::getEffectiveProxy($conf);

        $conf[\CURLOPT_HEADERFUNCTION] = $this->createHeaderFn($easy);
        if ($this->shareHandle !== null) {
            if (!\defined('CURLOPT_SHARE')) {
                throw new RequestException('The configured cURL share handle requires CURLOPT_SHARE, but it is not available in the installed PHP cURL extension.', $easy->request);
            }

            $conf[(int) \constant('CURLOPT_SHARE')] = $this->shareHandle;
        }

        if (\defined('CURLOPT_PIPEWAIT')) {
            $easy->usesPipewait = !empty($conf[(int) \constant('CURLOPT_PIPEWAIT')]);
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
    private function applyCurlOptions(
        $handle,
        #[\SensitiveParameter]
        array $conf
    ): void {
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

    /**
     * @param array<int|string, mixed> $conf
     */
    private static function normalizeStringableProxyCredentialOptions(
        #[\SensitiveParameter]
        array &$conf
    ): void {
        foreach (self::STRINGABLE_PROXY_CREDENTIAL_OPTIONS as $name) {
            if (!\defined($name)) {
                continue;
            }

            $option = (int) \constant($name);
            if (!isset($conf[$option]) || !\is_object($conf[$option]) || !\method_exists($conf[$option], '__toString')) {
                continue;
            }

            try {
                $conf[$option] = (string) $conf[$option];
            } catch (\Throwable $e) {
                // Wrap the failure exactly as applyCurlOptions() does for a
                // value that cannot be applied.
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
        }
    }

    private function rejectRequestLevelShareConflict(
        #[\SensitiveParameter]
        array $options
    ): void {
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

    private function rejectPersistentRequireConnectionReuseConflicts(
        #[\SensitiveParameter]
        array $options
    ): void {
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

    private static function normalizeMultiplex(
        #[\SensitiveParameter]
        array $options
    ): string {
        $multiplex = $options['multiplex'] ?? null;

        if ($multiplex === null) {
            return Multiplexing::WAIT;
        }

        if (!\in_array($multiplex, [Multiplexing::NONE, Multiplexing::EAGER, Multiplexing::WAIT, Multiplexing::REQUIRE_EAGER, Multiplexing::REQUIRE_WAIT], true)) {
            throw new InvalidArgumentException(\sprintf(
                'The "multiplex" option must be null or a GuzzleHttp\\Multiplexing::* constant; received %s.',
                \get_debug_type($multiplex)
            ));
        }

        return $multiplex;
    }

    private static function assertRequiredMultiplexSupported(
        #[\SensitiveParameter]
        EasyHandle $easy
    ): void {
        if (!CurlVersion::supportsRequiredHttp2Multiplex()) {
            throw new RequestException('Required multiplexing needs libcurl 8.14.0 or newer built with HTTP/2 support.', $easy->request);
        }

        $proxy = ProxyEnv::resolveProxySelection($easy->request->getUri(), $easy->options['proxy'] ?? null);
        self::assertSelectedProxySupported($proxy->getProxy(), $easy->request);

        if ('https' !== $easy->request->getUri()->getScheme() && $proxy->hasProxy()) {
            throw new RequestException('Required multiplexing cannot be guaranteed for cleartext requests sent through a proxy.', $easy->request);
        }
    }

    /**
     * libcurl forces NTLM-authenticated transfers onto HTTP/1.1: when the
     * server picks NTLM from the offered mask, the connection is closed and the
     * request is retried over HTTP/1.1 whatever HTTP version was asked for,
     * silently defeating the required protocol guarantee on both cleartext and
     * TLS routes. The final merged mask is checked because the raw
     * CURLOPT_HTTPAUTH cURL option is allow-listed, and any mask permitting
     * NTLM, such as CURLAUTH_ANY, is rejected because the selection is
     * server-controlled.
     *
     * @param array<int|string, mixed> $conf
     */
    private static function assertRequiredMultiplexAuthSupported(
        #[\SensitiveParameter]
        array $conf
    ): void {
        if (!\array_key_exists(\CURLOPT_HTTPAUTH, $conf)) {
            return;
        }

        $auth = $conf[\CURLOPT_HTTPAUTH];
        if (!\is_scalar($auth)) {
            throw new InvalidArgumentException('The "multiplex" request option cannot be required when the final CURLOPT_HTTPAUTH cURL option value is not an integer.');
        }

        $ntlmBits = \CURLAUTH_NTLM;
        if (\defined('CURLAUTH_NTLM_WB')) {
            $ntlmBits |= (int) \constant('CURLAUTH_NTLM_WB');
        }

        if (((int) $auth & $ntlmBits) !== 0) {
            throw new InvalidArgumentException('The "multiplex" request option cannot be required when the final CURLOPT_HTTPAUTH cURL option value permits NTLM; libcurl retries NTLM authentication over HTTP/1.1.');
        }
    }

    private static function assertSelectedProxySupported(
        #[\SensitiveParameter]
        ?string $selectedProxy,
        #[\SensitiveParameter]
        RequestInterface $request
    ): void {
        if ($selectedProxy === null || $selectedProxy === '') {
            return;
        }

        $scheme = ProxyOptions::proxyScheme($selectedProxy);

        if (!\in_array($scheme, ['http', 'https', 'socks4', 'socks4a', 'socks5', 'socks5h'], true)) {
            throw new InvalidArgumentException(\sprintf('The "%s" proxy scheme is not supported by the cURL handler.', Psr7\DiagnosticValue::escape($scheme)));
        }

        if ($scheme === 'https' && !CurlVersion::supportsHttpsProxy()) {
            throw new RequestException('HTTPS proxies are not supported by the installed libcurl; libcurl 7.54.0 or newer built with HTTPS-proxy support is required.', $request);
        }
    }

    /**
     * @param int|string $option
     */
    private static function formatCurlOption($option): string
    {
        if (!\is_int($option)) {
            return \sprintf('"%s"', Psr7\DiagnosticValue::escape((string) $option));
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

    private static function rejectConflictingCurlOptions(
        #[\SensitiveParameter]
        array $options
    ): void {
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

    private static function rejectUnsupportedCurlOptions(
        #[\SensitiveParameter]
        array $options
    ): void {
        if (!isset($options['curl']) || !\is_array($options['curl']) || $options['curl'] === []) {
            return;
        }

        $supportedOptions = self::supportedCurlOptions();
        $conflictingOptions = self::conflictingCurlOptions();

        foreach ($options['curl'] as $option => $_) {
            if (
                !\is_int($option)
                || \array_key_exists($option, $supportedOptions)
                || \array_key_exists($option, $conflictingOptions)
            ) {
                continue;
            }

            throw new InvalidArgumentException(\sprintf(
                'Passing %s in the "curl" request option is not supported because it is outside the built-in cURL handlers\' allow-list.',
                self::formatCurlOption($option)
            ));
        }
    }

    private static function rejectUnsupportedRequestOptions(
        #[\SensitiveParameter]
        array $options
    ): void {
        if (\array_key_exists('stream_context', $options)) {
            throw new InvalidArgumentException('Passing the "stream_context" request option to a cURL handler is not supported because cURL handlers ignore PHP stream context options.');
        }
    }

    private static function assertOnStatsCallable(
        #[\SensitiveParameter]
        array $options
    ): void {
        if (isset($options['on_stats']) && !\is_callable($options['on_stats'])) {
            throw new InvalidArgumentException('on_stats must be callable');
        }
    }

    private static function assertOnTrailersCallable(
        #[\SensitiveParameter]
        array $options
    ): void {
        if (isset($options['on_trailers']) && !\is_callable($options['on_trailers'])) {
            throw new InvalidArgumentException('on_trailers must be callable');
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
        self::addConflictingCurlOption($options, 'CURLOPT_PROXYTYPE', 'the "proxy" request option with a scheme-prefixed URL');
        self::addConflictingCurlOption($options, 'CURLOPT_FOLLOWLOCATION', 'the "allow_redirects" request option');
        self::addConflictingCurlOption($options, 'CURLOPT_MAXREDIRS', 'the "allow_redirects" request option');
        self::addConflictingCurlOption($options, 'CURLOPT_POSTREDIR', 'the "allow_redirects" request option');
        self::addConflictingCurlOption($options, 'CURLOPT_REDIR_PROTOCOLS', 'the "allow_redirects" request option');
        self::addConflictingCurlOption($options, 'CURLOPT_REDIR_PROTOCOLS_STR', 'the "allow_redirects" request option');
        self::addConflictingCurlOption($options, 'CURLOPT_PROTOCOLS', 'the "protocols" request option');
        self::addConflictingCurlOption($options, 'CURLOPT_PROTOCOLS_STR', 'the "protocols" request option');
        self::addConflictingCurlOption($options, 'CURLOPT_HTTP_VERSION', 'the request protocol version');
        self::addConflictingCurlOption($options, 'CURLOPT_PIPEWAIT', 'the "multiplex" request option');
        self::addConflictingCurlOption($options, 'CURLOPT_IPRESOLVE', 'the "force_ip_resolve" request option');
        self::addConflictingCurlOption($options, 'CURLOPT_SSL_VERIFYPEER', 'the "verify" request option');
        self::addConflictingCurlOption($options, 'CURLOPT_SSL_VERIFYHOST', 'the "verify" request option');
        self::addConflictingCurlOption($options, 'CURLOPT_CAINFO', 'the "verify" request option');
        self::addConflictingCurlOption($options, 'CURLOPT_CAPATH', 'the "verify" request option');
        self::addConflictingCurlOption($options, 'CURLOPT_SSLVERSION', 'the "crypto_method" or "crypto_method_max" request option');
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
     * @return array<int, true>
     */
    private static function supportedCurlOptions(): array
    {
        static $options = null;

        if ($options !== null) {
            return $options;
        }

        $options = [];

        self::addSupportedCurlOption($options, 'CURLOPT_ADDRESS_SCOPE');
        self::addSupportedCurlOption($options, 'CURLOPT_CERTINFO');
        self::addSupportedCurlOption($options, 'CURLOPT_CONNECT_TO');
        self::addSupportedCurlOption($options, 'CURLOPT_DNS_CACHE_TIMEOUT');
        self::addSupportedCurlOption($options, 'CURLOPT_DNS_INTERFACE');
        self::addSupportedCurlOption($options, 'CURLOPT_DNS_LOCAL_IP4');
        self::addSupportedCurlOption($options, 'CURLOPT_DNS_LOCAL_IP6');
        self::addSupportedCurlOption($options, 'CURLOPT_DNS_SERVERS');
        self::addSupportedCurlOption($options, 'CURLOPT_DNS_SHUFFLE_ADDRESSES');
        self::addSupportedCurlOption($options, 'CURLOPT_ENCODING');
        self::addSupportedCurlOption($options, 'CURLOPT_FORBID_REUSE');
        self::addSupportedCurlOption($options, 'CURLOPT_FRESH_CONNECT');
        self::addSupportedCurlOption($options, 'CURLOPT_HAPPY_EYEBALLS_TIMEOUT_MS');
        self::addSupportedCurlOption($options, 'CURLOPT_HTTPAUTH');
        self::addSupportedCurlOption($options, 'CURLOPT_INTERFACE');
        self::addSupportedCurlOption($options, 'CURLOPT_LOCALPORT');
        self::addSupportedCurlOption($options, 'CURLOPT_LOCALPORTRANGE');
        self::addSupportedCurlOption($options, 'CURLOPT_LOW_SPEED_LIMIT');
        self::addSupportedCurlOption($options, 'CURLOPT_LOW_SPEED_TIME');
        self::addSupportedCurlOption($options, 'CURLOPT_MAXAGE_CONN');
        self::addSupportedCurlOption($options, 'CURLOPT_MAXCONNECTS');
        self::addSupportedCurlOption($options, 'CURLOPT_MAXLIFETIME_CONN');
        self::addSupportedCurlOption($options, 'CURLOPT_HTTPPROXYTUNNEL');
        self::addSupportedCurlOption($options, 'CURLOPT_PREREQFUNCTION');
        self::addSupportedCurlOption($options, 'CURLOPT_PROXYHEADER');
        self::addSupportedCurlOption($options, 'CURLOPT_PROXYUSERPWD');
        self::addSupportedCurlOption($options, 'CURLOPT_RESOLVE');
        self::addSupportedCurlOption($options, 'CURLOPT_SSL_CIPHER_LIST');
        self::addSupportedCurlOption($options, 'CURLOPT_SSL_EC_CURVES');
        self::addSupportedCurlOption($options, 'CURLOPT_TCP_FASTOPEN');
        self::addSupportedCurlOption($options, 'CURLOPT_TCP_KEEPALIVE');
        self::addSupportedCurlOption($options, 'CURLOPT_TCP_KEEPIDLE');
        self::addSupportedCurlOption($options, 'CURLOPT_TCP_KEEPINTVL');
        self::addSupportedCurlOption($options, 'CURLOPT_TCP_KEEPCNT');
        self::addSupportedCurlOption($options, 'CURLOPT_TCP_NODELAY');
        self::addSupportedCurlOption($options, 'CURLOPT_TLS13_CIPHERS');
        self::addSupportedCurlOption($options, 'CURLOPT_UNIX_SOCKET_PATH');
        self::addSupportedCurlOption($options, 'CURLOPT_USERPWD');

        return $options;
    }

    /**
     * @param array<int, true> $options
     */
    private static function addSupportedCurlOption(array &$options, string $constant): void
    {
        if (!\defined($constant)) {
            return;
        }

        $value = \constant($constant);
        if (\is_int($value)) {
            $options[$value] = true;
        }
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

    private static function validateRequestUriScheme(
        #[\SensitiveParameter]
        RequestInterface $request
    ): void {
        $scheme = $request->getUri()->getScheme();
        if ($scheme === '') {
            throw new RequestException('URI must include a scheme and host. Use an absolute URI, a network-path reference starting with //, or configure a base_uri.', $request);
        }

        if (!\in_array($scheme, ['http', 'https'], true)) {
            throw new RequestException(\sprintf("The scheme '%s' is not supported.", Psr7\DiagnosticValue::escape($scheme)), $request);
        }
    }

    public function release(
        #[\SensitiveParameter]
        EasyHandle $easy
    ): void {
        $this->assertOpen();

        $resource = $easy->handle;
        unset($easy->handle);

        if (
            \count($this->handles) >= $this->maxHandles
            || ($easy->proxyTunnelSignature !== null && $easy->proxyTunnelSignature !== $this->proxyTunnelOwner)
        ) {
            // Pool is full, or this handle belongs to a superseded tunnel
            // owner (an async create/release overlap can hand a stale-owner
            // handle back after a purge) - drop it instead of pooling it.
            $this->discardHandle($resource);

            return;
        }

        if ($easy->proxyTunnelSignature !== null) {
            // A pooled handle now carries the current owner's tunnel.
            $this->poolMayHoldTunnels = true;
        }

        // Remove all callback functions as they can hold onto references and
        // are not cleaned up by curl_reset. Using curl_setopt_array does not
        // work for some reason, so removing each one individually.
        $this->clearEasyHandleCallbacks($resource);
        \curl_reset($resource);
        $this->handles[] = $resource;
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

        if (\defined('CURLOPT_PREREQFUNCTION')) {
            curl_setopt($handle, (int) \constant('CURLOPT_PREREQFUNCTION'), null);
        }

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
    public static function finish(
        callable $handler,
        #[\SensitiveParameter]
        EasyHandle $easy,
        CurlFactoryInterface $factory
    ): PromiseInterface {
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

        if (isset($easy->options['on_trailers'])) {
            /** @var callable(array<string, list<string>>, ResponseInterface, RequestInterface): mixed $onTrailers */
            $onTrailers = $easy->options['on_trailers'];

            try {
                $onTrailers(self::headersFromTrailerLines($easy->trailers), $response, $easy->request);
            } catch (\Throwable $e) {
                $reason = new ResponseException(
                    'An error was encountered during the on_trailers event',
                    $easy->request,
                    $response,
                    $e
                );

                if ($onStats !== null && $stats !== null) {
                    // Report the ResponseException rather than errno 0 to match
                    // the response finalization stats above.
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
        }

        if ($onStats !== null && $stats !== null) {
            $onStats($stats);
        }

        /** @var PromiseInterface<ResponseInterface, mixed> */
        return P\Create::promiseFor($response);
    }

    private static function createStats(
        #[\SensitiveParameter]
        EasyHandle $easy
    ): TransferStats {
        $curlStats = \curl_getinfo($easy->handle);
        $curlStats['appconnect_time'] = \curl_getinfo($easy->handle, \CURLINFO_APPCONNECT_TIME);

        if ($easy->createResponseException) {
            $curlStats = [
                'total_time' => $curlStats['total_time'],
                'appconnect_time' => $curlStats['appconnect_time'],
            ];
        }

        $handlerErrorData = $easy->responseHeaderException ?? $easy->responseBodySizeException ?? $easy->errno;

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
    private static function finishError(
        callable $handler,
        #[\SensitiveParameter]
        EasyHandle $easy,
        CurlFactoryInterface $factory,
        ?TransferStats $stats,
        ?callable $onStats
    ): PromiseInterface {
        // Get error information and release the handle to the factory.
        $ctx = self::createErrorContext($easy);
        $factory->release($easy);

        if ($onStats !== null && $stats !== null) {
            $onStats($stats);
        }

        if (self::shouldRetryFailedRewind($easy)) {
            return self::retryFailedRewind($handler, $easy, $ctx);
        }

        if (self::isChallengeRewindFailure($easy)) {
            $ctx['error'] = 'The server issued an authentication challenge '
                .'after the request body had already been sent, and the body '
                .'could not be rewound to resend it. The request was not '
                .'retried because a retry replays the same challenge. See '
                .'https://bugs.php.net/bug.php?id=47204 for more information.';
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
            || $easy->responseHeaderException !== null
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

        if (self::isChallengeRewindFailure($easy)) {
            // Re-issuing the identical request replays the same challenge and
            // the same in-transfer rewind, so retrying is futile and the
            // challenge response is surfaced instead.
            return false;
        }

        // Two transfer outcomes warrant rewinding the body and retrying:
        //
        // - errno === CURLE_SEND_FAIL_REWIND (65): libcurl needed to rewind an
        //   already-partially-sent upload to resend it on a reused connection
        //   that died before any response arrived, but could not, because PHP
        //   registers no seek callback for a streamed request body. See
        //   https://bugs.php.net/bug.php?id=47204.
        //
        // - errno === 0: libcurl reported success yet no usable response
        //   reached us. This is the legacy curl_multi silent-failure variant of
        //   the same rewind problem. libcurl 7.61.1 fixed it to surface as
        //   CURLE_SEND_FAIL_REWIND instead (curl/curl@d6cf930), so this arm is
        //   only load-bearing for libcurl < 7.61.1 and may be removed once the
        //   minimum supported libcurl is >= 7.61.1.
        return $easy->errno === 0 || $easy->errno === self::CURLE_SEND_FAIL_REWIND;
    }

    /**
     * Whether the transfer failed because libcurl could not rewind the
     * request body to resend it in reply to a challenge response, such as a
     * 401 or 407 during multi-pass authentication. libcurl's only other
     * rewind triggers are followed redirects, which the built-in handlers
     * never enable, and reused connections that died, which cannot have
     * produced a response.
     */
    private static function isChallengeRewindFailure(EasyHandle $easy): bool
    {
        return $easy->errno === self::CURLE_SEND_FAIL_REWIND
            && $easy->response !== null
            && !self::hasLocalFailure($easy);
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
    private static function createRejection(
        #[\SensitiveParameter]
        EasyHandle $easy,
        #[\SensitiveParameter]
        array $ctx,
        #[\SensitiveParameter]
        ?\Throwable $previous = null
    ): PromiseInterface {
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

        if ($easy->responseHeaderException) {
            /** @var PromiseInterface<ResponseInterface, mixed> */
            return P\Create::rejectionFor($easy->responseHeaderException);
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

        $sanitizedError = self::sanitizeCurlError($ctx['error'] ?? '', $uri, $easy->effectiveProxy);

        $message = \sprintf(
            'cURL error %s: %s (%s)',
            $ctx['errno'],
            $sanitizedError,
            'see https://curl.se/libcurl/c/libcurl-errors.html'
        );

        if ('' !== $sanitizedError) {
            $redactedUriString = Psr7\DiagnosticValue::escape(Psr7\Utils::redactUserInfo($uri)->__toString());
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
        #[\SensitiveParameter]
        EasyHandle $easy,
        string $message,
        #[\SensitiveParameter]
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
            if (Psr7\Utils::caselessContains($error, $connectTimeoutError)) {
                return true;
            }
        }

        return Psr7\Utils::caselessContains($error, 'Failed to resolve')
            && Psr7\Utils::caselessContains($error, 'timeout');
    }

    private static function sanitizeCurlError(
        #[\SensitiveParameter]
        string $error,
        #[\SensitiveParameter]
        UriInterface $uri,
        #[\SensitiveParameter]
        ?string $proxy = null
    ): string {
        if ('' === $error) {
            return $error;
        }

        $error = self::redactProxyUserInfo($error, $proxy);

        $baseUri = $uri->withQuery('')->withFragment('');
        $baseUriString = $baseUri->__toString();

        if ('' !== $baseUriString) {
            $redactedUriString = Psr7\Utils::redactUserInfo($baseUri)->__toString();
            $error = str_replace($baseUriString, $redactedUriString, $error);
        }

        return Psr7\DiagnosticValue::escape($error);
    }

    private static function redactProxyUserInfo(
        #[\SensitiveParameter]
        string $error,
        #[\SensitiveParameter]
        ?string $proxy
    ): string {
        if ($proxy === null || $proxy === '') {
            return $error;
        }

        return Psr7\Utils::redactUserInfoInString($error, $proxy);
    }

    /**
     * @param array<int|string, mixed> $conf
     */
    private function forceFreshConnectionForAuthenticatedProxy(
        #[\SensitiveParameter]
        RequestInterface $request,
        #[\SensitiveParameter]
        array &$conf
    ): void {
        $proxy = self::getEffectiveProxy($conf);

        if (
            $proxy === null
            || (!self::requiresFreshConnectionForAuthenticatedProxy($request, $proxy, $conf)
                && !$this->isOpaqueShareAnonymousProxyTunnel($request, $proxy, $conf))
        ) {
            return;
        }

        if ($this->shareMode === TransportSharing::PERSISTENT_REQUIRE) {
            throw new InvalidArgumentException(self::PERSISTENT_REQUIRE_FRESH_PROXY_TUNNEL_MESSAGE);
        }

        $conf[\CURLOPT_FRESH_CONNECT] = true;
        $conf[\CURLOPT_FORBID_REUSE] = true;
    }

    /**
     * @param array<int|string, mixed> $conf
     */
    private function isOpaqueShareAnonymousProxyTunnel(RequestInterface $request, string $proxy, array $conf): bool
    {
        if (!$this->opaqueShareConnectionCache || !CurlVersion::supportsShareConnectionCaches()) {
            return false;
        }

        if (!self::usesProxyTunnel($request, $conf) || !self::isHttpProxyForConnectionReuse($proxy, $conf)) {
            return false;
        }

        if (
            self::hasCurlProxyAuthorizationHeader($conf)
            || self::hasCurlProxyTlsCredentials($conf)
            || self::hasCurlProxyCredentials($conf)
        ) {
            return false;
        }

        $proxyForParsing = \strpos($proxy, '://') === false ? 'http://'.$proxy : $proxy;
        $proxyParts = \parse_url($proxyForParsing);
        if (
            \is_array($proxyParts)
            && (\array_key_exists('user', $proxyParts) || \array_key_exists('pass', $proxyParts))
        ) {
            return false;
        }

        // From libcurl 7.57.0 an opaque share handle can own a connection
        // cache, and a tunnel seeded there with a literal Proxy-Authorization
        // header is never keyed on credentials, so an anonymous request could
        // inherit it on every later libcurl version. Requests carrying
        // recognized credential state keep the version-gated channel
        // safeguards in requiresFreshConnectionForAuthenticatedProxy().
        return true;
    }

    /**
     * @param array<int|string, mixed> $conf
     */
    private static function getEffectiveProxy(array $conf): ?string
    {
        if (!\array_key_exists(\CURLOPT_PROXY, $conf)) {
            return null;
        }

        $proxy = $conf[\CURLOPT_PROXY];

        return \is_string($proxy) && $proxy !== '' ? $proxy : null;
    }

    /**
     * @param array<int|string, mixed> $conf
     */
    private static function normalizeCurlHeaderOptions(
        #[\SensitiveParameter]
        array &$conf
    ): void {
        $options = [\CURLOPT_HTTPHEADER => 'CURLOPT_HTTPHEADER'];
        if (\defined('CURLOPT_PROXYHEADER')) {
            $options[(int) \constant('CURLOPT_PROXYHEADER')] = 'CURLOPT_PROXYHEADER';
        }

        foreach ($options as $option => $label) {
            if (!\array_key_exists($option, $conf) || !\is_array($conf[$option])) {
                continue;
            }

            $normalized = [];
            foreach ($conf[$option] as $key => $entry) {
                if (\is_object($entry) && \method_exists($entry, '__toString')) {
                    $entry = (string) $entry;
                } elseif (!\is_string($entry)) {
                    throw new InvalidArgumentException(\sprintf('%s entries must be strings or stringable objects.', $label));
                }

                if (\strpbrk($entry, "\r\n") !== false) {
                    throw new InvalidArgumentException(\sprintf('%s entries must not contain a carriage return or line feed.', $label));
                }

                $normalized[$key] = $entry;
            }

            $conf[$option] = $normalized;
        }
    }

    /**
     * @param array<int|string, mixed> $conf
     */
    private static function requiresFreshConnectionForAuthenticatedProxy(RequestInterface $request, string $proxy, array $conf): bool
    {
        // SOCKS authentication binds an identity to the connection itself, and
        // below 7.69.0 an opaque configured share may already contain a SOCKS
        // connection whose credential state Guzzle cannot inspect. Isolate
        // authenticated and anonymous requests so neither can inherit it.
        if (self::isSocksProxy($proxy, $conf)) {
            return !CurlVersion::supportsSocksProxyCredentialAwareConnectionReuse();
        }

        if (!self::usesProxyTunnel($request, $conf) || !self::isHttpProxyForConnectionReuse($proxy, $conf)) {
            return false;
        }

        $proxyForParsing = \strpos($proxy, '://') === false ? 'http://'.$proxy : $proxy;
        $proxyParts = \parse_url($proxyForParsing);
        if (!\is_array($proxyParts)) {
            return false;
        }

        if (self::hasCurlProxyAuthorizationHeader($conf)) {
            return true;
        }

        // A proxy client certificate or TLS-SRP authenticates the client to the
        // HTTPS proxy at the TLS layer; libcurl ignored TLS-SRP before 7.83.1
        // (CVE-2022-27782), so an old build can reuse a tunnel across those
        // identities. Force a fresh one, as the non-share signature path does.
        // See docs/contributing/curl-connection-reuse.md.
        if (
            !CurlVersion::supportsProxyTlsCredentialAwareConnectionReuse()
            && self::hasCurlProxyTlsCredentials($conf)
        ) {
            return true;
        }

        if (CurlVersion::supportsProxyCredentialAwareConnectionReuse()) {
            return false;
        }

        return \array_key_exists('user', $proxyParts)
            || \array_key_exists('pass', $proxyParts)
            || self::hasCurlProxyCredentials($conf);
    }

    /**
     * @param array<int|string, mixed> $conf
     */
    private static function applyProxyConnectHeaderSuppression(
        #[\SensitiveParameter]
        RequestInterface $request,
        #[\SensitiveParameter]
        array &$conf
    ): void {
        $proxy = $conf[\CURLOPT_PROXY] ?? null;

        if (!\is_string($proxy)
            || $proxy === ''
            || self::isSocksProxy($proxy, $conf)
            || !self::usesProxyTunnel($request, $conf)
        ) {
            return;
        }

        if (!CurlVersion::supportsProxyTunneling()) {
            throw new RequestException('Tunneling requests through an HTTP proxy is not supported by the installed libcurl; libcurl 7.54.0 or newer is required.', $request);
        }

        // Keep the proxy CONNECT reply out of the header callback so a
        // tunnelled transfer failure is not misclassified as a response
        // failure carrying the proxy's interim 200.
        $conf[(int) \constant('CURLOPT_SUPPRESS_CONNECT_HEADERS')] = true;
    }

    /**
     * @param array<int|string, mixed> $conf
     */
    private static function usesProxyTunnel(RequestInterface $request, array $conf): bool
    {
        $scheme = $request->getUri()->getScheme();

        if ('https' === $scheme) {
            return true;
        }

        // An HTTP proxy auto-switches to a CONNECT tunnel when CONNECT_TO
        // redirects the origin, so an http:// target with it set tunnels too.
        if ('http' === $scheme && self::hasCurlConnectTo($conf)) {
            return true;
        }

        return \defined('CURLOPT_HTTPPROXYTUNNEL')
            && \array_key_exists((int) \constant('CURLOPT_HTTPPROXYTUNNEL'), $conf)
            && (bool) $conf[(int) \constant('CURLOPT_HTTPPROXYTUNNEL')];
    }

    /**
     * @param array<int|string, mixed> $conf
     */
    private static function hasCurlConnectTo(array $conf): bool
    {
        if (!\defined('CURLOPT_CONNECT_TO')) {
            return false;
        }

        $option = (int) \constant('CURLOPT_CONNECT_TO');
        if (!\array_key_exists($option, $conf)) {
            return false;
        }

        $value = $conf[$option];

        return \is_array($value)
            ? $value !== []
            : $value !== null && $value !== false && $value !== '';
    }

    /**
     * @param array<int|string, mixed> $conf
     */
    private static function isHttpProxyForConnectionReuse(string $proxy, array $conf): bool
    {
        if (\strpos($proxy, '://') !== false) {
            $proxyParts = \parse_url($proxy);
            if (!\is_array($proxyParts) || !isset($proxyParts['scheme'])) {
                return false;
            }

            $proxyScheme = Psr7\Utils::asciiToLower($proxyParts['scheme']);

            return $proxyScheme === 'http' || $proxyScheme === 'https';
        }

        return !self::isSocksProxyType($conf[\CURLOPT_PROXYTYPE] ?? null);
    }

    private static function proxyScheme(string $proxy): ?string
    {
        $position = \strpos($proxy, '://');

        return $position === false ? null : Psr7\Utils::asciiToLower(\substr($proxy, 0, $position));
    }

    /**
     * @param array<int|string, mixed> $conf
     */
    private static function isSocksProxy(string $proxy, array $conf): bool
    {
        $scheme = self::proxyScheme($proxy);
        if ($scheme !== null) {
            if (\in_array($scheme, ['socks', 'socks4', 'socks4a', 'socks5', 'socks5h'], true)) {
                return true;
            }

            // libcurl preserves a raw SOCKS CURLOPT_PROXYTYPE behind an http
            // scheme, while every other scheme overrides the proxy type.
            if ($scheme !== 'http') {
                return false;
            }
        }

        return self::isSocksProxyType($conf[\CURLOPT_PROXYTYPE] ?? null);
    }

    /**
     * Computes the connection-reuse section signature for a SOCKS proxy.
     * libcurl compares SOCKS credentials on connection reuse from 7.69.0 (curl
     * #4835), so no sectioning is needed there. Older libcurl matches a SOCKS
     * proxy by type, host, and port only, so every SOCKS request is sectioned
     * by its credential state; hashing the credential-less state too keeps an
     * unauthenticated request from inheriting an authenticated connection. See
     * docs/contributing/curl-connection-reuse.md.
     *
     * @param array<int|string, mixed> $conf
     */
    private static function socksProxySignature(
        #[\SensitiveParameter]
        string $proxy,
        #[\SensitiveParameter]
        array $conf
    ): ?string {
        if (CurlVersion::supportsSocksProxyCredentialAwareConnectionReuse()) {
            return null;
        }

        $credentialState = [];
        foreach (['CURLOPT_PROXYUSERPWD', 'CURLOPT_PROXYUSERNAME', 'CURLOPT_PROXYPASSWORD', 'CURLOPT_PROXYTYPE'] as $name) {
            $credentialState[$name] = \defined($name)
                ? ($conf[(int) \constant($name)] ?? null)
                : null;
        }

        return \hash('sha256', \serialize(['socks', $proxy, $credentialState]));
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

    /**
     * @param array<int|string, mixed> $conf
     */
    private static function hasCurlProxyCredentials(array $conf): bool
    {
        foreach (['CURLOPT_PROXYUSERPWD', 'CURLOPT_PROXYUSERNAME', 'CURLOPT_PROXYPASSWORD'] as $option) {
            if (\defined($option) && \array_key_exists((int) \constant($option), $conf)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int|string, mixed> $conf
     */
    private static function hasCurlProxyTlsCredentials(array $conf): bool
    {
        foreach ([
            'CURLOPT_PROXY_SSLCERT',
            'CURLOPT_PROXY_SSLCERT_BLOB',
            'CURLOPT_PROXY_TLSAUTH_USERNAME',
            'CURLOPT_PROXY_TLSAUTH_PASSWORD',
        ] as $option) {
            if (\defined($option) && \array_key_exists((int) \constant($option), $conf)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int|string, mixed> $conf
     */
    private static function hasCurlProxyAuthorizationHeader(array $conf): bool
    {
        return self::curlProxyAuthorizationHeaderValues($conf) !== [];
    }

    /**
     * Collects the first-class Proxy-Authorization request header lines that
     * Guzzle configures in cURL's proxy-only header channel. Empty values use
     * cURL's semicolon form so they suppress an automatically generated proxy
     * authorization field without carrying a credential. getHeaderLine() is
     * deliberately not used: comma-joining multiple credentials would change
     * their wire representation and connection signature.
     *
     * @return list<string>
     */
    private static function managedProxyAuthorizationHeaderLines(
        #[\SensitiveParameter]
        RequestInterface $request
    ): array {
        $headers = [];

        foreach ($request->getHeader('Proxy-Authorization') as $value) {
            $headers[] = $value === ''
                ? 'Proxy-Authorization;'
                : 'Proxy-Authorization: '.$value;
        }

        return $headers;
    }

    /**
     * Routes the managed Proxy-Authorization lines and any caller-provided
     * proxy headers through cURL's proxy-only header channel without
     * consulting Guzzle's route prediction, so no proxy-classification
     * discrepancy can disclose them to an origin server. libcurl uses the
     * proxy-only list only for HTTP requests it actually sends to a proxy, so
     * direct, no-proxy-bypassed, and SOCKS transfers never deliver it.
     *
     * @param array<int|string, mixed> $conf
     * @param list<string>             $managedHeaders
     */
    private static function applyProxyAuthorizationHeaderHandling(
        #[\SensitiveParameter]
        RequestInterface $request,
        #[\SensitiveParameter]
        array &$conf,
        #[\SensitiveParameter]
        array $managedHeaders
    ): void {
        if ($managedHeaders !== [] || self::hasCurlProxyHeaderOption($conf)) {
            if (!CurlVersion::supportsProxyHeaderSeparation()) {
                throw new RequestException('Proxy headers require libcurl 7.37.0 or newer built with proxy header separation support.', $request);
            }

            if ($managedHeaders !== []) {
                self::appendCurlProxyHeaders($conf, $managedHeaders);
            }

            $conf[(int) \constant('CURLOPT_HEADEROPT')] = (int) \constant('CURLHEADER_SEPARATE');
        }

        // Keep the conservative CONNECT header-list separation for effective
        // HTTP(S) proxy tunnels without a proxy header: on libcurl
        // 7.37.0-7.42.0 the default is CURLHEADER_UNIFIED. This broad route
        // approximation never decides managed credential routing.
        $proxy = self::getEffectiveProxy($conf);
        if (
            CurlVersion::supportsProxyHeaderSeparation()
            && $proxy !== null
            && self::isHttpProxyForConnectionReuse($proxy, $conf)
            && self::usesProxyTunnel($request, $conf)
        ) {
            $conf[(int) \constant('CURLOPT_HEADEROPT')] = (int) \constant('CURLHEADER_SEPARATE');
        }
    }

    /**
     * @param array<int|string, mixed> $conf
     * @param list<string>             $headers
     */
    private static function appendCurlProxyHeaders(
        #[\SensitiveParameter]
        array &$conf,
        #[\SensitiveParameter]
        array $headers
    ): void {
        $option = (int) \constant('CURLOPT_PROXYHEADER');

        if (\array_key_exists($option, $conf)) {
            if (!\is_array($conf[$option])) {
                throw new InvalidArgumentException('CURLOPT_PROXYHEADER must be an array when a Proxy-Authorization request header is routed to the proxy header channel.');
            }

            $headers = \array_merge($conf[$option], $headers);
        }

        $conf[$option] = $headers;
    }

    /**
     * @param array<int|string, mixed> $conf
     */
    private static function hasCurlProxyHeaderOption(array $conf): bool
    {
        return \defined('CURLOPT_PROXYHEADER')
            && \array_key_exists((int) \constant('CURLOPT_PROXYHEADER'), $conf);
    }

    /**
     * @param mixed[] $headers
     *
     * @return list<string>
     */
    private static function proxyAuthorizationHeaderValuesFromList(array $headers): array
    {
        $values = [];

        foreach ($headers as $header) {
            if (!\is_string($header)) {
                continue;
            }

            $position = \strpos($header, ':');
            if ($position === false) {
                continue;
            }

            if (!Psr7\Utils::caselessEquals(\trim(\substr($header, 0, $position), " \n\r\t\0\x0B"), 'Proxy-Authorization')) {
                continue;
            }

            $value = \trim(\substr($header, $position + 1), " \n\r\t\0\x0B");
            if ($value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * Computes the connection-reuse section signature for a proxy tunnel or
     * SOCKS proxy, or null when the request does not require sectioning.
     *
     * @param array<int|string, mixed> $conf
     */
    private static function proxyTunnelSignature(
        #[\SensitiveParameter]
        RequestInterface $request,
        #[\SensitiveParameter]
        array $conf
    ): ?string {
        $proxy = self::getEffectiveProxy($conf);
        if ($proxy === null) {
            return null;
        }

        // SOCKS authentication binds an identity to the connection itself, for
        // plain http:// requests as much as https://, so it sections ahead of
        // the CONNECT tunnel domain checks.
        if (self::isSocksProxy($proxy, $conf)) {
            return self::socksProxySignature($proxy, $conf);
        }

        if (
            !self::usesProxyTunnel($request, $conf)
            || !self::isHttpProxyForConnectionReuse($proxy, $conf)
        ) {
            return null;
        }

        $headerAuth = self::curlProxyAuthorizationHeaderValues($conf);
        if ($headerAuth === [] && CurlVersion::supportsProxyCredentialAwareConnectionReuse()) {
            // libcurl keys reuse on parsed proxy credentials only from 8.19.0,
            // trusted from 8.20.0 (PROXY_CREDENTIAL_REUSE_VERSION); a literal
            // Proxy-Authorization header is never keyed and always sections.
            // See docs/contributing/curl-connection-reuse.md.
            return self::DELEGATED_PROXY_TUNNEL_OWNER;
        }

        // Hash every proxy channel an old libcurl might not key reuse on. A
        // changed signature only forces a fresh connection, never relaxes
        // reuse, so over-covering is always safe; under-covering leaks. Proxy
        // credentials are the channel CVE-2026-3784 missed; the proxy-TLS
        // options are load-bearing on builds before the proxy-TLS reuse fixes
        // (the proxy client cert is keyed from 7.52.0, libcurl's first
        // HTTPS-proxy release; CVE-2016-5420 (7.50.1) is only the origin-cert
        // precedent; TLS-SRP from 7.83.1, CVE-2022-27782) and harmless after.
        // The private-key file and passphrase are hashed on this non-delegated
        // path too, as fallback hardening: libcurl's mTLS private-key matching
        // on reuse was incomplete before 8.21.0 (CVE-2026-8932). This does not
        // cover the delegated path (the early return above) or configured share
        // handles, so it is not a complete pre-8.21.0 mitigation. The key blob
        // and cert/key type encodings (PROXY_SSLKEY_BLOB, PROXY_SSLKEYTYPE,
        // PROXY_SSLCERTTYPE) are not hashed and are an accepted residual. See
        // docs/contributing/curl-connection-reuse.md.
        $credentialState = [];
        foreach ([
            'CURLOPT_PROXYUSERPWD', 'CURLOPT_PROXYUSERNAME', 'CURLOPT_PROXYPASSWORD',
            'CURLOPT_PROXYTYPE',
            'CURLOPT_PROXY_SSLCERT', 'CURLOPT_PROXY_SSLCERT_BLOB', 'CURLOPT_PROXY_SSLKEY',
            'CURLOPT_PROXY_KEYPASSWD', 'CURLOPT_PROXY_TLSAUTH_USERNAME',
            'CURLOPT_PROXY_TLSAUTH_PASSWORD', 'CURLOPT_PROXY_SSLVERSION',
        ] as $name) {
            $credentialState[$name] = \defined($name)
                ? ($conf[(int) \constant($name)] ?? null)
                : null;
        }

        return \hash('sha256', \serialize([$proxy, $credentialState, $headerAuth]));
    }

    /**
     * @param array<int|string, mixed> $conf
     *
     * @return list<string>
     */
    private static function curlProxyAuthorizationHeaderValues(array $conf): array
    {
        if (!\defined('CURLOPT_PROXYHEADER')) {
            return [];
        }

        $option = (int) \constant('CURLOPT_PROXYHEADER');
        if (!\array_key_exists($option, $conf)) {
            return [];
        }

        $headers = $conf[$option];
        if (!\is_array($headers)) {
            return [];
        }

        return self::proxyAuthorizationHeaderValuesFromList($headers);
    }

    private function discardIdleHandles(): void
    {
        foreach ($this->handles as $id => $handle) {
            try {
                // Best effort: a cleanup hiccup on a superseded idle handle
                // must not abort the unrelated request that triggered the purge.
                $this->discardHandle($handle);
            } catch (\Throwable $e) {
                // Ignored.
            } finally {
                unset($this->handles[$id]);
            }
        }
    }

    /**
     * @return array<int|string, mixed>
     */
    private function getDefaultConf(
        #[\SensitiveParameter]
        EasyHandle $easy
    ): array {
        $uri = $easy->request->getUri();
        $protocols = Utils::normalizeProtocols($easy->options['protocols'] ?? ['http', 'https']);
        $scheme = $uri->getScheme();
        if (!\in_array($scheme, $protocols, true)) {
            throw new RequestException(\sprintf('The scheme "%s" is not allowed by the protocols request option.', Psr7\DiagnosticValue::escape($scheme)), $easy->request);
        }

        if ($uri->getHost() === '') {
            throw new RequestException('URI must include a scheme and host. Use an absolute URI, a network-path reference starting with //, or configure a base_uri.', $easy->request);
        }

        $conf = [
            '_headers' => $easy->request->getHeaders(),
            \CURLOPT_CUSTOMREQUEST => $easy->request->getMethod(),
            \CURLOPT_URL => (string) $uri->withFragment(''),
            \CURLOPT_RETURNTRANSFER => false,
            \CURLOPT_HEADER => false,
            \CURLOPT_CONNECTTIMEOUT_MS => self::DEFAULT_CONNECT_TIMEOUT_MS,
        ];

        if (CurlVersion::supportsProtocolsStr()) {
            $conf[(int) \constant('CURLOPT_PROTOCOLS_STR')] = \implode(',', $protocols);
        } else {
            $conf[\CURLOPT_PROTOCOLS] = self::curlProtocolMask($protocols);
        }

        $version = $easy->request->getProtocolVersion();
        $multiplex = self::normalizeMultiplex($easy->options);

        if ('3' === $version || '3.0' === $version) {
            if (!\defined('CURL_HTTP_VERSION_3')) {
                throw new RequestException('HTTP/3 is not supported by this cURL installation.', $easy->request);
            }

            $proxy = ProxyEnv::resolveProxySelection($easy->request->getUri(), $easy->options['proxy'] ?? null);

            if (\in_array($multiplex, [Multiplexing::REQUIRE_EAGER, Multiplexing::REQUIRE_WAIT], true)) {
                self::assertSelectedProxySupported($proxy->getProxy(), $easy->request);

                if ($proxy->hasProxy()) {
                    throw new RequestException('Required multiplexing cannot be guaranteed for HTTP/3 requests sent through a proxy.', $easy->request);
                }
                if (!CurlVersion::supportsRequiredHttp3Multiplex()) {
                    throw new RequestException('Required multiplexing for HTTP/3 needs libcurl 8.13.0 or newer built with HTTP/3 support.', $easy->request);
                }
                // HTTP/3 or fail: required multiplexing never downgrades, not
                // even to HTTP/2.
                $conf[\CURLOPT_HTTP_VERSION] = (int) \constant('CURL_HTTP_VERSION_3ONLY');
            } else {
                $conf[\CURLOPT_HTTP_VERSION] = $proxy->hasProxy()
                    ? (CurlVersion::supportsHttp2() ? \CURL_HTTP_VERSION_2_0 : \CURL_HTTP_VERSION_1_1)
                    : (int) \constant('CURL_HTTP_VERSION_3');
            }
        } elseif ('2' === $version || '2.0' === $version) {
            if (\in_array($multiplex, [Multiplexing::REQUIRE_EAGER, Multiplexing::REQUIRE_WAIT], true)) {
                self::assertRequiredMultiplexSupported($easy);
                // New HTTP/2 connections cannot negotiate HTTP/1.x here, and
                // the 8.14.0 floor's version-aware reuse matching keeps
                // reused connections on HTTP/2 as well.
                $conf[\CURLOPT_HTTP_VERSION] = (int) \constant('CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE');
            } else {
                $conf[\CURLOPT_HTTP_VERSION] = \CURL_HTTP_VERSION_2_0;
            }
        } elseif ('1.1' === $version) {
            if (\in_array($multiplex, [Multiplexing::REQUIRE_EAGER, Multiplexing::REQUIRE_WAIT], true)) {
                throw new RequestException(\sprintf('The "multiplex" request option cannot be required for HTTP/%s requests; use protocol version 2 or 3.', $version), $easy->request);
            }
            $conf[\CURLOPT_HTTP_VERSION] = \CURL_HTTP_VERSION_1_1;
        } else {
            if (\in_array($multiplex, [Multiplexing::REQUIRE_EAGER, Multiplexing::REQUIRE_WAIT], true)) {
                throw new RequestException(\sprintf('The "multiplex" request option cannot be required for HTTP/%s requests; use protocol version 2 or 3.', $version), $easy->request);
            }
            $conf[\CURLOPT_HTTP_VERSION] = \CURL_HTTP_VERSION_1_0;
        }

        $resolvedVersion = $conf[\CURLOPT_HTTP_VERSION];
        if (\in_array($multiplex, [Multiplexing::WAIT, Multiplexing::REQUIRE_WAIT], true)
            && CurlVersion::supportsMultiplex()
            && ($resolvedVersion === \CURL_HTTP_VERSION_2_0
                || (\defined('CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE') && $resolvedVersion === (int) \constant('CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE'))
                || (\defined('CURL_HTTP_VERSION_3') && $resolvedVersion === (int) \constant('CURL_HTTP_VERSION_3'))
                || (\defined('CURL_HTTP_VERSION_3ONLY') && $resolvedVersion === (int) \constant('CURL_HTTP_VERSION_3ONLY')))
        ) {
            // Wait for an in-progress connection to the same origin to reveal
            // whether it can be multiplexed instead of opening another one.
            $conf[(int) \constant('CURLOPT_PIPEWAIT')] = true;
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

        return Psr7\Utils::asciiToUpper($type);
    }

    private static function shouldValidateSslKeyFile(?string $type): bool
    {
        return $type !== 'ENG' && $type !== 'PROV';
    }

    private function applyMethod(
        #[\SensitiveParameter]
        EasyHandle $easy,
        #[\SensitiveParameter]
        array &$conf,
        RequestFraming $framing
    ): void {
        if ($easy->request->getMethod() === 'HEAD') {
            // libcurl stops at HEAD response headers only when CURLOPT_NOBODY
            // is set; CURLOPT_CUSTOMREQUEST changes only the method string.
            // NOBODY also suppresses request upload, so strip non-zero body
            // length, transfer coding, and a 100-continue expectation.
            $conf[\CURLOPT_CUSTOMREQUEST] = null;
            $conf[\CURLOPT_NOBODY] = true;
            unset(
                $conf[\CURLOPT_WRITEFUNCTION],
                $conf[\CURLOPT_READFUNCTION],
                $conf[\CURLOPT_FILE],
                $conf[\CURLOPT_INFILE]
            );
            if (\trim($easy->request->getHeaderLine('Content-Length'), " \n\r\t\0\x0B") !== '0') {
                $this->removeHeader('Content-Length', $conf);
            }
            $this->removeHeader('Transfer-Encoding', $conf);
            if (Psr7\Utils::caselessEquals(\trim($easy->request->getHeaderLine('Expect'), " \n\r\t\0\x0B"), '100-continue')) {
                $this->removeHeader('Expect', $conf);
            }

            return;
        }

        if ($framing->bodySize !== 0 && $framing->contentLength !== 0) {
            $this->applyBody($easy, $conf, $framing);
        }
    }

    private function applyBody(
        #[\SensitiveParameter]
        EasyHandle $easy,
        #[\SensitiveParameter]
        array &$conf,
        RequestFraming $framing
    ): void {
        $request = $easy->request;
        $options = $easy->options;
        $contentLength = $framing->contentLength;

        // Send the body as a string if the size is less than 1MB OR if the
        // [curl][body_as_string] request value is set.
        if (($framing->bodySize !== null && $framing->bodySize < 1000000) || !empty($options['_body_as_string'])) {
            $conf[\CURLOPT_POSTFIELDS] = $framing->materialize();
            // Don't duplicate the Content-Length header
            $this->removeHeader('Content-Length', $conf);
            $this->removeHeader('Transfer-Encoding', $conf);
        } else {
            $conf[\CURLOPT_UPLOAD] = true;

            if ($contentLength !== null) {
                // Never let cURL emit our header; it sizes the upload via a cURL input-size option.
                $this->removeHeader('Content-Length', $conf);
                $conf[self::curlInputSizeOption($request, $contentLength)] = $contentLength;
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
            $remaining = $contentLength;
            $conf[\CURLOPT_READFUNCTION] = static function ($ch, $fd, int $length) use ($easy, $body, &$remaining) {
                if ($remaining === 0) {
                    return '';
                }

                $limit = $remaining === null ? $length : \min($length, $remaining);

                try {
                    $data = $body->read($limit);
                } catch (TimeoutException $e) {
                    $easy->bodyReadTimeoutException = $e;

                    return self::CURL_READFUNC_ABORT;
                } catch (\Throwable $e) {
                    $easy->bodyReadException = $e;

                    return self::CURL_READFUNC_ABORT;
                }

                $dataLength = \strlen($data);
                if ($dataLength > $limit) {
                    $easy->bodyReadException = new \RuntimeException('Request body stream returned more bytes than requested');

                    return self::CURL_READFUNC_ABORT;
                }

                if ($remaining !== null) {
                    if ($data === '') {
                        $easy->bodyReadException = new \RuntimeException('Request body ended before the declared Content-Length was reached');

                        return self::CURL_READFUNC_ABORT;
                    }

                    $remaining -= $dataLength;
                }

                return $data;
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

    private static function curlInputSizeOption(
        #[\SensitiveParameter]
        RequestInterface $request,
        int $contentLength
    ): int {
        $option = \defined('CURLOPT_INFILESIZE_LARGE')
            ? (int) \constant('CURLOPT_INFILESIZE_LARGE')
            : \CURLOPT_INFILESIZE;

        // cURL's long remains 32-bit on 64-bit Windows.
        if (\PHP_OS_FAMILY === 'Windows' && $option === \CURLOPT_INFILESIZE && $contentLength > 2147483647) {
            throw new RequestException('Content-Length exceeds the maximum cURL upload size supported by this PHP build', $request);
        }

        return $option;
    }

    private function applyHeaders(
        #[\SensitiveParameter]
        EasyHandle $easy,
        #[\SensitiveParameter]
        array &$conf
    ): void {
        foreach ($conf['_headers'] as $name => $values) {
            // The managed Proxy-Authorization field never enters the origin
            // header list; applyProxyAuthorizationHeaderHandling() routes the
            // values through cURL's proxy-only header channel.
            if (Psr7\Utils::caselessEquals((string) $name, 'Proxy-Authorization')) {
                continue;
            }

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
            if (Psr7\Utils::caselessEquals((string) $key, $name)) {
                unset($options['_headers'][$key]);

                return;
            }
        }
    }

    /**
     * Decorates a caller-owned sink stream so that closing the response body
     * detaches Guzzle's wrapper without closing the original PHP resource.
     */
    private static function streamForResourceSink(StreamInterface $stream): StreamInterface
    {
        return FnStream::decorate($stream, [
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

    private function applyHandlerOptions(
        #[\SensitiveParameter]
        EasyHandle $easy,
        #[\SensitiveParameter]
        array &$conf
    ): void {
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
                        throw new InvalidArgumentException(\sprintf('SSL CA bundle not found: %s', Psr7\DiagnosticValue::escape($options['verify'])));
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

        if (!isset($options['curl'][\CURLOPT_ENCODING]) && isset($options['decode_content']) && $options['decode_content'] !== false) {
            $accept = $easy->request->getHeaderLine('Accept-Encoding');
            if ($accept !== '') {
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

        $streamFactory = self::requireStreamFactory($options[RequestOptions::STREAM_FACTORY] ?? new HttpFactory());
        $hasSink = isset($options['sink']);
        if (!$hasSink) {
            // Use a default temp stream if no sink was set.
            $options['sink'] = Psr7\Utils::tryFopen('php://temp', 'w+');
        }
        $sink = $options['sink'];
        if ($hasSink && \is_resource($sink)) {
            $sink = self::streamForResourceSink(Psr7\Utils::streamFor($sink));
        } elseif (\is_resource($sink)) {
            $sink = $streamFactory->createStreamFromResource($sink);
        } elseif (!\is_string($sink)) {
            $sink = Psr7\Utils::streamFor($sink);
        } elseif (!\is_dir(\dirname($sink))) {
            // Ensure that the directory exists before failing in curl.
            throw new RequestException(\sprintf('Directory %s does not exist for sink value of %s', Psr7\DiagnosticValue::escape(\dirname($sink)), Psr7\DiagnosticValue::escape($sink)), $easy->request);
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
            $timeout = Timeout::toMilliseconds($options['timeout'], 'timeout');
            $timeoutRequiresNoSignal |= $timeout > 0 && $timeout < 1000;
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
            $connectTimeout = Timeout::toMilliseconds($options['connect_timeout'], 'connect_timeout');
            if ($connectTimeout > 0) {
                $timeoutRequiresNoSignal |= $connectTimeout < 1000;
                $conf[\CURLOPT_CONNECTTIMEOUT_MS] = $connectTimeout;
            } else {
                $conf[\CURLOPT_CONNECTTIMEOUT_MS] = self::CONNECT_TIMEOUT_DISABLED_MS;
            }
        }

        if ($timeoutRequiresNoSignal && \PHP_OS_FAMILY !== 'Windows') {
            $conf[\CURLOPT_NOSIGNAL] = true;
        }

        // Always pin CURLOPT_PROXY and CURLOPT_NOPROXY so that libcurl never
        // falls back to reading proxy environment variables itself.
        $proxy = ProxyEnv::resolveProxySelection($easy->request->getUri(), $options['proxy'] ?? null);
        $selectedProxy = $proxy->getProxy();
        if ($selectedProxy !== null) {
            // Validate the whole proxy URL up front (ProxyOptions leans on
            // Psr7\Uri), so a malformed proxy fails the same way on every
            // handler, then check the scheme against what libcurl can use.
            self::assertSelectedProxySupported($selectedProxy, $easy->request);

            $conf[\CURLOPT_PROXY] = $selectedProxy;
            $conf[\CURLOPT_NOPROXY] = '';
        } else {
            $conf[\CURLOPT_PROXY] = '';
            $conf[\CURLOPT_NOPROXY] = $proxy->isBypassed() ? '*' : '';
        }

        $this->applyTlsVersionRange($easy, $conf);

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
                throw new InvalidArgumentException(\sprintf('SSL certificate not found: %s', Psr7\DiagnosticValue::escape($cert)));
            }
            // OpenSSL (versions 0.9.3 and later) also support "P12" for PKCS#12-encoded files.
            // see https://curl.se/libcurl/c/CURLOPT_SSLCERTTYPE.html
            $ext = pathinfo($cert, \PATHINFO_EXTENSION);
            if ($certType === null && preg_match('#^(der|p12)$#iD', $ext)) {
                $conf[\CURLOPT_SSLCERTTYPE] = Psr7\Utils::asciiToUpper($ext);
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
                throw new InvalidArgumentException(\sprintf('SSL private key not found: %s', Psr7\DiagnosticValue::escape($sslKey)));
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

    private function applyTlsVersionRange(
        #[\SensitiveParameter]
        EasyHandle $easy,
        #[\SensitiveParameter]
        array &$conf
    ): void {
        $options = $easy->options;
        $cryptoMethod = $options['crypto_method'] ?? null;
        $cryptoMethodMax = $options['crypto_method_max'] ?? null;

        if ($cryptoMethod === null && 'https' === $easy->request->getUri()->getScheme()) {
            $cryptoMethod = \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        }

        if ($cryptoMethod === null && $cryptoMethodMax === null) {
            return;
        }

        $protocolVersion = $easy->request->getProtocolVersion();
        $isHttp3 = '3' === $protocolVersion || '3.0' === $protocolVersion;
        $isHttp2 = '2' === $protocolVersion || '2.0' === $protocolVersion;

        if (($isHttp2 || $isHttp3) && $cryptoMethodMax !== null && TlsVersion::ordinal('crypto_method_max', $cryptoMethodMax) < 12) {
            throw new InvalidArgumentException(
                'Invalid crypto_method_max request option: HTTP/2 and HTTP/3 require TLS 1.2 or higher'
            );
        }

        if (($isHttp2 || $isHttp3) && $cryptoMethod !== null && TlsVersion::ordinal('crypto_method', $cryptoMethod) < 12) {
            $cryptoMethod = \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        }

        TlsVersion::assertRange($cryptoMethod, $cryptoMethodMax);

        $sslVersion = $cryptoMethod === null
            ? \CURL_SSLVERSION_DEFAULT
            : self::curlMinSslVersion($easy, $cryptoMethod);

        if ($cryptoMethodMax !== null) {
            $sslVersion |= self::curlMaxSslVersion($easy, $cryptoMethodMax);
        }

        $conf[\CURLOPT_SSLVERSION] = $sslVersion;
    }

    private static function curlMinSslVersion(
        #[\SensitiveParameter]
        EasyHandle $easy,
        int $value
    ): int {
        if ($value === \STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT) {
            return \CURL_SSLVERSION_TLSv1_0;
        }

        if ($value === \STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT) {
            return \CURL_SSLVERSION_TLSv1_1;
        }

        if ($value === \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT) {
            return \CURL_SSLVERSION_TLSv1_2;
        }

        if ($value === \STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT) {
            if (!CurlVersion::supportsTls13()) {
                throw new RequestException(
                    'Invalid crypto_method request option: TLS 1.3 not supported by your version of cURL',
                    $easy->request
                );
            }

            return \CURL_SSLVERSION_TLSv1_3;
        }

        throw new InvalidArgumentException('Invalid crypto_method request option: unknown version provided');
    }

    private static function curlMaxSslVersion(
        #[\SensitiveParameter]
        EasyHandle $easy,
        int $value
    ): int {
        if ($value === \STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT) {
            return self::requireCurlMaxSslVersion($easy, 'CURL_SSLVERSION_MAX_TLSv1_0');
        }

        if ($value === \STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT) {
            return self::requireCurlMaxSslVersion($easy, 'CURL_SSLVERSION_MAX_TLSv1_1');
        }

        if ($value === \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT) {
            return self::requireCurlMaxSslVersion($easy, 'CURL_SSLVERSION_MAX_TLSv1_2');
        }

        if ($value === \STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT) {
            return self::requireCurlMaxSslVersion($easy, 'CURL_SSLVERSION_MAX_TLSv1_3');
        }

        throw new InvalidArgumentException('Invalid crypto_method_max request option: unknown version provided');
    }

    private static function requireCurlMaxSslVersion(
        #[\SensitiveParameter]
        EasyHandle $easy,
        string $constant
    ): int {
        if (\defined($constant)) {
            /** @var int */
            return \constant($constant);
        }

        throw new RequestException(
            'Invalid crypto_method_max request option: maximum TLS version control is not supported by your version of cURL',
            $easy->request
        );
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
    private static function retryFailedRewind(
        callable $handler,
        #[\SensitiveParameter]
        EasyHandle $easy,
        #[\SensitiveParameter]
        array $ctx
    ): PromiseInterface {
        try {
            // Only rewind if the body has been read from.
            $body = $easy->request->getBody();
            if ($body->tell() > 0) {
                $body->rewind();
            }
        } catch (\Exception $e) {
            $ctx['error'] = 'The connection unexpectedly failed without providing an error. The request would have been retried, but attempting to rewind the request body failed.';

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

    /**
     * Parses validated trailer field lines into an associative array keyed by
     * lowercased field name, preserving first-occurrence key order and wire
     * value order.
     *
     * @param list<string> $lines
     *
     * @return array<string, list<string>>
     */
    private static function headersFromTrailerLines(array $lines): array
    {
        $headers = [];

        foreach ($lines as $line) {
            [$name, $value] = \explode(':', $line, 2);
            $name = Psr7\Utils::asciiToLower(\trim($name, " \n\r\t\0\x0B"));
            $headers[$name][] = \trim($value, " \n\r\t\0\x0B");
        }

        return $headers;
    }

    private function createHeaderFn(
        #[\SensitiveParameter]
        EasyHandle $easy
    ): callable {
        if (isset($easy->options['on_headers'])) {
            $onHeaders = $easy->options['on_headers'];

            if (!\is_callable($onHeaders)) {
                throw new InvalidArgumentException('on_headers must be callable');
            }
        } else {
            $onHeaders = null;
        }

        $startingResponse = false;
        $collectingTrailers = false;
        $retainTrailers = isset($easy->options['on_trailers']);

        return static function ($ch, string $h) use (
            $onHeaders,
            $easy,
            &$startingResponse,
            &$collectingTrailers,
            $retainTrailers
        ): int {
            $value = \trim($h, " \n\r\t\0\x0B");
            if ($h === "\r\n" || $h === "\n" || $h === "\r" || $h === '') {
                if ($collectingTrailers) {
                    // A blank line ends the trailer section; the response has
                    // already been created.
                    return \strlen($h);
                }

                $startingResponse = true;

                try {
                    $easy->createResponse();
                } catch (\Throwable $e) {
                    $easy->response = null;
                    $easy->createResponseException = $e;

                    return -1;
                }

                if ($easy->responseHeaderException !== null) {
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
            } elseif ($startingResponse || $collectingTrailers) {
                if ($easy->response !== null && !HeaderProcessor::isStatusLineCandidate($h)) {
                    // Trailer fields arrive through the header callback after
                    // the body; a new header block always begins with a status
                    // line.
                    $collectingTrailers = true;

                    if ($retainTrailers && HeaderProcessor::isValidHeaderFieldLine($h)) {
                        $easy->trailers[] = $value;
                    }
                } else {
                    $collectingTrailers = false;
                    $easy->trailers = [];
                    $easy->headers = [$value];
                }

                $startingResponse = false;
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

    public function __unserialize(array $data): void
    {
        $this->closed = true;
        $this->handles = [];
        $this->shareHandle = null;

        throw new \LogicException(static::class.' should never be unserialized');
    }
}
