<?php

declare(strict_types=1);

namespace GuzzleHttp\Handler;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\InvalidArgumentException;
use Psr\Http\Message\RequestInterface;

/**
 * @internal
 */
final class CurlVersion
{
    private const MIN_VERSION = '7.34.0';

    private const TLS_13_VERSION = '7.52.0';

    // CURLOPT_PIPEWAIT exists since libcurl 7.43.0, and multi handles have
    // multiplexed by default since 7.62.0 - but a 7.65.0-7.65.1 regression
    // dropped that default, which 7.65.2 restored, so 7.65.2 is the floor at
    // which PIPEWAIT is reliably effective.
    private const MULTIPLEX_VERSION = '7.65.2';

    // libcurl's connection matcher refuses to hand a transfer wanting
    // HTTP/1.x a pooled connection that already negotiated HTTP/2 or newer
    // from 7.77.0: ConnectionExists() in lib/url.c gained the check between
    // the 7.76.0 and 7.77.0 releases. The HTTP/2 branch of the check
    // regressed to a debug log in 8.11.0 (curl commit 433d730) and was
    // restored in 8.13.0 via the negotiation mask (curl commit db72b8d), so
    // 8.11.0 through 8.12.1 are vulnerable again.
    private const HTTP_VERSION_REUSE_MATCH_VERSION = '7.77.0';

    private const HTTP_VERSION_REUSE_MATCH_REGRESSION = '8.11.0';

    private const HTTP_VERSION_REUSE_MATCH_RESTORED = '8.13.0';

    // CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE restricts the ALPN offer to h2 only
    // since libcurl 8.10.0, and connection reuse matching stopped handing
    // lower-version connections to prior-knowledge transfers in 8.14.0; below
    // that, a required HTTP/2 request could silently be sent over a reused
    // HTTP/1.1 connection.
    private const REQUIRED_HTTP2_MULTIPLEX_VERSION = '8.14.0';

    // Version-aware connection reuse matching arrived in libcurl 8.13.0 with
    // an HTTP/3-only mask for CURL_HTTP_VERSION_3ONLY transfers; below that,
    // a required HTTP/3 request could silently ride a reused HTTP/2
    // connection.
    private const REQUIRED_HTTP3_MULTIPLEX_VERSION = '8.13.0';

    // curl 7.52.0 introduced HTTPS proxy support, advertised by a feature bit
    // (a build can meet the version yet lack the feature). Earlier libcurl
    // mishandles an https:// proxy: before 7.50.2 it silently downgrades to a
    // plaintext HTTP proxy, and 7.50.2 through 7.51 reject it at connect time.
    // The 7.52.0 TLS rework also shipped verification flaws in exactly this
    // path (CVE-2017-2629, CVE-2017-7468); the latter affects 7.52.0-7.53.1
    // and was fixed in 7.54.0, so TLS to a proxy is only trusted from there.
    private const HTTPS_PROXY_VERSION = '7.54.0';

    // HTTP/3 arrived in libcurl 7.66.0, but CURL_HTTP_VERSION_3ONLY only
    // exists from 7.88.0; requiring it keeps every HTTP/3-capable runtime
    // able to pin HTTP/3 with no downgrade.
    private const HTTP_3_VERSION = '7.88.0';

    private const PROTOCOLS_STR_VERSION = '7.85.0';

    private const HANDLER_SHARING_VERSION = '7.35.0';

    private const SSL_SESSION_SHARING_VERSION = '8.6.0';

    private const CONNECTION_SHARING_VERSION = '8.12.0';

    // curl 7.57.0 added share-handle connection caches through
    // CURL_LOCK_DATA_CONNECT; older share objects can only hold DNS, TLS
    // session, and cookie data, never connections. This is libcurl's raw
    // capability floor for the opaque share safeguards; Guzzle-managed
    // connection sharing is separately gated by CONNECTION_SHARING_VERSION.
    private const SHARE_CONNECTION_CACHE_VERSION = '7.57.0';

    // curl 7.83.1 added proxy TLS-SRP to the connection-reuse match
    // (CVE-2022-27782); the proxy client certificate was matched from 7.52.0,
    // so proxy TLS credentials are trusted from 7.83.1 onwards.
    private const PROXY_TLS_CREDENTIAL_REUSE_VERSION = '7.83.1';

    // curl 8.19.0 fixed proxy tunnel reuse after credential changes
    // (CVE-2026-3784), but related proxy credential leak flaws were only
    // fixed in 8.20.0, so connection reuse is trusted from 8.20.0 onwards.
    private const PROXY_CREDENTIAL_REUSE_VERSION = '8.20.0';

    // curl 7.69.0 started comparing SOCKS proxy credentials when matching
    // connections for reuse (curl #4835); older libcurl matches a SOCKS proxy
    // by type, host, and port only.
    private const SOCKS_PROXY_CREDENTIAL_REUSE_VERSION = '7.69.0';

