<?php

declare(strict_types=1);

namespace GuzzleHttp\Handler;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7;
use Psr\Http\Message\RequestInterface;

/**
 * Rejects request hosts that a handler could resolve to a host other than the
 * one the request names.
 *
 * @internal
 */
final class HostValidator
{
    private function __construct()
    {
    }

    /**
     * Asserts that a request names one unambiguous network host.
     *
     * A handler passes the request URI to a transport that reparses it,
     * percent-decodes the host, and, when built with IDN support, applies IDNA
     * mapping to it, while the Host header is sent exactly as given. Any
     * spelling those transformations change can name one host on the
     * connection and a different one in the request, so the URI host must be
     * printable ASCII, free of percent escapes, a valid RFC 3986 host, and not
     * one to four numeric-looking parts written with one or more trailing dots,
     * and every Host header value must be printable ASCII.
     *
     * @throws RequestException
     */
    public static function assertRequestHost(
        #[\SensitiveParameter]
        RequestInterface $request
    ): void {
        self::assertUriHost($request->getUri()->getHost(), $request);

        foreach ($request->getHeader('Host') as $value) {
            self::assertPrintableAscii((string) $value, 'The request Host header "%s" must contain only printable ASCII characters, because an intermediary or an origin server can otherwise read it as an authority that differs from the one the request names. An internationalized host name has an A-label form that this rule accepts.', $request);
        }
    }

    /**
     * The percent, RFC 3986, and trailing-dot rules apply to the URI host
     * only. A transport reparses the URI and derives the connection target
     * from it, while a Host header is sent verbatim and legitimately carries a
     * port, brackets, and an RFC 6874 zone identifier.
     *
     * @throws RequestException
     */
    private static function assertUriHost(
        string $host,
        #[\SensitiveParameter]
        RequestInterface $request
    ): void {
        self::assertPrintableAscii($host, 'The request URI host "%s" must contain only printable ASCII characters, because a handler can otherwise connect to a host that differs from the one the request names. An internationalized host name has an A-label form that this rule accepts.', $request);

        if (\strpos($host, '%') !== false) {
            throw new RequestException(\sprintf('The request URI host "%s" must not contain a percent escape, because a handler decodes it and can then connect to a host that differs from the one the request names.', self::escape($host)), $request);
        }

        // The predicate GuzzleHttp\Psr7\Uri already enforces, so this rejects
        // nothing a Guzzle URI can hold and everything a third-party
        // UriInterface can smuggle past it: an authority delimiter, a bare
        // port colon, or a bracketed value that is not an IP literal.
        if (!Psr7\Rfc3986::isValidHost($host)) {
            throw new RequestException(\sprintf('The request URI host "%s" must be a valid RFC 3986 host, because a handler reparses the URI and can then connect to a host that differs from the one the request names.', self::escape($host)), $request);
        }

        // A transport that reads the host as a numeric IPv4 address discards
        // the trailing dot and connects to the address, while every other
        // reader of the same value, this process included, has a name. The rule
        // is written on the shape rather than on the value, so it also refuses
        // the trailing-dot form of a numeric-looking name a transport keeps as
        // a name; isNumericIpv4Host() states that tradeoff. The plain numeric
        // spellings stay accepted; only the trailing-dot forms are
        // rejected. rtrim() strips every trailing dot rather than one, so
        // 127.0.0.1.. is rejected too: libcurl 8.21.0 refuses that spelling in
        // hostname_check(), a guard added in the same release as the fold it
        // constrains, so the rule stays closed if a later release drops it.
        // This is deliberately not HostIdentity's numeric-host grammar, which
        // classifies on the rightmost label alone because cookie suffix
        // matching must over-approximate.
        //
        // @see \GuzzleHttp\HostIdentity::canonicalHost()
        if (\str_ends_with($host, '.') && self::isNumericIpv4Host(\rtrim($host, '.'))) {
            throw new RequestException(\sprintf('The request URI host "%s" must not be written as one to four decimal, octal or hexadecimal parts followed by one or more trailing dots, because a handler can read that spelling as an IPv4 address and connect to that address while the rest of the process reads a name.', self::escape($host)), $request);
        }
    }

    /**
     * Reports whether a host is written the way a transport spells a numeric
     * IPv4 address rather than a name: one to four dot-separated parts, each
     * written in decimal, in octal with a 0 prefix, or in hexadecimal with a
     * 0x prefix. That is the inet_aton() shorthand, and it is the grammar
     * libcurl implements in ipv4_normalize().
     *
     * Deliberately without the per-part range and 32-bit overflow checks the
     * transport also applies. A transport calls a spelling whose part is too
     * large for its position, or too large for 32 bits, a name, so this accepts
     * a little more than one folds, such as 127.0.0.256. and 0x100000000. The
     * only effect is that the trailing-dot form of an out-of-range spelling is
     * rejected rather than accepted, which is the safe direction and keeps the
     * rule independent of any backend's per-position bit widths.
     */
    private static function isNumericIpv4Host(string $host): bool
    {
        if ($host === '') {
            return false;
        }

        $parts = \explode('.', $host);

        if (\count($parts) > 4) {
            return false;
        }

        foreach ($parts as $part) {
            if (!self::isNumericIpv4Part($part)) {
                return false;
            }
        }

        return true;
    }

    private static function isNumericIpv4Part(string $part): bool
    {
        if ($part === '') {
            return false;
        }

        if ($part[0] === '0' && isset($part[1]) && ($part[1] === 'x' || $part[1] === 'X')) {
            return \strlen($part) > 2
                && \strspn($part, '0123456789abcdefABCDEF', 2) === \strlen($part) - 2;
        }

        $digits = $part[0] === '0' ? '01234567' : '0123456789';

        return \strspn($part, $digits) === \strlen($part);
    }

    /**
     * @throws RequestException
     */
    private static function assertPrintableAscii(
        string $value,
        string $message,
        #[\SensitiveParameter]
        RequestInterface $request
    ): void {
        // Matching the accepted shape positively keeps a PCRE engine failure
        // failing closed.
        if (\preg_match('/\A[\x21-\x7E]*\z/D', $value) !== 1) {
            throw new RequestException(\sprintf($message, self::escape($value)), $request);
        }
    }

    /**
     * Renders a rejected host for diagnostics, escaping every byte outside
     * printable ASCII as an uppercase \xNN sequence so an invisible or control
     * byte cannot reach a message. The result is diagnostic text, not a
     * reversible encoding.
     *
     * This is Psr7\DiagnosticValue's bytewise fallback applied unconditionally.
     * Psr7\DiagnosticValue::escape() is deliberately not used: it leaves valid
     * non-ASCII UTF-8 unchanged, and the values rejected here are routinely
     * invisible characters whose bytes are the whole point of the diagnostic.
     */
    private static function escape(string $value): string
    {
        $escaped = '';

        for ($offset = 0, $length = \strlen($value); $offset < $length; ++$offset) {
            $byte = \ord($value[$offset]);
            if ($byte >= 0x20 && $byte <= 0x7E) {
                $escaped .= $value[$offset];

                continue;
            }

            $escaped .= \sprintf('\\x%02X', $byte);
        }

        return $escaped;
    }
}
