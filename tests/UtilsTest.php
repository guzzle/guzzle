<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests;

use GuzzleHttp\Exception\InvalidArgumentException;
use GuzzleHttp\Handler\CurlVersion;
use GuzzleHttp\TransportSharing;
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
            [new StrClass(), 'object(GuzzleHttp\Tests\StrClass)'],
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

    public function testChooseHandlerAcceptsPersistentPreferTransportSharing(): void
    {
        self::skipIfDefaultCurlHandlerIsUnavailable();

        $_SERVER['curl_test'] = true;
        unset($_SERVER['_curl_share_init_count'], $_SERVER['_curl_share_init_persistent_count']);

        try {
            $handler = Utils::chooseHandler(['transport_sharing' => TransportSharing::PERSISTENT_PREFER]);

            self::assertIsCallable($handler);
        } finally {
            unset($_SERVER['curl_test'], $_SERVER['_curl_share_init_count'], $_SERVER['_curl_share_init_persistent_count']);
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

    /**
     * @dataProvider validDelayProvider
     *
     * @param mixed $value
     */
    public function testConvertsDelayToMicroseconds($value, int $expected): void
    {
        self::assertSame($expected, Utils::delayToMicroseconds($value));
    }

    public static function validDelayProvider(): array
    {
        return [
            'zero int' => [0, 0],
            'zero float' => [0.0, 0],
            'one millisecond' => [1, 1000],
            'fractional millisecond' => [1.5, 1500],
        ];
    }

    /**
     * @dataProvider invalidDelayProvider
     *
     * @param mixed $value
     */
    public function testRejectsInvalidDelayValues($value, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        Utils::delayToMicroseconds($value);
    }

    public static function invalidDelayProvider(): array
    {
        return [
            'not a number' => ['1', 'delay must be a number'],
            'positive infinity' => [\INF, 'delay must be finite'],
            'negative infinity' => [-\INF, 'delay must be finite'],
            'not a number float' => [\NAN, 'delay must be finite'],
            'negative' => [-1, 'delay must be greater than or equal to 0'],
            'huge finite float' => [1.0e100, 'delay is too large'],
        ];
    }

    public function testRejectsRoundedDelayFloatAtIntegerBoundaryOnSixtyFourBit(): void
    {
        if (\PHP_INT_SIZE !== 8) {
            self::markTestSkipped('The rounded delay boundary only applies on 64-bit platforms.');
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('delay is too large');

        Utils::delayToMicroseconds(\PHP_INT_MAX / 1000);
    }

    /**
     * @dataProvider validTimeoutProvider
     *
     * @param mixed $value
     */
    public function testConvertsTimeoutToMilliseconds($value, int $expected): void
    {
        self::assertSame($expected, Utils::timeoutToMilliseconds($value, 'timeout'));
    }

    public static function validTimeoutProvider(): array
    {
        return [
            'zero int' => [0, 0],
            'zero float' => [0.0, 0],
            'numeric string' => ['0.001', 1],
            'truncated fractional millisecond' => [0.0015, 1],
        ];
    }

    /**
     * @dataProvider invalidTimeoutProvider
     *
     * @param mixed $value
     */
    public function testRejectsInvalidTimeoutValues($value, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        Utils::timeoutToMilliseconds($value, 'timeout');
    }

    public static function invalidTimeoutProvider(): array
    {
        return [
            'not a number' => ['foo', 'timeout must be a number of seconds'],
            'positive infinity' => [\INF, 'timeout must be 0 or greater than or equal to 0.001 seconds'],
            'negative infinity' => [-\INF, 'timeout must be 0 or greater than or equal to 0.001 seconds'],
            'not a number float' => [\NAN, 'timeout must be 0 or greater than or equal to 0.001 seconds'],
            'negative' => [-1, 'timeout must be 0 or greater than or equal to 0.001 seconds'],
            'below one millisecond' => [0.0001, 'timeout must be 0 or greater than or equal to 0.001 seconds'],
            'huge finite float' => [1.0e100, 'timeout must be 0 or greater than or equal to 0.001 seconds'],
        ];
    }

    public function testRejectsRoundedTimeoutFloatAtIntegerBoundaryOnSixtyFourBit(): void
    {
        if (\PHP_INT_SIZE !== 8) {
            self::markTestSkipped('The rounded timeout boundary only applies on 64-bit platforms.');
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('timeout must be 0 or greater than or equal to 0.001 seconds');

        Utils::timeoutToMilliseconds(\PHP_INT_MAX / 1000, 'timeout');
    }

    private static function skipIfDefaultCurlHandlerIsUnavailable(): void
    {
        if (
            !\function_exists('curl_share_init')
            || !\function_exists('curl_share_setopt')
            || !\function_exists('curl_exec')
            || !CurlVersion::supportsTls12()
        ) {
            self::markTestSkipped('Default cURL handler with share handles is unavailable.');
        }
    }
}

final class StrClass
{
    public function __toString(): string
    {
        return 'foo';
    }
}
