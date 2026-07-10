<?php

namespace GuzzleHttp\Test;

use GuzzleHttp;
use GuzzleHttp\Handler\CurlVersion;
use GuzzleHttp\Psr7;
use GuzzleHttp\TransportSharing;
use GuzzleHttp\Utils;
use PHPUnit\Framework\TestCase;

class UtilsTest extends TestCase
{
    public static function noBodyProvider()
    {
        return [['get'], ['head'], ['delete']];
    }

    public function testParsesHeadersFromLines()
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
        self::assertSame($expected, GuzzleHttp\headers_from_lines($lines));
    }

    public function testParsesHeadersFromLinesWithMultipleLines()
    {
        $lines = ['Foo: bar', 'Foo: baz', 'Foo: 123'];
        $expected = ['Foo' => ['bar', 'baz', '123']];

        self::assertSame($expected, Utils::headersFromLines($lines));
        self::assertSame($expected, GuzzleHttp\headers_from_lines($lines));
    }

    public function testChooseHandler()
    {
        self::assertIsCallable(Utils::chooseHandler());
        self::assertIsCallable(GuzzleHttp\choose_handler());
    }

    public function testChooseHandlerAcceptsPreferredTransportSharing(): void
    {
        self::skipIfDefaultCurlHandlerIsUnavailable();
        $previous = self::setCurlVersionInfo(['version' => '8.6.0', 'features' => self::curlSslFeature()]);

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
            self::setCurlVersionInfo($previous);
            unset($_SERVER['curl_test'], $_SERVER['_curl_share'], $_SERVER['_curl_share_init_count']);
        }
    }

    public function testChooseHandlerAcceptsRequiredTransportSharing(): void
    {
        self::skipIfDefaultCurlHandlerIsUnavailable();
        $previous = self::setCurlVersionInfo(['version' => '8.6.0', 'features' => self::curlSslFeature()]);

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
            self::setCurlVersionInfo($previous);
            unset($_SERVER['curl_test'], $_SERVER['_curl_share'], $_SERVER['_curl_share_init_count']);
        }
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

    public static function connectionCapOptionProvider(): iterable
    {
        yield 'max host connections' => ['max_host_connections'];
        yield 'max total connections' => ['max_total_connections'];
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

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Enabling the "stream" request option on a stream handler configured with the "max_host_connections" or "max_total_connections" option is not supported because streamed connections cannot be capped.');

        $handler(new Psr7\Request('GET', 'http://localhost/'), ['stream' => true]);
    }

    public function testDefaultUserAgent()
    {
        self::assertIsString(Utils::defaultUserAgent());
        self::assertIsString(GuzzleHttp\default_user_agent());
    }

    public function testReturnsDebugResource()
    {
        self::assertIsResource(Utils::debugResource());
        self::assertIsResource(GuzzleHttp\debug_resource());
    }

    public function testProvidesDefaultCaBundler()
    {
        self::assertFileExists(Utils::defaultCaBundle());
        self::assertFileExists(GuzzleHttp\default_ca_bundle());
    }

    public function testNormalizeHeaderKeys()
    {
        $input = ['HelLo' => 'foo', 'WORld' => 'bar'];
        $expected = ['hello' => 'HelLo', 'world' => 'WORld'];

        self::assertSame($expected, Utils::normalizeHeaderKeys($input));
        self::assertSame($expected, GuzzleHttp\normalize_header_keys($input));
    }

    public function testNormalizeHeaderKeysHandlesNumericKeys()
    {
        $input = [0 => 'zero', 'HelLo' => 'foo'];
        $expected = [0 => 0, 'hello' => 'HelLo'];

        self::assertSame($expected, Utils::normalizeHeaderKeys($input));
    }

    public function testNormalizeProtocolsAcceptsLowercaseProtocols()
    {
        self::assertSame(['http', 'https'], Utils::normalizeProtocols(['http', 'https', 'http']));
    }

    public function testNormalizeProtocolsRejectsUppercaseProtocols()
    {
        $this->expectException(GuzzleHttp\Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('protocols may only contain "http" and "https"');

        Utils::normalizeProtocols(['HTTPS']);
    }

    public static function noProxyProvider()
    {
        return [
            ['mit.edu', ['.mit.edu'], false],
            ['foo.mit.edu', ['.mit.edu'], true],
            ['foo.mit.edu:123', ['.mit.edu'], true],
            ['mit.edu', ['mit.edu'], true],
            ['mit.edu', ['baz', 'mit.edu'], true],
            ['mit.edu', ['', '', 'mit.edu'], true],
            ['mit.edu', ['baz', '*'], true],
            ['0', ['0'], true],
            ['foo.example.com', ['example.com'], true],
            ['EXAMPLE.com', ['example.com'], true],
            ['example.com', ['EXAMPLE.com'], true],
            ['foo.example.com', ['EXAMPLE.com'], true],
            ['example.com.', ['example.com'], true],
            ['foo.example.com.', ['example.com'], true],
            ['example.com', ['example.com.'], true],
            ['foo.example.com', ['example.com.'], true],
            ['example.com.', ['.example.com'], false],
            ['foo.example.com.', ['.example.com'], true],
            ['foo.example.com', ['.example.com.'], true],
            ['example.com..', ['example.com'], false],
            ['.', ['*'], false],
            ['foo.example.com:123', ['.EXAMPLE.com'], true],
            ['example.com', ['.EXAMPLE.com'], false],
            ['example.com', ['example.com:443'], false],
            ['[::1]', ['[::1]'], true],
            ['[::1]', ['::1'], true],
            ['::1', ['::1'], true],
            ['[::1]', ['[::1]:8080'], false],
            ['[::1]:8080', ['::1'], true],
            ['[::1]:8080', ['[::1]'], true],
            ['[::1]:8080', ['[::1]:8080'], false],
            ['[fd00::1]', ['fd00::1'], true],
            ['[fd00::1]', ['[fd00::2]'], false],
            ['[2a00:f48:1008::212:183:10]', ['2a00:f48:1008::212:183:10'], true],
            ['[2a00:f48:1008::212:183:10]', ['[2a00:f48:1008::212:183:10]'], true],
            ['[2A00:F48:1008::212:183:10]', ['2a00:f48:1008::212:183:10'], true],
            ['[0:0:0:0:0:0:0:1]', ['::1'], true],
            ['[::1]', ['[0:0:0:0:0:0:0:1]'], true],
            ['example.com:443', ['example.com'], true],
            ['example.com:443', ['example.com:443'], false],
            ['127.0.0.1.', ['127.0.0.1'], false],
            ['127.0.0.1.', ['127.0.0.0/8'], false],
            ['[::1].', ['[::1]'], false],
            ['::1.', ['::1'], false],
            ['127.0.0.1', ['.127.0.0.1'], false],
            ['foo.127.0.0.1', ['127.0.0.1'], false],
            ['192.168.1.10', ['192.168.0.0/16'], true],
            ['192.169.1.10', ['192.168.0.0/16'], false],
            ['[fd00::1]', ['fd00::/8'], true],
            ['[fe80::1]', ['fd00::/8'], false],
        ];
    }

    /**
     * @dataProvider noproxyProvider
     */
    public function testChecksNoProxyList($host, $list, $result)
    {
        self::assertSame($result, Utils::isHostInNoProxy($host, $list));
        self::assertSame($result, \GuzzleHttp\is_host_in_noproxy($host, $list));
    }

    public static function uriNoProxyProvider()
    {
        return [
            ['http://example.com', 'example.com', true],
            ['http://foo.com', 'example.com, foo.com', true],
            ['http://foo.com', ' example.com , foo.com ', true],
            ['http://foo.com', '', false],
            ['http://foo.com', null, false],
            ['http://foo.com', false, false],
            ['http://example.com', [' example.com ', new \stdClass()], true],
            ['http://example.com', ['example.com:80'], true],
            ['https://example.com', ['example.com:443'], true],
            ['http://example.com', ['EXAMPLE.com'], true],
            ['http://foo.example.com', ['EXAMPLE.com'], true],
            ['http://foo.example.com', ['.EXAMPLE.com'], true],
            ['http://example.com', ['.EXAMPLE.com'], false],
            ['http://example.com.', ['example.com'], true],
            ['http://foo.example.com.', ['example.com'], true],
            ['http://example.com', ['example.com.'], true],
            ['http://foo.example.com', ['example.com.'], true],
            ['http://example.com.', ['.example.com'], false],
            ['http://foo.example.com.', ['.example.com'], true],
            ['http://foo.example.com', ['.example.com.'], true],
            ['http://example.com.:8080', ['example.com:8080'], true],
            ['http://example.com.', ['example.com:80'], true],
            ['https://example.com.', ['example.com:443'], true],
            ['http://example.com..', ['example.com'], false],
            ['http://notexample.com', ['example.com'], false],
            ['http://example.com:8080', ['example.com:8080'], true],
            ['http://example.com:8081', ['example.com:8080'], false],
            ['http://foo.example.com:8080', ['example.com:8080'], true],
            ['http://foo.example.com:8080', ['.example.com:8080'], true],
            ['http://example.com:8080', ['.example.com:8080'], false],
            ['http://foo.example.com:8080', ['.EXAMPLE.com:8080'], true],
            ['http://foo.example.com:8081', ['.EXAMPLE.com:8080'], false],
            ['http://[::1]:8080', ['[::1]:8080'], true],
            ['http://[::1]:8081', ['[::1]:8080'], false],
            ['http://[::1]', ['[::1]:80'], true],
            ['https://[::1]', ['[::1]:443'], true],
            ['http://[::1]', ['::1:80'], false],
            ['http://[::1]', ['[::1].'], false],
            ['http://[0:0:0:0:0:0:0:1]', ['::1'], true],
            ['http://[::1]', ['[0:0:0:0:0:0:0:1]'], true],
            ['http://[::1]:8080', ['[0:0:0:0:0:0:0:1]:8080'], true],
            ['http://[::1]:8081', ['[0:0:0:0:0:0:0:1]:8080'], false],
            ['http://[::1:80]', ['::1:80'], true],
            ['http://[::1]', ['.[::1]'], false],
            ['http://127.0.0.1', ['127.0.0.1'], true],
            ['http://127.0.0.1:8080', ['127.0.0.1:8080'], true],
            ['http://127.0.0.1:8081', ['127.0.0.1:8080'], false],
            ['http://127.0.0.1', ['.127.0.0.1'], false],
            ['http://127.0.0.1.', ['127.0.0.1'], false],
            ['http://127.0.0.1.', ['127.0.0.0/8'], false],
            ['http://test.test.com', ['*.test.com'], false],
            ['http://127.0.0.1', ['127.0.0.*'], false],
            ['http://0', ['0'], true],
            ['http://anything.test', ['*'], true],
            ['http://.', ['*'], false],
            ['http://example.com', ['*:80'], true],
            ['https://example.com', ['*:80'], false],
            ['http://example.com:8080', ['*:80'], false],
            ['http://example.com:80', ['example.com:00080'], true],
            ['http://example.com:65535', ['example.com:65535'], true],
            ['http://example.com', ['.*'], false],
            ['http://example.com', ['.*:80'], false],
            ['http://example.com', ['example.com:abc'], false],
            ['http://example.com', ['example.com:99999'], false],
            ['http://example.com', ['example.com:999999999999999999999999'], false],
            ['http://example.com', ["foo\0bar"], false],
            ['http://127.0.0.1', ["127.0.0.1\0evil"], false],
            ['http://[::1]', ["::1\0evil"], false],
            ['http://192.168.1.10', ['192.168.0.0/16'], true],
            ['http://192.168.255.255', ['192.168.0.0/16'], true],
            ['http://192.169.1.10', ['192.168.0.0/16'], false],
            ['http://192.169.0.0', ['192.168.0.0/16'], false],
            ['http://127.0.0.1', ['127.0.0.0/8'], true],
            ['http://10.1.2.3:8080', ['10.0.0.0/8'], true],
            ['http://[fd00::1]', ['fd00::/8'], true],
            ['http://[fd00::1]', ['[fd00::]/8'], true],
            ['http://[fdff:ffff::1]', ['fd00::/8'], true],
            ['http://[fe00::1]', ['fd00::/8'], false],
            ['http://[fe80::1]', ['fe80::/10'], true],
            ['http://[febf::1]', ['fe80::/10'], true],
            ['http://[fec0::1]', ['fe80::/10'], false],
            ['http://example.com', ['example.com/24'], false],
            ['http://127.0.0.1', ['127.0.0.0/999999999999999999999999'], false],
            ['http://192.168.1.10', ['192.168.0.0/33'], false],
            ['http://[fd00::1]', ['fd00::/129'], false],
            ['http://192.168.1.10', ['192.168.0.0/foo'], false],
            ['http://192.168.1.10:8080', ['192.168.0.0/16:8080'], false],
            ['http://[fd00::1]:8080', ['fd00::/8:8080'], false],
            ['http://[fd00::1]:8080', ['[fd00::]/8:8080'], false],
            ['http://192.168.1.10', ['fd00::/8'], false],
            ['http://[fd00::1]', ['192.168.0.0/16'], false],
            ['http://192.168.1.10', ["192.168.0.0\0evil/16"], false],
            ['http://203.0.113.10', ['0.0.0.0/0'], true],
            ['http://203.0.113.10', ['203.0.113.10/32'], true],
            ['http://203.0.113.11', ['203.0.113.10/32'], false],
            ['http://[2001:db8::1]', ['::/0'], true],
            ['http://[2001:db8::1]', ['2001:db8::1/128'], true],
            ['http://[2001:db8::2]', ['2001:db8::1/128'], false],
            ['/relative-path', ['*'], false],
        ];
    }

    /**
     * @dataProvider uriNoProxyProvider
     */
    public function testChecksUriNoProxyList($uri, $list, $result)
    {
        self::assertSame($result, Utils::isUriInNoProxy(Psr7\Utils::uriFor($uri), $list));
    }

    public function testEnsuresNoProxyCheckHostIsSet()
    {
        $this->expectException(\InvalidArgumentException::class);

        Utils::isHostInNoProxy('', []);
    }

    public function testEnsuresNoProxyCheckHostIsSetLegacy()
    {
        $this->expectException(\InvalidArgumentException::class);

        \GuzzleHttp\is_host_in_noproxy('', []);
    }

    public function testEncodesJson()
    {
        self::assertSame('true', Utils::jsonEncode(true));
        self::assertSame('true', \GuzzleHttp\json_encode(true));
    }

    public function testEncodesJsonAndThrowsOnError()
    {
        $this->expectException(\InvalidArgumentException::class);

        Utils::jsonEncode("\x99");
    }

    public function testEncodesJsonAndThrowsOnErrorLegacy()
    {
        $this->expectException(\InvalidArgumentException::class);

        \GuzzleHttp\json_encode("\x99");
    }

    public function testDecodesJson()
    {
        self::assertTrue(Utils::jsonDecode('true'));
        self::assertTrue(\GuzzleHttp\json_decode('true'));
    }

    public function testDecodesJsonAndThrowsOnError()
    {
        $this->expectException(\InvalidArgumentException::class);

        Utils::jsonDecode('{{]]');
    }

    public function testDecodesJsonAndThrowsOnErrorLegacy()
    {
        $this->expectException(\InvalidArgumentException::class);

        \GuzzleHttp\json_decode('{{]]');
    }

    /**
     * @dataProvider invalidJsonDepthProvider
     */
    public function testDecodesJsonAndThrowsOnInvalidDepth(int $depth)
    {
        $this->expectException(\InvalidArgumentException::class);

        Utils::jsonDecode('{}', true, $depth);
    }

    /**
     * @dataProvider invalidJsonDepthProvider
     */
    public function testDecodesJsonAndThrowsOnInvalidDepthLegacy(int $depth)
    {
        $this->expectException(\InvalidArgumentException::class);

        \GuzzleHttp\json_decode('{}', true, $depth);
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
        ) {
            self::markTestSkipped('Default cURL handler with share handles is unavailable.');
        }
    }

    private static function curlSslFeature(): int
    {
        if (!\defined('CURL_VERSION_SSL')) {
            self::markTestSkipped('CURL_VERSION_SSL is unavailable.');
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
    public function __toString()
    {
        return 'foo';
    }
}
