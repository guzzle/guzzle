<?php

declare(strict_types=1);

namespace GuzzleHttp\Handler;

use GuzzleHttp\Exception\InvalidArgumentException;

/**
 * @internal
 */
final class TlsVersion
{
    /**
     * @param mixed $value
     */
    public static function ordinal(string $option, $value): int
    {
        if ($value === \STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT) {
            return 10;
        }
        if ($value === \STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT) {
            return 11;
        }
        if ($value === \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT) {
            return 12;
        }
        if ($value === \STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT) {
            return 13;
        }

        throw new InvalidArgumentException(\sprintf('Invalid %s request option: unknown version provided', $option));
    }

    /**
     * @param mixed $min
     * @param mixed $max
     */
    public static function assertRange($min, $max): void
    {
        if ($min === null || $max === null) {
            return;
        }

        if (self::ordinal('crypto_method_max', $max) < self::ordinal('crypto_method', $min)) {
            throw new InvalidArgumentException('Invalid crypto_method_max request option: maximum TLS version must be greater than or equal to crypto_method');
        }
    }

    /**
     * @param mixed $value
     */
    public static function streamProtocolVersion(string $option, $value): int
    {
        if ($value === \STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT) {
            return \STREAM_CRYPTO_PROTO_TLSv1_0;
        }
        if ($value === \STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT) {
            return \STREAM_CRYPTO_PROTO_TLSv1_1;
        }
        if ($value === \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT) {
            return \STREAM_CRYPTO_PROTO_TLSv1_2;
        }
        if ($value === \STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT) {
            return \STREAM_CRYPTO_PROTO_TLSv1_3;
        }

        throw new InvalidArgumentException(\sprintf('Invalid %s request option: unknown version provided', $option));
    }
}
