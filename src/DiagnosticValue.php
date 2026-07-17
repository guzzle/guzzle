<?php

declare(strict_types=1);

namespace GuzzleHttp;

/**
 * Escapes control characters in values used by Guzzle-generated diagnostics.
 *
 * @internal
 */
final class DiagnosticValue
{
    private function __construct()
    {
    }

    public static function escapeControls(string $value): string
    {
        if (\preg_match('//u', $value) !== 1) {
            return self::escapeBytes($value);
        }

        $escaped = \preg_replace_callback(
            '/[\x{0000}-\x{001F}\x{007F}-\x{009F}]/u',
            static function (array $matches): string {
                return \sprintf('\\x%02X', \ord($matches[0][\strlen($matches[0]) - 1]));
            },
            $value
        );

        if ($escaped === null) {
            throw new \RuntimeException('Unable to escape diagnostic controls: '.\preg_last_error_msg());
        }

        return $escaped;
    }

    private static function escapeBytes(string $value): string
    {
        $escaped = '';

        for ($i = 0, $length = \strlen($value); $i < $length; ++$i) {
            $byte = \ord($value[$i]);
            $escaped .= $byte >= 0x20 && $byte <= 0x7E ? $value[$i] : \sprintf('\\x%02X', $byte);
        }

        return $escaped;
    }
}
