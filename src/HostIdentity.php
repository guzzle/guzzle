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

    private function __construct()
    {
        // cannot be instantiated
    }
}
