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
        if (\is_float($value) && (!\is_finite($value) || $value < 0 || $value > \PHP_INT_MAX)) {
            throw new \OverflowException('Progress byte count exceeds the maximum integer size supported on this platform');
        }

        if (\is_int($value) && $value < 0) {
            throw new \OverflowException('Progress byte count exceeds the maximum integer size supported on this platform');
        }

        return (int) $value;
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
