<?php

declare(strict_types=1);

namespace GuzzleHttp\Test;

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
