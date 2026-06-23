<?php

namespace GuzzleHttp\Tests\Handler;

use GuzzleHttp\Handler\CurlVersion;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Handler\Proxy;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GuzzleHttp\Handler\Proxy
 */
class ProxyTest extends TestCase
{
    public function testSendsToNonSync()
    {
        $a = $b = null;
        $m1 = new MockHandler([static function ($v) use (&$a) {
            $a = $v;
        }]);
        $m2 = new MockHandler([static function ($v) use (&$b) {
            $b = $v;
        }]);
        $h = Proxy::wrapSync($m1, $m2);
        $h(new Request('GET', 'http://foo.com'), []);
        self::assertNotNull($a);
        self::assertNull($b);
    }

    public function testSendsToSync()
    {
        $a = $b = null;
        $m1 = new MockHandler([static function ($v) use (&$a) {
            $a = $v;
        }]);
        $m2 = new MockHandler([static function ($v) use (&$b) {
            $b = $v;
        }]);
        $h = Proxy::wrapSync($m1, $m2);
        $h(new Request('GET', 'http://foo.com'), [RequestOptions::SYNCHRONOUS => true]);
        self::assertNull($a);
        self::assertNotNull($b);
    }

    public function testSendsToStreaming()
    {
        $a = $b = null;
        $m1 = new MockHandler([static function ($v) use (&$a) {
            $a = $v;
        }]);
        $m2 = new MockHandler([static function ($v) use (&$b) {
            $b = $v;
        }]);
        $h = Proxy::wrapStreaming($m1, $m2);
        $h(new Request('GET', 'http://foo.com'), []);
        self::assertNotNull($a);
        self::assertNull($b);
    }

    public function testSendsToNonStreaming()
    {
        $a = $b = null;
        $m1 = new MockHandler([static function ($v) use (&$a) {
            $a = $v;
        }]);
        $m2 = new MockHandler([static function ($v) use (&$b) {
            $b = $v;
        }]);
        $h = Proxy::wrapStreaming($m1, $m2);
        $h(new Request('GET', 'http://foo.com'), ['stream' => true]);
        self::assertNull($a);
        self::assertNotNull($b);
    }

    public function testTlsFallbackUsesDefaultWithoutCryptoMethod(): void
    {
        $a = $b = null;
        $m1 = new MockHandler([static function ($v) use (&$a) {
            $a = $v;
        }]);
        $m2 = new MockHandler([static function ($v) use (&$b) {
            $b = $v;
        }]);

        $h = Proxy::wrapTlsFallback($m1, $m2);
        $h(new Request('GET', 'http://foo.com'), []);

        self::assertNotNull($a);
        self::assertNull($b);
    }

    public function testTlsFallbackUsesFallbackWhenCurlCannotSelectTls12(): void
    {
        if (!\defined('CURL_VERSION_SSL')) {
            self::markTestSkipped('CURL_VERSION_SSL is unavailable.');
        }

        $previous = self::setCurlVersionInfo(['version' => '7.33.0', 'features' => \CURL_VERSION_SSL]);
        $a = $b = null;
        $m1 = new MockHandler([static function ($v) use (&$a) {
            $a = $v;
        }]);
        $m2 = new MockHandler([static function ($v) use (&$b) {
            $b = $v;
        }]);

        try {
            $h = Proxy::wrapTlsFallback($m1, $m2);
            $h(new Request('GET', 'http://foo.com'), [
                RequestOptions::CRYPTO_METHOD => \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
            ]);

            self::assertNull($a);
            self::assertNotNull($b);
        } finally {
            self::setCurlVersionInfo($previous);
        }
    }

    public function testTlsFallbackUsesDefaultWhenCurlCanSelectTls12(): void
    {
        if (!\defined('CURL_SSLVERSION_TLSv1_2') || !\defined('CURL_VERSION_SSL')) {
            self::markTestSkipped('TLS 1.2 cURL constants are unavailable.');
        }

        $previous = self::setCurlVersionInfo(['version' => '7.34.0', 'features' => \CURL_VERSION_SSL]);
        $a = $b = null;
        $m1 = new MockHandler([static function ($v) use (&$a) {
            $a = $v;
        }]);
        $m2 = new MockHandler([static function ($v) use (&$b) {
            $b = $v;
        }]);

        try {
            $h = Proxy::wrapTlsFallback($m1, $m2);
            $h(new Request('GET', 'http://foo.com'), [
                RequestOptions::CRYPTO_METHOD => \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
            ]);

            self::assertNotNull($a);
            self::assertNull($b);
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

        $previous = $property->getValue();
        $property->setValue(null, $versionInfo);

        return $previous;
    }
}
