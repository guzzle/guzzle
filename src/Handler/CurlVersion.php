<?php

namespace GuzzleHttp\Handler;

/**
 * @internal
 */
final class CurlVersion
{
    private const MIN_VERSION = '7.21.2';

    private const TLS_12_VERSION = '7.34.0';

    private const TLS_13_VERSION = '7.52.0';

    // curl 7.52.0 introduced HTTPS proxy support, advertised by a feature bit
    // (a build can meet the version yet lack the feature). Earlier libcurl
    // mishandles an https:// proxy: before 7.50.2 it silently downgrades to a
    // plaintext HTTP proxy, and 7.50.2 through 7.51 reject it at connect time.
    private const HTTPS_PROXY_VERSION = '7.52.0';

    private const HANDLER_SHARING_VERSION = '7.35.0';

    private const SSL_SESSION_SHARING_VERSION = '8.6.0';

    /**
     * @var array{version: string, features: int}|false|null
     */
    private static $versionInfo;

    private function __construct()
    {
    }

    public static function supportsCurlHandler(): bool
    {
        $version = self::getVersion();

        return $version !== null && \version_compare($version, self::MIN_VERSION, '>=');
    }

    public static function supportsTls12(): bool
    {
        $version = self::getVersion();

        return self::supportsSsl()
            && \defined('CURL_SSLVERSION_TLSv1_2')
            && $version !== null
            && \version_compare($version, self::TLS_12_VERSION, '>=');
    }

    public static function supportsTls13(): bool
    {
        $version = self::getVersion();

        return self::supportsSsl()
            && \defined('CURL_SSLVERSION_TLSv1_3')
            && $version !== null
            && \version_compare($version, self::TLS_13_VERSION, '>=');
    }

    public static function supportsHttp2(): bool
    {
        $versionInfo = self::getVersionInfo();

        return self::supportsTls12()
            && \defined('CURL_VERSION_HTTP2')
            && $versionInfo !== null
            && 0 !== (\CURL_VERSION_HTTP2 & $versionInfo['features']);
    }

    public static function supportsHttpsProxy(): bool
    {
        $versionInfo = self::getVersionInfo();

        // CURL_VERSION_HTTPS_PROXY is not defined on every supported PHP
        // version; fall back to the curl.h bit value.
        $httpsProxyFeature = \defined('CURL_VERSION_HTTPS_PROXY') ? \CURL_VERSION_HTTPS_PROXY : (1 << 21);

        return $versionInfo !== null
            && \version_compare($versionInfo['version'], self::HTTPS_PROXY_VERSION, '>=')
            && 0 !== ($httpsProxyFeature & $versionInfo['features']);
    }

    public static function supportsHandlerSharing(): bool
    {
        $version = self::getVersion();

        return $version !== null && \version_compare($version, self::HANDLER_SHARING_VERSION, '>=');
    }

    public static function ensureHandlerSharingSupported(): void
    {
        if (!self::supportsHandlerSharing()) {
            throw new \InvalidArgumentException(\sprintf(
                'The "transport_sharing" option requires libcurl %s or higher for cURL share handles.',
                self::HANDLER_SHARING_VERSION
            ));
        }
    }

    public static function supportsSslSessionSharing(): bool
    {
        $version = self::getVersion();

        return self::supportsSsl()
            && $version !== null
            && \version_compare($version, self::SSL_SESSION_SHARING_VERSION, '>=');
    }

    public static function ensureSslSessionSharingSupported(): void
    {
        if (!self::supportsSslSessionSharing()) {
            throw new \InvalidArgumentException(\sprintf(
                'The "transport_sharing" option requires libcurl %s or higher with SSL support for SSL session sharing.',
                self::SSL_SESSION_SHARING_VERSION
            ));
        }
    }

    private static function supportsSsl(): bool
    {
        $versionInfo = self::getVersionInfo();

        return \defined('CURL_VERSION_SSL')
            && $versionInfo !== null
            && 0 !== (\CURL_VERSION_SSL & $versionInfo['features']);
    }

    public static function getVersion(): ?string
    {
        $versionInfo = self::getVersionInfo();

        return $versionInfo === null ? null : $versionInfo['version'];
    }

    /**
     * @return array{version: string, features: int}|null
     */
    private static function getVersionInfo(): ?array
    {
        if (self::$versionInfo === null) {
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

        return self::$versionInfo === false ? null : self::$versionInfo;
    }
}
