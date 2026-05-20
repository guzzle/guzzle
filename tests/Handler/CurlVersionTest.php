<?php

declare(strict_types=1);

namespace GuzzleHttp\Test\Handler;

use GuzzleHttp\Handler\CurlVersion;
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

    private static function requiresHttp3Constants(): void
    {
        if (!\defined('CURL_VERSION_HTTP3') || !\defined('CURL_HTTP_VERSION_3')) {
            self::markTestSkipped('HTTP/3 cURL constants are not available.');
        }
    }

    private static function http3Feature(): int
    {
        return (int) \constant('CURL_VERSION_HTTP3');
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
