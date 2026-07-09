<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests;

use GuzzleHttp\Exception\InvalidArgumentException;
use GuzzleHttp\Handler\CurlVersion;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\TransportSharing;
use GuzzleHttp\Utils;
use PHPUnit\Framework\TestCase;

class UtilsTest extends TestCase
{
    public static function noBodyProvider(): array
    {
        return [['get'], ['head'], ['delete']];
    }

    public function testParsesHeadersFromLines(): void
    {
        $lines = [
            'Foo: bar',
            'Foo: baz',
            'Abc: 123',
            'Def: a, b',
        ];

        $expected = [
            'Foo' => ['bar', 'baz'],
            'Abc' => ['123'],
            'Def' => ['a, b'],
        ];

        self::assertSame($expected, Utils::headersFromLines($lines));
    }

    public function testParsesHeadersFromLinesWithMultipleLines(): void
    {
        $lines = ['Foo: bar', 'Foo: baz', 'Foo: 123'];
        $expected = ['Foo' => ['bar', 'baz', '123']];

        self::assertSame($expected, Utils::headersFromLines($lines));
    }

    public function testChooseHandler(): void
    {
        self::assertIsCallable(Utils::chooseHandler());
    }

    public function testChooseHandlerAcceptsPreferredTransportSharing(): void
    {
        self::skipIfDefaultCurlHandlerIsUnavailable();
        $previousVersionInfo = self::setCurlVersionInfo([
            'version' => '8.6.0',
            'features' => self::curlSslFeature(),
        ]);

        $_SERVER['curl_test'] = true;
        unset($_SERVER['_curl_share'], $_SERVER['_curl_share_init_count']);

        try {
            $handler = Utils::chooseHandler(['transport_sharing' => TransportSharing::HANDLER_PREFER]);

            self::assertIsCallable($handler);
            self::assertSame(1, $_SERVER['_curl_share_init_count']);
            self::assertSame([
                \CURL_LOCK_DATA_DNS,
                \CURL_LOCK_DATA_SSL_SESSION,
            ], $_SERVER['_curl_share'][\CURLSHOPT_SHARE]);
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
            unset($_SERVER['curl_test'], $_SERVER['_curl_share'], $_SERVER['_curl_share_init_count']);
        }
    }

    public function testChooseHandlerAcceptsRequiredTransportSharing(): void
    {
        self::skipIfDefaultCurlHandlerIsUnavailable();
        $previousVersionInfo = self::setCurlVersionInfo([
            'version' => '8.6.0',
            'features' => self::curlSslFeature(),
        ]);

        $_SERVER['curl_test'] = true;
        unset($_SERVER['_curl_share'], $_SERVER['_curl_share_init_count']);

        try {
            $handler = Utils::chooseHandler(['transport_sharing' => TransportSharing::HANDLER_REQUIRE]);

            self::assertIsCallable($handler);
            self::assertSame(1, $_SERVER['_curl_share_init_count']);
            self::assertSame([
                \CURL_LOCK_DATA_DNS,
                \CURL_LOCK_DATA_SSL_SESSION,
            ], $_SERVER['_curl_share'][\CURLSHOPT_SHARE]);
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
            unset($_SERVER['curl_test'], $_SERVER['_curl_share'], $_SERVER['_curl_share_init_count']);
        }
    }

    public function testChooseHandlerAcceptsPersistentPreferTransportSharing(): void
    {
        self::skipIfDefaultCurlHandlerIsUnavailable();
        $previousVersionInfo = self::setCurlVersionInfo([
            'version' => '8.12.0',
            'features' => self::curlSslFeature(),
        ]);

        $_SERVER['curl_test'] = true;
        unset($_SERVER['_curl_share_init_count'], $_SERVER['_curl_share_init_persistent_count']);

        try {
            $handler = Utils::chooseHandler(['transport_sharing' => TransportSharing::PERSISTENT_PREFER]);

            self::assertIsCallable($handler);
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
            unset($_SERVER['curl_test'], $_SERVER['_curl_share_init_count'], $_SERVER['_curl_share_init_persistent_count']);
        }
    }

    /**
     * @dataProvider connectionCapOptionProvider
     */
    public function testChooseHandlerRejectsConnectionCapsWithRequiredPersistentTransportSharing(string $option): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('persistent transport sharing');

