<?php

declare(strict_types=1);

namespace GuzzleHttp;

use GuzzleHttp\Exception\InvalidArgumentException;
use Psr\Http\Message\UriInterface;

/**
 * @internal
 */
final class Idn
{
    private function __construct()
    {
    }

    /**
     * @param mixed $value
     */
    public static function normalizeConversionOption($value): ?int
    {
        if ($value === null || $value === false) {
            return null;
        }

        if ($value === true) {
            return \IDNA_DEFAULT;
        }

        if (\is_int($value)) {
            return $value;
        }

        throw new InvalidArgumentException('idn_conversion must be true, false, null, or an integer IDNA_* bitmask');
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function convertUri(UriInterface $uri, int $options = 0): UriInterface
    {
        if ($uri->getHost()) {
            $asciiHost = self::idnToAsci($uri->getHost(), $options, $info);
            if ($asciiHost === false) {
                $errorBitSet = $info['errors'] ?? 0;

                $errorConstants = array_filter(array_keys(get_defined_constants()), static function (string $name): bool {
                    return substr($name, 0, 11) === 'IDNA_ERROR_';
                });

                $errors = [];
                foreach ($errorConstants as $errorConstant) {
                    if ($errorBitSet & constant($errorConstant)) {
                        $errors[] = $errorConstant;
                    }
                }

                $errorMessage = 'IDN conversion failed';
                if ($errors) {
                    $errorMessage .= ' (errors: '.implode(', ', $errors).')';
                }

                throw new InvalidArgumentException($errorMessage);
            }
            if ($uri->getHost() !== $asciiHost) {
                // The conversion can map a character to a URI delimiter.
                if (!Psr7\Rfc3986::isValidHost($asciiHost)) {
                    throw new InvalidArgumentException('IDN conversion failed');
                }

                // Replace URI only if the ASCII version is different
                try {
                    $uri = $uri->withHost($asciiHost);
                } catch (\InvalidArgumentException $e) {
                    throw new InvalidArgumentException('IDN conversion failed', 0, $e);
                }
            }
        }

        return $uri;
    }

    /**
     * @return string|false
     */
    private static function idnToAsci(string $domain, int $options, ?array &$info = [])
    {
        if (\function_exists('idn_to_ascii') && \defined('INTL_IDNA_VARIANT_UTS46')) {
            // ICU can take quadratic time, and no valid name needs more bytes.
            if (\strlen($domain) > 4096) {
                return false;
            }

            try {
                return \idn_to_ascii($domain, $options, \INTL_IDNA_VARIANT_UTS46, $info);
            } catch (\IntlException $e) {
                // With intl.use_exceptions, a label too long to encode throws.
                return false;
            }
        }

        throw new \Error('ext-idn or symfony/polyfill-intl-idn not loaded or too old');
    }
}
