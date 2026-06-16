<?php

namespace GuzzleHttp\Tests\Handler;

use GuzzleHttp\Handler\CurlVersion;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GuzzleHttp\Handler\CurlVersion
 */
class CurlVersionTest extends TestCase
{
    public function testSupportsCurlHandlerUsesMinimumVersion(): void
    {
        $previous = self::setCurlVersionInfo(['version' => '7.21.1', 'features' => 0]);

        try {
            self::assertFalse(CurlVersion::supportsCurlHandler());

            self::setCurlVersionInfo(['version' => '7.21.2', 'features' => 0]);
            self::assertTrue(CurlVersion::supportsCurlHandler());
        } finally {
            self::setCurlVersionInfo($previous);
        }
    }

    public function testSupportsTls12UsesMinimumVersion(): void
    {
        if (!\defined('CURL_SSLVERSION_TLSv1_2') || !\defined('CURL_VERSION_SSL')) {
            self::markTestSkipped('TLS 1.2 cURL constants are unavailable.');
        }

        $previous = self::setCurlVersionInfo(['version' => '7.33.0', 'features' => \CURL_VERSION_SSL]);

        try {
            self::assertFalse(CurlVersion::supportsTls12());

            self::setCurlVersionInfo(['version' => '7.34.0', 'features' => 0]);
            self::assertFalse(CurlVersion::supportsTls12());

            self::setCurlVersionInfo(['version' => '7.34.0', 'features' => \CURL_VERSION_SSL]);
            self::assertTrue(CurlVersion::supportsTls12());
        } finally {
            self::setCurlVersionInfo($previous);
        }
    }

    public function testSupportsTls13UsesMinimumVersion(): void
    {
        if (!\defined('CURL_SSLVERSION_TLSv1_3') || !\defined('CURL_VERSION_SSL')) {
            self::markTestSkipped('TLS 1.3 cURL constants are unavailable.');
        }

        $previous = self::setCurlVersionInfo(['version' => '7.51.0', 'features' => \CURL_VERSION_SSL]);

        try {
            self::assertFalse(CurlVersion::supportsTls13());

            self::setCurlVersionInfo(['version' => '7.52.0', 'features' => 0]);
            self::assertFalse(CurlVersion::supportsTls13());

            self::setCurlVersionInfo(['version' => '7.52.0', 'features' => \CURL_VERSION_SSL]);
            self::assertTrue(CurlVersion::supportsTls13());
        } finally {
            self::setCurlVersionInfo($previous);
        }
    }

    public function testSupportsHttp2RequiresTls12AndHttp2Feature(): void
    {
        if (!\defined('CURL_SSLVERSION_TLSv1_2') || !\defined('CURL_VERSION_HTTP2') || !\defined('CURL_VERSION_SSL')) {
            self::markTestSkipped('HTTP/2 cURL constants are unavailable.');
        }

        $previous = self::setCurlVersionInfo([
            'version' => '7.33.0',
            'features' => \CURL_VERSION_HTTP2,
        ]);

        try {
            self::assertFalse(CurlVersion::supportsHttp2());

            self::setCurlVersionInfo(['version' => '7.34.0', 'features' => 0]);
            self::assertFalse(CurlVersion::supportsHttp2());

            self::setCurlVersionInfo([
                'version' => '7.34.0',
                'features' => \CURL_VERSION_HTTP2,
            ]);
            self::assertFalse(CurlVersion::supportsHttp2());

            self::setCurlVersionInfo([
                'version' => '7.34.0',
                'features' => \CURL_VERSION_HTTP2 | \CURL_VERSION_SSL,
            ]);
            self::assertTrue(CurlVersion::supportsHttp2());
        } finally {
            self::setCurlVersionInfo($previous);
        }
    }

    public function testSupportsTransportSharingUsesSharingFloors(): void
    {
        if (!\defined('CURL_VERSION_SSL')) {
            self::markTestSkipped('CURL_VERSION_SSL is unavailable.');
        }

        $previous = self::setCurlVersionInfo(['version' => '7.34.0', 'features' => \CURL_VERSION_SSL]);

        try {
            self::assertFalse(CurlVersion::supportsHandlerSharing());

            self::setCurlVersionInfo(['version' => '7.35.0', 'features' => \CURL_VERSION_SSL]);
            self::assertTrue(CurlVersion::supportsHandlerSharing());
            self::assertFalse(CurlVersion::supportsSslSessionSharing());

            self::setCurlVersionInfo(['version' => '8.6.0', 'features' => 0]);
            self::assertTrue(CurlVersion::supportsHandlerSharing());
            self::assertFalse(CurlVersion::supportsSslSessionSharing());

            self::setCurlVersionInfo(['version' => '8.6.0', 'features' => \CURL_VERSION_SSL]);
            self::assertTrue(CurlVersion::supportsHandlerSharing());
            self::assertTrue(CurlVersion::supportsSslSessionSharing());
        } finally {
            self::setCurlVersionInfo($previous);
        }
    }

    public function testGetVersionReturnsNullWhenVersionIsUnavailable(): void
    {
        $previous = self::setCurlVersionInfo(false);

        try {
            self::assertNull(CurlVersion::getVersion());
        } finally {
            self::setCurlVersionInfo($previous);
        }
    }

    /**
     * @param array{version: string, features: int}|false|null $versionInfo
     *
     * @return array{version: string, features: int}|false|null
     */
    private static function setCurlVersionInfo($versionInfo)
    {
        $property = new \ReflectionProperty(CurlVersion::class, 'versionInfo');
        if (\PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }

        $previousVersionInfo = $property->getValue();
        $property->setValue(null, $versionInfo);

        return $previousVersionInfo;
    }
}