        Utils::chooseHandler([
            'transport_sharing' => TransportSharing::PERSISTENT_REQUIRE,
            $option => 1,
        ]);
    }

    public function testChooseHandlerDegradesPersistentPreferTransportSharingWithConnectionCaps(): void
    {
        self::skipIfDefaultCurlHandlerIsUnavailable();
        self::skipIfDefaultCurlMultiHandlerIsUnavailable();
        $previousVersionInfo = self::setCurlVersionInfo([
            'version' => '8.12.0',
            'features' => self::curlSslFeature(),
        ]);

        $_SERVER['curl_test'] = true;
        unset($_SERVER['_curl_share'], $_SERVER['_curl_share_init_count'], $_SERVER['_curl_share_init_persistent_count']);

        try {
            $handler = Utils::chooseHandler([
                'transport_sharing' => TransportSharing::PERSISTENT_PREFER,
                'max_host_connections' => 1,
            ]);

            self::assertIsCallable($handler);
            self::assertArrayNotHasKey('_curl_share_init_persistent_count', $_SERVER);
            self::assertSame(1, $_SERVER['_curl_share_init_count']);
            self::assertSame([
                \CURL_LOCK_DATA_DNS,
                \CURL_LOCK_DATA_SSL_SESSION,
            ], $_SERVER['_curl_share'][\CURLSHOPT_SHARE]);
        } finally {
            self::setCurlVersionInfo($previousVersionInfo);
            unset($_SERVER['curl_test'], $_SERVER['_curl_share'], $_SERVER['_curl_share_init_count'], $_SERVER['_curl_share_init_persistent_count']);
        }
    }

    /**
     * @dataProvider connectionCapOptionProvider
     */
    public function testChooseHandlerRejectsStreamRequestsWhenConnectionCapsAreConfigured(string $option): void
    {
        if (!\ini_get('allow_url_fopen')) {
            self::markTestSkipped('The allow_url_fopen ini setting is required.');
        }

        $handler = Utils::chooseHandler([$option => 1]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Passing the "stream" request option to a stream handler configured with the "max_host_connections" or "max_total_connections" option is not supported because streamed connections cannot be capped.');

        $handler(new Request('GET', 'http://localhost/'), ['stream' => true]);
    }

    public static function connectionCapOptionProvider(): iterable
    {
        yield 'max host connections' => ['max_host_connections'];
        yield 'max total connections' => ['max_total_connections'];
    }

    public function testChooseHandlerAcceptsDisabledTransportSharing(): void
    {
        $_SERVER['curl_test'] = true;
        unset($_SERVER['_curl_share_init_count']);

        try {
            self::assertIsCallable(Utils::chooseHandler(['transport_sharing' => TransportSharing::NONE]));

            self::assertArrayNotHasKey('_curl_share_init_count', $_SERVER);
        } finally {
            unset($_SERVER['curl_test'], $_SERVER['_curl_share_init_count']);
        }
    }

    public function testDefaultUserAgent(): void
    {
        self::assertIsString(Utils::defaultUserAgent());
    }

    public function testReturnsDebugResource(): void
    {
        self::assertIsResource(Utils::debugResource());
    }

    public function testNormalizeHeaderKeys(): void
    {
        $input = ['HelLo' => 'foo', 'WORld' => 'bar'];
        $expected = ['hello' => 'HelLo', 'world' => 'WORld'];

        self::assertSame($expected, Utils::normalizeHeaderKeys($input));
    }

    public function testNormalizeHeaderKeysHandlesNumericKeys(): void
    {
        $input = [0 => 'zero', 'HelLo' => 'foo'];
        $expected = [0 => 0, 'hello' => 'HelLo'];

        self::assertSame($expected, Utils::normalizeHeaderKeys($input));
    }

    public function testNormalizeProtocolsAcceptsLowercaseProtocols(): void
    {
        self::assertSame(['http', 'https'], Utils::normalizeProtocols(['http', 'https', 'http']));
    }

    public function testNormalizeProtocolsRejectsUppercaseProtocols(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('protocols may only contain "http" and "https"');

        Utils::normalizeProtocols(['HTTPS']);
    }

    public function testEncodesJson(): void
    {
        self::assertSame('true', Utils::jsonEncode(true));
    }

    public function testEncodesJsonAndThrowsOnError(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Utils::jsonEncode("\x99");
    }

    public function testEncodesJsonAndThrowsOnErrorWithNativeOption(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Utils::jsonEncode("\x99", \JSON_THROW_ON_ERROR);
    }

    public function testDecodesJson(): void
    {
        self::assertTrue(Utils::jsonDecode('true'));
    }

    public function testDecodesJsonAndThrowsOnError(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Utils::jsonDecode('{{]]');
    }

    public function testDecodesJsonAndThrowsOnErrorWithNativeOption(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Utils::jsonDecode('{{]]', false, 512, \JSON_THROW_ON_ERROR);
    }

    /**
     * @dataProvider invalidJsonDepthProvider
     */
    public function testDecodesJsonAndThrowsOnInvalidDepth(int $depth): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Utils::jsonDecode('{}', true, $depth);
    }

    public static function invalidJsonDepthProvider(): array
    {
        return [[0], [-1]];
    }

    private static function skipIfDefaultCurlHandlerIsUnavailable(): void
    {
        if (
            !\function_exists('curl_share_init')
            || !\function_exists('curl_share_setopt')
            || !\function_exists('curl_exec')
            || !CurlVersion::supportsCurlHandler()
            || !CurlVersion::supportsHandlerSharing()
        ) {
            self::markTestSkipped('Default cURL handler with share handles is unavailable.');
        }
    }

    private static function skipIfDefaultCurlMultiHandlerIsUnavailable(): void
    {
        if (!\function_exists('curl_multi_exec') || !\function_exists('curl_exec') || !CurlVersion::supportsCurlHandler()) {
            self::markTestSkipped('Default cURL multi handler is unavailable.');
        }
    }

    private static function curlSslFeature(): int
    {
        if (!\defined('CURL_VERSION_SSL')) {
            self::markTestSkipped('CURL_VERSION_SSL is not available.');
        }

        return \CURL_VERSION_SSL;
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

final class StrClass
{
    public function __toString(): string
    {
        return 'foo';
    }
}
