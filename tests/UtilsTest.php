<?php

namespace GuzzleHttp\Test;

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
    }

    public function testParsesHeadersFromLinesWithMultipleLines()
    {
        $lines = ['Foo: bar', 'Foo: baz', 'Foo: 123'];
        $expected = ['Foo' => ['bar', 'baz', '123']];

        self::assertSame($expected, Utils::headersFromLines($lines));
    }

    public function testChooseHandler()
    {
        self::assertIsCallable(Utils::chooseHandler());
    }

    public function testDefaultUserAgent()
    {
        self::assertIsString(Utils::defaultUserAgent());
    }

    public function testReturnsDebugResource()
    {
        self::assertIsResource(Utils::debugResource());
    }

    public function testNormalizeHeaderKeys()
    {
        $input = ['HelLo' => 'foo', 'WORld' => 'bar'];
        $expected = ['hello' => 'HelLo', 'world' => 'WORld'];

        self::assertSame($expected, Utils::normalizeHeaderKeys($input));
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
        ];
    }

    /**
     * @dataProvider noproxyProvider
     */
    public function testChecksNoProxyList($host, $list, $result)
    {
        self::assertSame($result, Utils::isHostInNoProxy($host, $list));
    }

    public function testNormalizesNoProxyString()
    {
        self::assertSame(['foo.com', '.bar.com'], Utils::normalizeNoProxy(' foo.com, .bar.com, '));
    }

    public function testNormalizesNoProxyArray()
    {
        self::assertSame(['foo.com', '.bar.com'], Utils::normalizeNoProxy([' foo.com ', '', '.bar.com']));
    }

    public function testValidatesNoProxyValue()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('proxy no list must be a string or array of strings');

        Utils::normalizeNoProxy(new \stdClass());
    }

    public function testValidatesNoProxyArrayValues()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('proxy no list must be a string or array of strings');

        Utils::normalizeNoProxy(['foo.com', new \stdClass()]);
    }

    public function testEnsuresNoProxyCheckHostIsSet()
    {
        $this->expectException(\InvalidArgumentException::class);

        Utils::isHostInNoProxy('', []);
    }

    public function testEncodesJson()
    {
        self::assertSame('true', Utils::jsonEncode(true));
    }

    public function testEncodesJsonAndThrowsOnError()
    {
        $this->expectException(\InvalidArgumentException::class);

        Utils::jsonEncode("\x99");
    }

    public function testEncodesJsonAndThrowsOnErrorWithNativeOption()
    {
        $this->expectException(\InvalidArgumentException::class);

        Utils::jsonEncode("\x99", \JSON_THROW_ON_ERROR);
    }

    public function testDecodesJson()
    {
        self::assertTrue(Utils::jsonDecode('true'));
    }

    public function testDecodesJsonAndThrowsOnError()
    {
        $this->expectException(\InvalidArgumentException::class);

        Utils::jsonDecode('{{]]');
    }

    public function testDecodesJsonAndThrowsOnErrorWithNativeOption()
    {
        $this->expectException(\InvalidArgumentException::class);

        Utils::jsonDecode('{{]]', false, 512, \JSON_THROW_ON_ERROR);
    }

    /**
     * @dataProvider invalidJsonDepthProvider
     */
    public function testDecodesJsonAndThrowsOnInvalidDepth(int $depth)
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
    public function __toString()
    {
        return 'foo';
    }
}
