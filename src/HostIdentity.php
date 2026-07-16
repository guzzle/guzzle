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

    private function __construct()
    {
        // cannot be instantiated
    }
}
