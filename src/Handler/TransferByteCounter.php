<?php

declare(strict_types=1);

namespace GuzzleHttp\Handler;

/**
 * @internal
 */
final class TransferByteCounter
{
    private function __construct()
    {
    }

    /**
     * @param mixed $value
     */
    public static function progressValueToInt($value): int
    {
        if (\is_int($value)) {
            if ($value < 0) {
                throw new \OverflowException('Progress byte count exceeds the maximum integer size supported on this platform');
            }

            return $value;
        }

        if (!\is_float($value)) {
            throw new \UnexpectedValueException('Progress byte count must be an integer or float');
        }

        if (
            !\is_finite($value)
            || $value < 0
            || $value > \PHP_INT_MAX
            || (\PHP_INT_SIZE === 8 && $value >= (float) \PHP_INT_MAX)
        ) {
            throw new \OverflowException('Progress byte count exceeds the maximum integer size supported on this platform');
        }

        $intValue = (int) $value;
        if ($intValue < 0) {
            throw new \OverflowException('Progress byte count exceeds the maximum integer size supported on this platform');
        }

        return $intValue;
    }

    public static function add(int $current, int $delta, string $message): int
    {
        if ($current < 0 || $delta < 0) {
            throw new \OverflowException($message);
        }

        if ($delta > \PHP_INT_MAX - $current) {
            throw new \OverflowException($message);
        }

        return $current + $delta;
    }
}
