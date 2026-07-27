<?php

declare(strict_types=1);

namespace GuzzleHttp;

/**
 * Shared host identity normalization for features that key state on a
 * logical host.
 *
 * @internal
 */
final class HostIdentity
{
    /**
     * Returns the identity form of a URI host. Valid bracketed IPv6 literals
     * are canonicalized to their RFC 5952 form so equivalent spellings of one
     * address share a single identity; IPvFuture literals, zone-bearing
     * values, and invalid bracketed text fall back to ASCII case folding.
     */
    public static function canonicalHost(string $host): string
    {
        if (\str_starts_with($host, '[') && \str_ends_with($host, ']')) {
            try {
                return '['.Psr7\Rfc3986::canonicalizeIpv6(\substr($host, 1, -1)).']';
            } catch (\InvalidArgumentException $e) {
                // Fall back to case folding below.
            }
        }

        return Psr7\Utils::asciiToLower($host);
    }

    /**
     * Returns the identity form of a cookie domain. In addition to the
     * canonicalHost() rules, valid bare IPv6 addresses, which the cookie API
     * permissively accepts even though a URI host requires brackets, are
     * canonicalized to their RFC 5952 form without gaining brackets, so bare
     * and bracketed forms remain distinct identities.
     */
    public static function canonicalCookieDomain(string $domain): string
    {
        if (!\str_starts_with($domain, '[') && \strpos($domain, ':') !== false) {
            try {
                return Psr7\Rfc3986::canonicalizeIpv6($domain);
            } catch (\InvalidArgumentException $e) {
                return Psr7\Utils::asciiToLower($domain);
            }
        }

        return self::canonicalHost($domain);
    }

    /**
     * Whether a request domain matches a cookie domain according to RFC 6265.
     *
     * @see https://datatracker.ietf.org/doc/html/rfc6265#section-5.1.3
     */
    public static function cookieDomainMatches(string $domain, string $cookieDomain): bool
    {
        $domain = self::canonicalCookieDomain($domain);
        $cookieDomain = self::canonicalCookieDomain($cookieDomain);

        if ($domain === $cookieDomain) {
            return true;
        }

        // A percent-escaped cookie domain can decode to another host spelling.
        // Keep it exact-match-only; decoding a request host cannot create a
        // suffix match that its original text lacked.
        if (\strpos($cookieDomain, '%') !== false) {
            return false;
        }

        if (!self::isDnsSuffixEligible($domain) || !self::isDnsSuffixEligible($cookieDomain)) {
            return false;
        }

        return \preg_match('/\.'.\preg_quote($cookieDomain, '/').'$/D', $domain) === 1;
    }

    /**
     * Returns the identity form of a Host header value. A single bracketed
     * `host[:port]` authority, where any explicit port must be a valid decimal
     * port number, has its IPv6 literal canonicalized like canonicalHost() with
     * the port text retained; every other value, including malformed and
     * multiple values, falls back to ASCII case folding of the raw text.
     */
    public static function canonicalHostHeader(string $header): string
    {
        if (\str_starts_with($header, '[')) {
            $end = \strpos($header, ']');
            if ($end !== false) {
                $rest = \substr($header, $end + 1);
                if ($rest === '' || (\str_starts_with($rest, ':') && Psr7\Rfc3986::isValidPort(\substr($rest, 1)))) {
                    return self::canonicalHost(\substr($header, 0, $end + 1)).$rest;
                }
            }
        }

        return Psr7\Utils::asciiToLower($header);
    }

    /**
     * Whether the host may participate in RFC 6265 DNS suffix matching. IP
     * literals, IP addresses, numeric hosts, literal-like text containing a
     * raw bracket or colon, and invalid host text are exact-match-only.
     */
    private static function isDnsSuffixEligible(string $host): bool
    {
        if (\strpbrk($host, '[]:') !== false) {
            return false;
        }

        if (!Psr7\Rfc3986::isValidHost($host)) {
            return false;
        }

        return !self::isIpAddressOrNumericHost($host);
    }

    private static function isIpAddressOrNumericHost(string $host): bool
    {
        // Strip one root dot before detection so trailing-dot numeric hosts
        // still cannot be matched by subdomains.
        if ($host !== '' && \str_ends_with($host, '.')) {
            $host = \substr($host, 0, -1);
        }

        if (\str_starts_with($host, '[') && \str_ends_with($host, ']')) {
            $host = \substr($host, 1, -1);
        }

        if (\filter_var($host, \FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        // Public DNS names do not have an all-numeric rightmost label; treat
        // those private/internal hosts as exact-match-only too.
        $labels = \explode('.', $host);
        $last = (string) \end($labels);

        if ($last !== '' && \ctype_digit($last)) {
            return true;
        }

        // libcurl also parses a 0x-prefixed hexadecimal rightmost label as a
        // numerical IPv4 address, such as 0x7f000001 for 127.0.0.1.
        return \str_starts_with($last, '0x') && \strlen($last) > 2 && \ctype_xdigit(\substr($last, 2));
    }

    /**
     * Returns the four-byte binary form of a host that a transport reads as a
     * numeric IPv4 address, or null when it reads it as a name.
     *
     * The shape test is Handler\HostValidator::isNumericIpv4Host(); this
     * method adds the range checks that predicate omits: every part but the
     * last must fit one octet, and the last must fit the octets the earlier
     * parts left. A trailing root dot is not swallowed, unlike libcurl 8.21.0
     * and later, because assertRequestHost() rejects that spelling first.
     */
    public static function numericIpv4ToBinary(string $host): ?string
    {
        if (!Handler\HostValidator::isNumericIpv4Host($host)) {
            return null;
        }

        $values = [];
        foreach (\explode('.', $host) as $part) {
            $values[] = self::numericIpv4PartValue($part);
        }

        // Every accepted value is a whole number no larger than 0xFFFFFFFF,
        // which a float holds exactly, so the arithmetic below is correct on a
        // 32-bit build too, where the widest part overflows an integer.
        $address = (float) \array_pop($values);

        $packed = '';
        foreach ($values as $value) {
            if ($value > 255.0) {
                return null;
            }

            $packed .= \chr((int) $value);
        }

        $width = 4 - \count($values);
        if ($address >= 256.0 ** $width) {
            return null;
        }

        for ($shift = $width - 1; $shift >= 0; --$shift) {
            $packed .= \chr((int) \fmod(\floor($address / 256.0 ** $shift), 256.0));
        }

        return $packed;
    }

    /**
     * Returns the value of one accepted part as a float, so a part filling
     * all four octets such as 2130706433 stays exact on every integer width.
     */
    private static function numericIpv4PartValue(string $part): float
    {
        if ($part[0] === '0' && isset($part[1]) && ($part[1] === 'x' || $part[1] === 'X')) {
            return (float) \hexdec((string) \substr($part, 2));
        }

        if ($part[0] === '0') {
            return (float) \octdec($part);
        }

        return (float) $part;
    }

    private function __construct()
    {
        // cannot be instantiated
    }
}
