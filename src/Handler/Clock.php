<?php

declare(strict_types=1);

namespace GuzzleHttp\Handler;

/**
 * @internal
 */
final class Clock
{
    private function __construct()
    {
    }

    /**
     * Returns the current monotonic clock reading in seconds.
     */
    public static function now(): float
    {
        return \hrtime(true) / 1e9;
    }

    public static function sleep(int $microseconds): void
    {
        while ($microseconds > 0) {
            $slice = \min($microseconds, 0xFFFFFFFF);
            \usleep($slice);
            $microseconds -= $slice;
        }
    }
}
