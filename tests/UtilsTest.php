<?php

declare(strict_types=1);

namespace GuzzleHttp\Test;

use GuzzleHttp\Psr7;
use GuzzleHttp\Utils;
use PHPUnit\Framework\TestCase;

class UtilsTest extends TestCase
{
    public static function noBodyProvider(): array
    {
        return [['get'], ['head'], ['delete']];
    }

    public static function typeProvider(): array
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
     *
     * @param mixed $input
     */
    public function testDescribesType($input, string $output): void
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
        } finally {
            if (extension_loaded('xdebug')) {
                ini_set('xdebug.overload_var_dump', $originalOverload);
            }
        }
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

    public static function noProxyProvider(): array
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
            ['192.168.1.10', ['192.168.0.0/16'], true],
            ['192.169.1.10', ['192.168.0.0/16'], false],
            ['[fd00::1]', ['fd00::/8'], true],
            ['[fe80::1]', ['fd00::/8'], false],
        ];
    }

    /**
     * @dataProvider noproxyProvider
     */
    public function testChecksNoProxyList(string $host, array $list, bool $result): void
    {
        self::assertSame($result, Utils::isHostInNoProxy($host, $list));
    }

    public function testNormalizesNoProxyString(): void
    {
        self::assertSame(['foo.com', '.bar.com'], Utils::normalizeNoProxy(' foo.com, .bar.com, '));
    }

    public function testNormalizesNoProxyArray(): void
    {
        self::assertSame(['foo.com', '.bar.com'], Utils::normalizeNoProxy([' foo.com ', '', '.bar.com']));
    }

    public function testValidatesNoProxyValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('proxy no list must be a string or array of strings');

        Utils::normalizeNoProxy(new \stdClass());
    }

    public function testValidatesNoProxyArrayValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('proxy no list must be a string or array of strings');

        Utils::normalizeNoProxy(['foo.com', new \stdClass()]);
    }

    public static function uriNoProxyProvider(): array
    {
        return [
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
            ['http://192.168.1.10', ['192.168.0.0/16'], true],
            ['http://192.169.1.10', ['192.168.0.0/16'], false],
            ['http://127.0.0.1', ['127.0.0.0/8'], true],
            ['http://10.1.2.3:8080', ['10.0.0.0/8'], true],
            ['http://[fd00::1]', ['fd00::/8'], true],
            ['http://[fd00::1]', ['[fd00::]/8'], true],
            ['http://[fe80::1]', ['fe80::/10'], true],
            ['http://[febf::1]', ['fe80::/10'], true],
            ['http://[fec0::1]', ['fe80::/10'], false],
            ['http://example.com', ['example.com/24'], false],
            ['http://192.168.1.10', ['192.168.0.0/33'], false],
            ['http://[fd00::1]', ['fd00::/129'], false],
            ['http://192.168.1.10', ['192.168.0.0/foo'], false],
            ['http://192.168.1.10', ['fd00::/8'], false],
            ['http://[fd00::1]', ['192.168.0.0/16'], false],
            ['http://203.0.113.10', ['0.0.0.0/0'], true],
            ['http://203.0.113.10', ['203.0.113.10/32'], true],
            ['http://203.0.113.11', ['203.0.113.10/32'], false],
            ['http://[2001:db8::1]', ['::/0'], true],
            ['http://[2001:db8::1]', ['2001:db8::1/128'], true],
            ['http://[2001:db8::2]', ['2001:db8::1/128'], false],
        ];
    }

    /**
     * @dataProvider uriNoProxyProvider
     */
    public function testChecksUriNoProxyList(string $uri, array $list, bool $result): void
    {
        self::assertSame($result, Utils::isUriInNoProxy(Psr7\Utils::uriFor($uri), $list));
    }

    public function testEnsuresNoProxyCheckHostIsSet(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Utils::isHostInNoProxy('', []);
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
}

final class StrClass
{
    public function __toString(): string
    {
        return 'foo';
    }
}
