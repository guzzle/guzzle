<?php

namespace GuzzleHttp\Test;

use GuzzleHttp;
use GuzzleHttp\TransportSharing;
use GuzzleHttp\Utils;
use PHPUnit\Framework\TestCase;

class UtilsTest extends TestCase
{
    public static function noBodyProvider()
    {
        return [['get'], ['head'], ['delete']];
    }

    public static function typeProvider()
    {
        return [
            ['foo', 'string(3) "foo"'],
            [true, 'bool(true)'],
            [false, 'bool(false)'],
            [10, 'int(10)'],
            [1.0, 'float(1)'],
            [new StrClass(), 'object(GuzzleHttp\Test\StrClass)'],
            [['foo'], 'array(1)'],
        ];
    }

    /**
     * @dataProvider typeProvider
     */
    public function testDescribesType($input, $output)
    {
        /**
         * Output may not match if Xdebug is loaded and overloading var_dump().
         *
         * @see https://xdebug.org/docs/display#overload_var_dump
         */
        if (extension_loaded('xdebug')) {
            $originalOverload = ini_get('xdebug.overload_var_dump');
            ini_set('xdebug.overload_var_dump', 0);
        }

        try {
            self::assertSame($output, Utils::describeType($input));
            self::assertSame($output, GuzzleHttp\describe_type($input));
        } finally {
            if (extension_loaded('xdebug')) {
                ini_set('xdebug.overload_var_dump', $originalOverload);
            }
        }
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
            unset($_SERVER['curl_test'], $_SERVER['_curl_share'], $_SERVER['_curl_share_init_count']);
        }
    }

    public function testChooseHandlerAcceptsRequiredTransportSharing(): void
    {
        self::skipIfDefaultCurlHandlerIsUnavailable();

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
            ['example.com', ['example.com:443'], false],
            ['[::1]', ['[::1]'], true],
            ['[::1]', ['::1'], true],
            ['::1', ['::1'], true],
            ['[::1]', ['[::1]:8080'], false],
            ['[::1]:8080', ['::1'], true],
            ['[::1]:8080', ['[::1]'], true],
            ['[fd00::1]', ['fd00::1'], true],
            ['[fd00::1]', ['[fd00::2]'], false],
            ['[2a00:f48:1008::212:183:10]', ['2a00:f48:1008::212:183:10'], true],
            ['[2a00:f48:1008::212:183:10]', ['[2a00:f48:1008::212:183:10]'], true],
            ['[2A00:F48:1008::212:183:10]', ['2a00:f48:1008::212:183:10'], true],
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
            ['http://example.com:8080', ['example.com:8080'], true],
            ['http://example.com:8081', ['example.com:8080'], false],
            ['http://foo.example.com:8080', ['example.com:8080'], true],
            ['http://foo.example.com:8080', ['.example.com:8080'], true],
            ['http://example.com:8080', ['.example.com:8080'], false],
            ['http://[::1]:8080', ['[::1]:8080'], true],
            ['http://[::1]:8081', ['[::1]:8080'], false],
            ['http://[::1]', ['[::1]:80'], true],
            ['https://[::1]', ['[::1]:443'], true],
            ['http://[::1]', ['::1:80'], false],
            ['http://test.test.com', ['*.test.com'], false],
            ['http://127.0.0.1', ['127.0.0.*'], false],
            ['http://0', ['0'], true],
            ['http://anything.test', ['*'], true],
            ['http://example.com', ['example.com:abc'], false],
            ['http://example.com', ['example.com:99999'], false],
        ];
    }

    /**
     * @dataProvider uriNoProxyProvider
     */
    public function testChecksUriNoProxyList($uri, $list, $result)
    {
        self::assertSame($result, Utils::isUriInNoProxy(GuzzleHttp\Psr7\Utils::uriFor($uri), $list));
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
            || !\function_exists('curl_version')
            || version_compare(curl_version()['version'], '7.21.2') < 0
        ) {
            self::markTestSkipped('Default cURL handler with share handles is unavailable.');
        }
    }
}

final class StrClass
{
    public function __toString()
    {
        return 'foo';
    }
}
