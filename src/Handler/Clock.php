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
     * Wrapper for the hrtime() or microtime() functions
     * (depending on the PHP version, one of the two is used)
     *
     * @return float UNIX timestamp
     */
    public static function now(): float
    {
        return (float) \function_exists('hrtime') ? \hrtime(true) / 1e9 : \microtime(true);
    }
}
