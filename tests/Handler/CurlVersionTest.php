<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests\Handler;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\CurlVersion;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GuzzleHttp\Handler\CurlVersion
 */
class CurlVersionTest extends TestCase
{
    /**
     * @var array{version: string, features: int}|false|null
     */
    private $versionInfo;

    protected function setUp(): void
    {
        $this->versionInfo = self::getVersionInfo();
    }

    protected function tearDown(): void
    {
        self::setVersionInfo($this->versionInfo);
    }

    public function testSupportsCurlHandlerRequiresTls12Contract(): void
    {
        self::requiresCurlSslConstants();

        self::setVersionInfo([
            'version' => '7.33.0',
            'features' => self::curlSslFeature(),
        ]);
        self::assertFalse(CurlVersion::supportsCurlHandler());

        self::setVersionInfo([
            'version' => '7.34.0',
            'features' => 0,
        ]);
        self::assertFalse(CurlVersion::supportsCurlHandler());

        self::setVersionInfo([
            'version' => '7.34.0',
            'features' => self::curlSslFeature(),
        ]);
        self::assertTrue(CurlVersion::supportsCurlHandler());
    }

    public function testSupportsTls13UsesRuntimeVersion(): void
    {
        if (!\defined('CURL_SSLVERSION_TLSv1_3')) {
            self::markTestSkipped('CURL_SSLVERSION_TLSv1_3 is not available.');
        }

        self::setVersionInfo([
            'version' => '7.51.0',
            'features' => 0,
        ]);
        self::assertFalse(CurlVersion::supportsTls13());

        self::setVersionInfo([
            'version' => '7.52.0',
            'features' => 0,
        ]);
        self::assertTrue(CurlVersion::supportsTls13());
    }

    public function testSupportsHttp2UsesHttp2Feature(): void
    {
        if (!\defined('CURL_VERSION_HTTP2')) {
            self::markTestSkipped('CURL_VERSION_HTTP2 is not available.');
        }

        self::setVersionInfo([
            'version' => '7.34.0',
            'features' => 0,
        ]);
        self::assertFalse(CurlVersion::supportsHttp2());

        self::setVersionInfo([
            'version' => '7.34.0',
            'features' => \CURL_VERSION_HTTP2,
        ]);
        self::assertTrue(CurlVersion::supportsHttp2());
    }

    public function testSupportsHttp3ReturnsFalseWhenVersionInfoIsUnavailable(): void
    {
        self::setVersionInfo(false);

        self::assertFalse(CurlVersion::supportsHttp3());
    }

    public function testSupportsHttp3RequiresHttp3Feature(): void
    {
        self::requiresHttp3Constants();

        self::setVersionInfo([
            'version' => '7.66.0',
            'features' => 0,
        ]);

        self::assertFalse(CurlVersion::supportsHttp3());
    }

    public function testSupportsHttp3RequiresHttp3RuntimeVersion(): void
    {
        self::requiresHttp3Constants();

        self::setVersionInfo([
            'version' => '7.65.0',
            'features' => self::http3Feature(),
        ]);

        self::assertFalse(CurlVersion::supportsHttp3());
    }

    public function testSupportsHttp3WhenVersionAndFeatureAreAvailable(): void
    {
        self::requiresHttp3Constants();

        self::setVersionInfo([
            'version' => '7.66.0',
            'features' => self::http3Feature(),
        ]);

        self::assertTrue(CurlVersion::supportsHttp3());
    }

    public function testSupportsHttpsProxyUsesMinimumVersionAndFeature(): void
    {
        if (!\defined('CURL_VERSION_HTTPS_PROXY')) {
            self::markTestSkipped('CURL_VERSION_HTTPS_PROXY is not available.');
        }

        self::setVersionInfo([
            'version' => '7.51.0',
            'features' => \CURL_VERSION_HTTPS_PROXY,
        ]);
        self::assertFalse(CurlVersion::supportsHttpsProxy());

        self::setVersionInfo([
            'version' => '7.52.0',
            'features' => 0,
        ]);
        self::assertFalse(CurlVersion::supportsHttpsProxy());

        self::setVersionInfo([
            'version' => '7.52.0',
            'features' => \CURL_VERSION_HTTPS_PROXY,
        ]);
        self::assertTrue(CurlVersion::supportsHttpsProxy());

        self::setVersionInfo(false);
        self::assertFalse(CurlVersion::supportsHttpsProxy());
    }

