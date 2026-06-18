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

    // curl 7.52.0 introduced HTTPS proxy support, advertised by a feature bit
    // (a build can meet the version yet lack the feature). Earlier libcurl
    // mishandles an https:// proxy: before 7.50.2 it silently downgrades to a
    // plaintext HTTP proxy, and 7.50.2 through 7.51 reject it at connect time.
    private const HTTPS_PROXY_VERSION = '7.52.0';

    private const HTTP_3_VERSION = '7.66.0';

    private const PROTOCOLS_STR_VERSION = '7.85.0';

    private const HANDLER_SHARING_VERSION = '7.35.0';

    private const SSL_SESSION_SHARING_VERSION = '8.6.0';

    private const CONNECTION_SHARING_VERSION = '8.20.0';

    // curl 7.83.1 added the proxy TLS-SRP identity to the connection-reuse
    // match (CVE-2022-27782); the proxy client certificate was already matched
    // from the 7.52.0 HTTPS-proxy floor. Below 7.83.1 an HTTPS-proxy tunnel
    // could be reused across TLS-SRP identities, so proxy TLS credential reuse
    // is trusted from 7.83.1 onwards.
    private const PROXY_TLS_CREDENTIAL_REUSE_VERSION = '7.83.1';

    // curl 8.19.0 fixed proxy tunnel reuse after credential changes
    // (CVE-2026-3784), but related proxy credential leak flaws were only
    // fixed in 8.20.0, so connection reuse is trusted from 8.20.0 onwards.
    private const PROXY_CREDENTIAL_REUSE_VERSION = '8.20.0';

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

    public static function supportsHttp2(): bool
    {
        $versionInfo = self::getVersionInfo();

        return \defined('CURL_VERSION_HTTP2')
            && null !== $versionInfo
            && 0 !== (\CURL_VERSION_HTTP2 & $versionInfo['features']);
    }

    public static function supportsHttp3(): bool
    {
        if (!\defined('CURL_VERSION_HTTP3') || !\defined('CURL_HTTP_VERSION_3')) {
            return false;
        }

        $versionInfo = self::getVersionInfo();
        if (null === $versionInfo || version_compare($versionInfo['version'], self::HTTP_3_VERSION, '<')) {
            return false;
        }

        return 0 !== ((int) \constant('CURL_VERSION_HTTP3') & $versionInfo['features']);
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

    public static function supportsProtocolsStr(): bool
    {
        $version = self::get();

        return \defined('CURLOPT_PROTOCOLS_STR')
            && null !== $version
            && version_compare($version, self::PROTOCOLS_STR_VERSION, '>=');
    }

    public static function ensureSupported(RequestInterface $request): void
    {
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