    private const PROXY_HEADER_SEPARATION_VERSION = '7.37.0';

    // CURLOPT_SUPPRESS_CONNECT_HEADERS arrived in curl 7.54.0; proxy CONNECT
    // tunneling is gated on it so the proxy's interim reply can never surface
    // as a phantom response on any build that can tunnel.
    private const PROXY_TUNNEL_VERSION = '7.54.0';

    /**
     * @var array{version: string, features: int}|false|null
     */
    private static $versionInfo;

    private function __construct()
    {
    }

    public static function supportsCurlHandler(): bool
    {
        $versionInfo = self::getVersionInfo();

        return \defined('CURL_VERSION_SSL')
            && \defined('CURL_SSLVERSION_TLSv1_2')
            && \defined('CURLMOPT_MAX_HOST_CONNECTIONS')
            && \defined('CURLMOPT_MAX_TOTAL_CONNECTIONS')
            && null !== $versionInfo
            && version_compare($versionInfo['version'], self::MIN_VERSION, '>=')
            && 0 !== (\CURL_VERSION_SSL & $versionInfo['features']);
    }

    public static function supportsTls13(): bool
    {
        $version = self::get();

        return \defined('CURL_SSLVERSION_TLSv1_3')
            && null !== $version
            && version_compare($version, self::TLS_13_VERSION, '>=');
    }

    public static function supportsMultiplex(): bool
    {
        $version = self::get();

        return \defined('CURLOPT_PIPEWAIT')
            && null !== $version
            && version_compare($version, self::MULTIPLEX_VERSION, '>=');
    }

    public static function supportsHttpVersionReuseMatching(): bool
    {
        $version = self::get();

        if (null === $version || version_compare($version, self::HTTP_VERSION_REUSE_MATCH_VERSION, '<')) {
            return false;
        }

        return version_compare($version, self::HTTP_VERSION_REUSE_MATCH_REGRESSION, '<')
            || version_compare($version, self::HTTP_VERSION_REUSE_MATCH_RESTORED, '>=');
    }

    public static function supportsRequiredHttp2Multiplex(): bool
    {
        $version = self::get();

        return \defined('CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE')
            && null !== $version
            && self::supportsHttp2()
            && version_compare($version, self::REQUIRED_HTTP2_MULTIPLEX_VERSION, '>=');
    }

    public static function supportsHttp2(): bool
    {
        $versionInfo = self::getVersionInfo();

        // Requiring dependable CURLOPT_PIPEWAIT support keeps waiting from
        // ever being silently unavailable where HTTP/2 works.
        return \defined('CURL_VERSION_HTTP2')
            && null !== $versionInfo
            && self::supportsMultiplex()
            && 0 !== (\CURL_VERSION_HTTP2 & $versionInfo['features']);
    }

    public static function supportsHttp3(): bool
    {
        if (!\defined('CURL_VERSION_HTTP3') || !\defined('CURL_HTTP_VERSION_3') || !\defined('CURL_HTTP_VERSION_3ONLY')) {
            return false;
        }

        $versionInfo = self::getVersionInfo();
        if (null === $versionInfo || version_compare($versionInfo['version'], self::HTTP_3_VERSION, '<')) {
            return false;
        }

        return 0 !== ((int) \constant('CURL_VERSION_HTTP3') & $versionInfo['features']);
    }

    public static function supportsRequiredHttp3Multiplex(): bool
    {
        $version = self::get();

        return self::supportsHttp3()
            && null !== $version
            && version_compare($version, self::REQUIRED_HTTP3_MULTIPLEX_VERSION, '>=');
    }

    public static function supportsHttpsProxy(): bool
    {
        $versionInfo = self::getVersionInfo();

        return \defined('CURL_VERSION_HTTPS_PROXY')
            && null !== $versionInfo
            && version_compare($versionInfo['version'], self::HTTPS_PROXY_VERSION, '>=')
            && 0 !== (\CURL_VERSION_HTTPS_PROXY & $versionInfo['features']);
    }

    public static function supportsHandlerSharing(): bool
    {
        $version = self::get();

        return null !== $version
            && version_compare($version, self::HANDLER_SHARING_VERSION, '>=');
    }

    public static function ensureHandlerSharingSupported(): void
    {
        if (!self::supportsHandlerSharing()) {
            throw new InvalidArgumentException(\sprintf(
                'The "transport_sharing" option requires libcurl %s or higher for cURL share handles.',
                self::HANDLER_SHARING_VERSION
            ));
        }
    }

    public static function supportsSslSessionSharing(): bool
    {
        $versionInfo = self::getVersionInfo();

        return \defined('CURL_VERSION_SSL')
            && null !== $versionInfo
            && version_compare($versionInfo['version'], self::SSL_SESSION_SHARING_VERSION, '>=')
            && 0 !== (\CURL_VERSION_SSL & $versionInfo['features']);
    }

