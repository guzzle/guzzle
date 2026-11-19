<?php

declare(strict_types=1);

namespace GuzzleHttp\Handler;

use GuzzleHttp\Exception\InvalidArgumentException;

/**
 * @internal
 */
final class Protocols
{
    private function __construct()
    {
    }

    /**
     * @return string[]
     *
     * @throws InvalidArgumentException
     */
    public static function normalize(mixed $protocols): array
    {
        if (!\is_array($protocols) || $protocols === []) {
            throw new InvalidArgumentException('protocols must be a non-empty array of "http" and/or "https"');
        }

        $normalized = [];

        foreach ($protocols as $protocol) {
            if (!\is_string($protocol)) {
                throw new InvalidArgumentException('protocols must contain only strings');
            }

            if ($protocol !== 'http' && $protocol !== 'https') {
                throw new InvalidArgumentException('protocols may only contain "http" and "https"');
            }

            $normalized[$protocol] = true;
        }

        return \array_keys($normalized);
    }
}
