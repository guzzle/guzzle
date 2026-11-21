<?php

declare(strict_types=1);

namespace GuzzleHttp;

use GuzzleHttp\Exception\InvalidArgumentException;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\UriInterface;

/**
 * @internal
 */
final class Idn
{
    private function __construct()
    {
    }

    public static function normalizeConversionOption(mixed $value): int|bool
    {
        if ($value === null) {
            return false;
        }

        if (!\is_bool($value) && !\is_int($value)) {
            throw new InvalidArgumentException('idn_conversion must be true, false, null, or an integer IDNA_* bitmask');
        }

        if ($value !== false && (!\function_exists('idn_to_ascii') || !\defined('INTL_IDNA_VARIANT_UTS46'))) {
            throw new InvalidArgumentException('idn_conversion requires the intl extension');
        }

        return $value;
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function createUri(
        UriFactoryInterface $uriFactory,
        #[\SensitiveParameter]
        string $uri,
        int|bool $options
    ): UriInterface {
        if ($options === false || !$uriFactory instanceof Psr7\HttpFactory) {
            return self::convertUri($uriFactory->createUri($uri), $options);
        }

        try {
            return Psr7\Idna::uriFor($uri, $options === true ? null : $options);
        } catch (\InvalidArgumentException $e) {
            throw new InvalidArgumentException($e->getMessage(), 0, $e);
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function convertUri(UriInterface $uri, int|bool $options): UriInterface
    {
        if ($options === false) {
            return $uri;
        }

        $host = $uri->getHost();

        try {
            $asciiHost = Psr7\Idna::toAscii($host, $options === true ? null : $options);

            return $asciiHost === $host ? $uri : $uri->withHost($asciiHost);
        } catch (\InvalidArgumentException $e) {
            throw new InvalidArgumentException($e->getMessage(), 0, $e);
        }
    }
}
