<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests\Handler;

use GuzzleHttp\Handler\TransferByteCounter;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GuzzleHttp\Handler\TransferByteCounter
 */
class TransferByteCounterTest extends TestCase
{
    /**
     * @dataProvider validProgressValueProvider
     *
     * @param mixed $value
     */
    public function testProgressValueNarrowsToInt($value, int $expected): void
    {
        self::assertSame($expected, TransferByteCounter::progressValueToInt($value));
    }

    public static function validProgressValueProvider(): array
    {
        return [
            'zero int' => [0, 0],
            'small int' => [42, 42],
            'int max' => [\PHP_INT_MAX, \PHP_INT_MAX],
            'zero float' => [0.0, 0],
            'small float' => [10.0, 10],
            'megabyte float' => [1048576.0, 1048576],
        ];
    }

    /**
     * @dataProvider overflowingProgressValueProvider
     *
     * @param mixed $value
     */
    public function testProgressValueRejectsOutOfRangeValues($value): void
    {
        $this->expectException(\OverflowException::class);
        $this->expectExceptionMessage('Progress byte count exceeds the maximum integer size supported on this platform');

        TransferByteCounter::progressValueToInt($value);
    }

    public static function overflowingProgressValueProvider(): array
    {
        return [
            'negative int' => [-1],
            'int min' => [\PHP_INT_MIN],
            'negative float' => [-1.0],
            'positive infinity' => [\INF],
            'negative infinity' => [-\INF],
            'not a number' => [\NAN],
            'finite above max' => [1.0e19],
        ];
    }

    /**
     * @dataProvider nonNumericProgressValueProvider
     *
     * @param mixed $value
     */
    public function testProgressValueRejectsNonNumericValues($value): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Progress byte count must be an integer or float');

        TransferByteCounter::progressValueToInt($value);
    }

    public static function nonNumericProgressValueProvider(): array
    {
        return [
            'numeric string' => ['5'],
            'empty string' => [''],
            'null' => [null],
            'array' => [[]],
            'true' => [true],
            'false' => [false],
            'object' => [new \stdClass()],
        ];
    }

    public function testProgressFloatAtIntMaxBoundaryIsRejectedOnSixtyFourBit(): void
    {
        if (\PHP_INT_SIZE !== 8) {
            self::markTestSkipped('The rounded PHP_INT_MAX float guard only triggers on 64-bit platforms.');
        }

        // (float) PHP_INT_MAX rounds up to 2**63, which is not greater than
        // PHP_INT_MAX after float promotion, so reject it before casting.
        $this->expectException(\OverflowException::class);
        $this->expectExceptionMessage('Progress byte count exceeds the maximum integer size supported on this platform');

        TransferByteCounter::progressValueToInt((float) \PHP_INT_MAX);
    }

    /**
     * @dataProvider addableByteCountProvider
     */
    public function testAddReturnsSum(int $current, int $delta, int $expected): void
    {
        self::assertSame($expected, TransferByteCounter::add($current, $delta, 'unused'));
    }

    public static function addableByteCountProvider(): array
    {
        return [
            'zero plus zero' => [0, 0, 0],
            'zero plus delta' => [0, 5, 5],
            'mid range' => [5, 7, 12],
            'reaches int max' => [\PHP_INT_MAX - 1, 1, \PHP_INT_MAX],
        ];
    }

    /**
     * @dataProvider overflowingByteCountProvider
     */
    public function testAddRejectsOverflowAndNegativeOperands(int $current, int $delta): void
    {
        $this->expectException(\OverflowException::class);
        $this->expectExceptionMessage('byte ceiling reached');

        TransferByteCounter::add($current, $delta, 'byte ceiling reached');
    }

    public static function overflowingByteCountProvider(): array
    {
        return [
            'one past max' => [\PHP_INT_MAX, 1],
            'two past max minus one' => [\PHP_INT_MAX - 1, 2],
            'max plus max' => [\PHP_INT_MAX, \PHP_INT_MAX],
            'negative current' => [-1, 0],
            'negative delta' => [0, -1],
            'both negative' => [-1, -1],
        ];
    }
}