    public function testSupportsTransportSharingUsesSharingFloors(): void
    {
        self::requiresCurlSslFeature();

        self::setVersionInfo([
            'version' => '7.34.0',
            'features' => self::curlSslFeature(),
        ]);
        self::assertFalse(CurlVersion::supportsHandlerSharing());
        self::assertFalse(CurlVersion::supportsSslSessionSharing());
        self::assertFalse(CurlVersion::supportsConnectionSharing());

        self::setVersionInfo([
            'version' => '7.35.0',
            'features' => self::curlSslFeature(),
        ]);
        self::assertTrue(CurlVersion::supportsHandlerSharing());
        self::assertFalse(CurlVersion::supportsSslSessionSharing());
        self::assertFalse(CurlVersion::supportsConnectionSharing());

        self::setVersionInfo([
            'version' => '8.6.0',
            'features' => 0,
        ]);
        self::assertTrue(CurlVersion::supportsHandlerSharing());
        self::assertFalse(CurlVersion::supportsSslSessionSharing());
        self::assertFalse(CurlVersion::supportsConnectionSharing());

        self::setVersionInfo([
            'version' => '8.6.0',
            'features' => self::curlSslFeature(),
        ]);
        self::assertTrue(CurlVersion::supportsHandlerSharing());
        self::assertTrue(CurlVersion::supportsSslSessionSharing());
        self::assertFalse(CurlVersion::supportsConnectionSharing());

        self::setVersionInfo([
            'version' => '8.11.0',
            'features' => self::curlSslFeature(),
        ]);
        self::assertTrue(CurlVersion::supportsHandlerSharing());
        self::assertTrue(CurlVersion::supportsSslSessionSharing());
        self::assertFalse(CurlVersion::supportsConnectionSharing());

        self::setVersionInfo([
            'version' => '8.12.0',
            'features' => self::curlSslFeature(),
        ]);
        self::assertTrue(CurlVersion::supportsHandlerSharing());
        self::assertTrue(CurlVersion::supportsSslSessionSharing());
        self::assertTrue(CurlVersion::supportsConnectionSharing());

        self::setVersionInfo([
            'version' => '8.20.0',
            'features' => self::curlSslFeature(),
        ]);
        self::assertTrue(CurlVersion::supportsHandlerSharing());
        self::assertTrue(CurlVersion::supportsSslSessionSharing());
        self::assertTrue(CurlVersion::supportsConnectionSharing());
    }

    public function testSupportsProxyCredentialAwareConnectionReuseUsesSafeVersion(): void
    {
        self::requiresCurlSslFeature();

        self::setVersionInfo([
            'version' => '8.19.0',
            'features' => self::curlSslFeature(),
        ]);
        self::assertFalse(CurlVersion::supportsProxyCredentialAwareConnectionReuse());

        self::setVersionInfo([
            'version' => '8.20.0',
            'features' => self::curlSslFeature(),
        ]);
        self::assertTrue(CurlVersion::supportsProxyCredentialAwareConnectionReuse());
    }

    public function testSupportsProxyHeaderSeparationIsFalseBelowMinimumVersion(): void
    {
        self::setVersionInfo([
            'version' => '7.36.0',
            'features' => 0,
        ]);

        self::assertFalse(CurlVersion::supportsProxyHeaderSeparation());
    }

    public function testSupportsProxyHeaderSeparationIsTrueAtMinimumVersion(): void
    {
        self::requiresProxyHeaderSeparationConstants();

        self::setVersionInfo([
            'version' => '7.37.0',
            'features' => 0,
        ]);

        self::assertTrue(CurlVersion::supportsProxyHeaderSeparation());
    }

    public function testSupportsProxyHeaderSeparationIsTrueAboveMinimumVersion(): void
    {
        self::requiresProxyHeaderSeparationConstants();

        self::setVersionInfo([
            'version' => '7.42.0',
            'features' => 0,
        ]);

        self::assertTrue(CurlVersion::supportsProxyHeaderSeparation());
    }

