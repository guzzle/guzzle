<?php

declare(strict_types=1);

namespace GuzzleHttp\Handler;

use GuzzleHttp\Psr7;

/**
 * @internal
 */
final class DebugResource
{
    private function __construct()
    {
    }

    /**
     * Returns a debug stream based on the provided variable.
     *
     * @param mixed $value Optional value
     *
     * @return resource
     */
    public static function from($value = null)
    {
        if (\is_resource($value)) {
            return $value;
        }
        if (\defined('STDOUT')) {
            return \STDOUT;
        }

        return Psr7\Utils::tryFopen('php://output', 'w');
    }
}
