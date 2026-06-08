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

    private const HTTP_3_VERSION = '7.66.0';

    private const PROTOCOLS_STR_VERSION = '7.85.0';

    private const HANDLER_SHARING_VERSION = '7.35.0';

    private const SSL_SESSION_SHARING_VERSION = '8.6.0';

    private const CONNECTION_SHARING_VERSION = '8.20.0';

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
        $version = self::get();

        return self::supportsSsl()
            && \defined('CURL_SSLVERSION_TLSv1_2')
            && null !== $version
            && version_compare($version, self::MIN_VERSION, '>=');
    }

    public static function supportsTls13(): bool
    {
        $version = self::get();

        return self::supportsSsl()
            && \defined('CURL_SSLVERSION_TLSv1_3')
            && null !== $version
            && version_compare($version, self::TLS_13_VERSION, '>=');
    }

    public static function supportsHttp2(): bool
    {
        if (!\defined('CURL_VERSION_HTTP2')) {
            return false;
        }

        return self::supportsCurlHandler()
            && 0 !== (\CURL_VERSION_HTTP2 & self::getInfo()['features']);
    }

    public static function supportsHttp3(): bool
    {
        if (!\defined('CURL_VERSION_HTTP3') || !\defined('CURL_HTTP_VERSION_3')) {
            return false;
        }

        $version = self::get();
        if (null === $version || version_compare($version, self::HTTP_3_VERSION, '<')) {
            return false;
        }

        return self::supportsSsl()
            && 0 !== ((int) \constant('CURL_VERSION_HTTP3') & self::getInfo()['features']);
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
        $version = self::get();

        return self::supportsSsl()
            && null !== $version
            && version_compare($version, self::SSL_SESSION_SHARING_VERSION, '>=');
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

        if (!self::supportsSsl()) {
            throw new ConnectException('The cURL handler requires libcurl SSL support.', $request);
        }

        throw new ConnectException('The installed cURL version is not supported by the cURL handler.', $request);
    }

    private static function supportsSsl(): bool
    {
        $versionInfo = self::getVersionInfo();

        return \defined('CURL_VERSION_SSL')
            && null !== $versionInfo
            && 0 !== (\CURL_VERSION_SSL & $versionInfo['features']);
    }

    private static function get(): ?string
    {
        $versionInfo = self::getVersionInfo();

        return null === $versionInfo ? null : $versionInfo['version'];
    }

    /**
     * @return array{version: string, features: int}
     */
    private static function getInfo(): array
    {
        $versionInfo = self::getVersionInfo();

        if (null === $versionInfo) {
            throw new \RuntimeException('Unable to determine cURL version.');
        }

        return $versionInfo;
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