    public function testSupportsProxyHeaderSeparationIsTrueAtPatchVersion(): void
    {
        self::requiresProxyHeaderSeparationConstants();

        self::setVersionInfo([
            'version' => '7.42.1',
            'features' => 0,
        ]);

        self::assertTrue(CurlVersion::supportsProxyHeaderSeparation());
    }

    public function testSupportsProxyHeaderSeparationIsFalseWhenVersionInfoIsUnavailable(): void
    {
        self::setVersionInfo(false);

        self::assertFalse(CurlVersion::supportsProxyHeaderSeparation());
    }

    public function testEnsureSupportedRejectsCurlWithoutSslSupport(): void
    {
        if (!\defined('CURL_SSLVERSION_TLSv1_2')) {
            self::markTestSkipped('CURL_SSLVERSION_TLSv1_2 is not available.');
        }

        self::setVersionInfo([
            'version' => '7.34.0',
            'features' => 0,
        ]);

        $this->expectException(ConnectException::class);
        $this->expectExceptionMessage('SSL support');

        CurlVersion::ensureSupported(new Request('GET', 'http://example.com'));
    }

    public function testSupportsProtocolsStrReturnsFalseWhenVersionInfoIsUnavailable(): void
    {
        self::setVersionInfo(false);

        self::assertFalse(CurlVersion::supportsProtocolsStr());
    }

    public function testSupportsProtocolsStrRequiresRuntimeVersion(): void
    {
        self::requiresProtocolsStrConstant();

        self::setVersionInfo([
            'version' => '7.84.0',
            'features' => 0,
        ]);

        self::assertFalse(CurlVersion::supportsProtocolsStr());
    }

    public function testSupportsProtocolsStrWhenRuntimeAndConstantAreAvailable(): void
    {
        self::requiresProtocolsStrConstant();

        self::setVersionInfo([
            'version' => '7.85.0',
            'features' => 0,
        ]);

        self::assertTrue(CurlVersion::supportsProtocolsStr());
    }

    private static function requiresHttp3Constants(): void
    {
        if (!\defined('CURL_VERSION_HTTP3') || !\defined('CURL_HTTP_VERSION_3')) {
            self::markTestSkipped('HTTP/3 cURL constants are not available.');
        }
    }

    private static function requiresCurlSslConstants(): void
    {
        if (!\defined('CURL_SSLVERSION_TLSv1_2')) {
            self::markTestSkipped('CURL_SSLVERSION_TLSv1_2 is not available.');
        }

        self::requiresCurlSslFeature();
    }

    private static function requiresCurlSslFeature(): void
    {
        if (!\defined('CURL_VERSION_SSL')) {
            self::markTestSkipped('CURL_VERSION_SSL is not available.');
        }
    }

    private static function requiresProtocolsStrConstant(): void
    {
        if (!\defined('CURLOPT_PROTOCOLS_STR')) {
            self::markTestSkipped('CURLOPT_PROTOCOLS_STR is not available.');
        }
    }

    private static function requiresProxyHeaderSeparationConstants(): void
    {
        foreach (['CURLOPT_PROXYHEADER', 'CURLOPT_HEADEROPT', 'CURLHEADER_SEPARATE'] as $constant) {
            if (!\defined($constant)) {
                self::markTestSkipped($constant.' is not available.');
            }
        }
    }

    private static function http3Feature(): int
    {
        return (int) \constant('CURL_VERSION_HTTP3');
    }

    private static function curlSslFeature(): int
    {
        self::requiresCurlSslFeature();

        return \CURL_VERSION_SSL;
    }

    /**
     * @return array{version: string, features: int}|false|null
     */
    private static function getVersionInfo()
    {
        $property = new \ReflectionProperty(CurlVersion::class, 'versionInfo');
        if (\PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }

        return $property->getValue();
    }

    /**
     * @param array{version: string, features: int}|false|null $versionInfo
     */
    private static function setVersionInfo($versionInfo): void
    {
        $property = new \ReflectionProperty(CurlVersion::class, 'versionInfo');
        if (\PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }

        $property->setValue(null, $versionInfo);
    }
}
