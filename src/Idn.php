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

    public static function convertUriString(
        #[\SensitiveParameter]
        string $uri,
        int $options
    ): string {
        // Replace only the host of an authority or of a leading "host:port".
        if (\preg_match('~\A(?!(?:[^:/?#]+:)?(?://)?[^/?#]*\\\\)((?:[^:/?#]+:)?//(?:[^/?#]*@)?+|(?:[^/?#]*[@:])?(?=[^/?#@:]*:[0-9]+(?:/|\z)))(?![^/?#@:]*%[89A-Fa-f][0-9A-Fa-f])(?=[^/?#@:]*?[\x80-\xFF])([^/?#@:]*+)(?:[:/?#]|\z)~', $uri, $matches) !== 1) {
            return $uri;
        }

        [, $start, $host] = $matches;

        try {
            $asciiHost = Psr7\Idna::toAscii($host, $options);
            $converted = $start.$asciiHost.\substr($uri, \strlen($start.$host));

            // The expression is not a parser, so psr7 must find the same host.
            return (new Psr7\Uri($converted))->getHost() === $asciiHost ? $converted : $uri;
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $uri;
        }
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
                // Replace URI only if the ASCII version is different
                try {
                    $uri = $uri->withHost($asciiHost);
                } catch (\InvalidArgumentException $e) {
                    // The conversion can map a character to a URI delimiter.
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
