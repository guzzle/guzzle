<?php

declare(strict_types=1);

namespace GuzzleHttp\Handler;

use GuzzleHttp\Exception\InvalidArgumentException;

/**
 * @internal
 */
final class Timeout
{
    private function __construct()
    {
    }

    /**
     * Converts a request timeout option to integer milliseconds.
     *
     * @param mixed $value
     */
    public static function toMilliseconds($value, string $option): int
    {
        if (!\is_int($value) && !\is_float($value) && (!\is_string($value) || !\is_numeric($value))) {
            throw new InvalidArgumentException($option.' must be a number of seconds');
        }

        $seconds = (float) $value;
        if (!\is_finite($seconds) || $seconds < 0) {
            throw new InvalidArgumentException($option.' must be 0 or greater than or equal to 0.001 seconds');
        }

        $milliseconds = (int) ($seconds * 1000);
        if ($seconds > 0 && $milliseconds === 0) {
            throw new InvalidArgumentException($option.' must be 0 or greater than or equal to 0.001 seconds');
        }

        return $milliseconds;
    }
}