    public static function ensureSslSessionSharingSupported(): void
    {
        if (!self::supportsSslSessionSharing()) {
            throw new InvalidArgumentException(\sprintf(
                'The "transport_sharing" option requires libcurl %s or higher with SSL support for SSL session sharing.',
                self::SSL_SESSION_SHARING_VERSION
            ));
        }
    }

    public static function supportsConnectionSharing(): bool
    {
        $version = self::get();

        return null !== $version
            && version_compare($version, self::CONNECTION_SHARING_VERSION, '>=');
    }

    public static function ensureConnectionSharingSupported(): void
    {
        if (!self::supportsConnectionSharing()) {
            throw new InvalidArgumentException(\sprintf(
                'The "transport_sharing" option requires libcurl %s or higher for persistent connection sharing.',
                self::CONNECTION_SHARING_VERSION
            ));
        }
    }

    public static function supportsShareConnectionCaches(): bool
    {
        $version = self::get();

        // An undetectable libcurl version is treated as capable so the
        // opaque share safeguards fail closed.
        return null === $version
            || version_compare($version, self::SHARE_CONNECTION_CACHE_VERSION, '>=');
    }

    public static function supportsProxyTlsCredentialAwareConnectionReuse(): bool
    {
        $version = self::get();

        return null !== $version
            && version_compare($version, self::PROXY_TLS_CREDENTIAL_REUSE_VERSION, '>=');
    }

    public static function supportsProxyCredentialAwareConnectionReuse(): bool
    {
        $version = self::get();

        return null !== $version
            && version_compare($version, self::PROXY_CREDENTIAL_REUSE_VERSION, '>=');
    }

    public static function supportsSocksProxyCredentialAwareConnectionReuse(): bool
    {
        $version = self::get();

        return null !== $version
            && version_compare($version, self::SOCKS_PROXY_CREDENTIAL_REUSE_VERSION, '>=');
    }

    public static function supportsProxyHeaderSeparation(): bool
    {
        $version = self::get();

        return null !== $version
            && version_compare($version, self::PROXY_HEADER_SEPARATION_VERSION, '>=')
            && \defined('CURLOPT_PROXYHEADER')
            && \defined('CURLOPT_HEADEROPT')
            && \defined('CURLHEADER_SEPARATE');
    }

    public static function supportsProtocolsStr(): bool
    {
        $version = self::get();

        return \defined('CURLOPT_PROTOCOLS_STR')
            && null !== $version
            && version_compare($version, self::PROTOCOLS_STR_VERSION, '>=');
    }

    public static function supportsProxyTunneling(): bool
    {
        $version = self::get();

        return \defined('CURLOPT_SUPPRESS_CONNECT_HEADERS')
            && null !== $version
            && version_compare($version, self::PROXY_TUNNEL_VERSION, '>=');
    }

    public static function ensureSupported(
        #[\SensitiveParameter]
        RequestInterface $request
    ): void {
        if (self::supportsCurlHandler()) {
            return;
        }

        $version = self::get();

        if (null === $version || version_compare($version, self::MIN_VERSION, '<')) {
            throw new ConnectException(\sprintf(
                'cURL %s or higher is required by the cURL handler; %s is installed.',
                self::MIN_VERSION,
                $version ?? 'an unknown version'
            ), $request);
        }

        if (!\defined('CURL_SSLVERSION_TLSv1_2')) {
            throw new ConnectException(\sprintf(
                'The PHP cURL extension must be built against cURL %s or higher to use the cURL handler.',
                self::MIN_VERSION
            ), $request);
        }

        if (!\defined('CURLMOPT_MAX_HOST_CONNECTIONS') || !\defined('CURLMOPT_MAX_TOTAL_CONNECTIONS')) {
            throw new ConnectException('The PHP cURL extension must expose CURLMOPT_MAX_HOST_CONNECTIONS and CURLMOPT_MAX_TOTAL_CONNECTIONS to use the cURL handler.', $request);
        }

        throw new ConnectException('The cURL handler requires libcurl SSL support.', $request);
    }

    private static function get(): ?string
    {
        $versionInfo = self::getVersionInfo();

        return null === $versionInfo ? null : $versionInfo['version'];
    }

    /**
     * @return array{version: string, features: int}|null
     */
    private static function getVersionInfo(): ?array
    {
        if (null === self::$versionInfo) {
            if (!\function_exists('curl_version')) {
                self::$versionInfo = false;
            } else {
                $versionInfo = \curl_version();
                self::$versionInfo = \is_array($versionInfo)
                    && isset($versionInfo['version'], $versionInfo['features'])
                    && \is_string($versionInfo['version'])
                    && \is_int($versionInfo['features'])
                        ? [
                            'version' => $versionInfo['version'],
                            'features' => $versionInfo['features'],
                        ]
                        : false;
            }
        }

        return false === self::$versionInfo ? null : self::$versionInfo;
    }
}
