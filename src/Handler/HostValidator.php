<?php

namespace GuzzleHttp\Handler;

use GuzzleHttp\Exception\RequestException;
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
     * The URI host and every Host header value must consist only of printable
     * ASCII characters and must not contain a percent escape, and the URI host
     * must additionally be free of URI authority delimiters and must not be one
     * to four numeric-looking parts followed by one or more trailing dots.
     * Handlers pass the URI to a transport that reparses it, percent-decodes
     * the host and, when built with IDN support, applies IDNA mapping to it,
     * while the Host header is sent exactly as given, so any other spelling can
     * name one host on the connection and a different one in the request.
     *
     * @throws RequestException
     */
    public static function assertRequestHost(RequestInterface $request): void
    {
        $host = $request->getUri()->getHost();

        self::assertUriHostValue($host, $request);
        self::assertNoAuthorityDelimiter($host, $request);
        self::assertNotADottedAddress($host, $request);

        foreach ($request->getHeader('Host') as $value) {
            self::assertHostHeaderValue((string) $value, $request);
        }
    }

    /**
     * @throws RequestException
     */
    private static function assertUriHostValue(string $value, RequestInterface $request): void
    {
        if (!self::isPrintableAscii($value)) {
            throw new RequestException(\sprintf('The request URI host "%s" must contain only printable ASCII characters, because a handler can otherwise connect to a host that differs from the one the request names. An internationalized host name has an A-label form that this rule accepts.', self::escape($value)), $request);
        }

        if (\strpos($value, '%') !== false) {
            throw new RequestException(\sprintf('The request URI host "%s" must not contain a percent escape, because a handler can decode it and then connect to a host that differs from the one the request names.', self::escape($value)), $request);
        }
    }

    /**
     * The Host header is not reparsed for the connection, so its diagnostics
     * name the consequence it actually has: the request as received states an
     * authority the caller did not write.
     *
     * @throws RequestException
     */
    private static function assertHostHeaderValue(string $value, RequestInterface $request): void
    {
        if (!self::isPrintableAscii($value)) {
            throw new RequestException(\sprintf('The request Host header "%s" must contain only printable ASCII characters, because an intermediary or an origin server can otherwise read it as an authority that differs from the one the request names. An internationalized host name has an A-label form that this rule accepts.', self::escape($value)), $request);
        }

        if (\strpos($value, '%') !== false) {
            throw new RequestException(\sprintf('The request Host header "%s" must not contain a percent escape, because an intermediary or an origin server can decode it and then read it as an authority that differs from the one the request names.', self::escape($value)), $request);
        }
    }

    /**
     * Matches the accepted shape positively, so a PCRE engine failure, which
     * returns false rather than 1, reports "not printable ASCII" and the
     * request is rejected.
     */
    private static function isPrintableAscii(string $value): bool
    {
        return \preg_match('/\A[\x21-\x7E]*\z/D', $value) === 1;
    }

    /**
     * Rejects a URI host that carries a delimiter the transport's own URI
     * parser would treat as the end of the host.
     *
     * This mirrors GuzzleHttp\Psr7\Uri::assertValidHost(), so it can only ever
     * reject a value produced by a third-party UriInterface implementation.
     * It is not applied to the Host header, which legitimately carries a port
     * and is sent verbatim rather than reparsed.
     *
     * @throws RequestException
     */
    private static function assertNoAuthorityDelimiter(string $host, RequestInterface $request): void
    {
        $message = 'The request URI host "%s" must not contain a URI authority delimiter, because a handler reparses the URI and can then connect to a host that differs from the one the request names.';

        // Match the accepted shape positively so a PCRE engine failure rejects.
        if (\preg_match('/\A[^\/?#@\\\\]*\z/D', $host) !== 1) {
            throw new RequestException(\sprintf($message, self::escape($host)), $request);
        }

        if (\strpos($host, '[') !== false || \strpos($host, ']') !== false) {
            if (\strpos($host, '[') !== 0 || \substr($host, -1) !== ']') {
                throw new RequestException(\sprintf($message, self::escape($host)), $request);
            }

            return;
        }

        if (\strpos($host, ':') !== false) {
            throw new RequestException(\sprintf($message, self::escape($host)), $request);
        }
    }

    /**
     * Rejects one to four numeric-looking parts followed by trailing dots.
     *
     * libcurl 8.21.0 swallows a single dot that follows a numerical address, in
     * every base its own inet_aton-style parse accepts, and then connects to
     * that address. filter_var() rejects all of those spellings as addresses,
     * so a caller that classifies the host before handing it to Guzzle sees an
     * unresolvable name while the transport reaches the address. The rule is
     * written on the shape rather than on the value, so it also refuses the
     * trailing-dot form of a numeric-looking name a transport would keep as a
     * name; isNumericIpv4Host() states that tradeoff. Numeric spellings without
     * a trailing dot are the long-standing inet_aton shorthand and stay
     * accepted; only the dot-folding class is new.
     *
     * @throws RequestException
     */
    private static function assertNotADottedAddress(string $host, RequestInterface $request): void
    {
        if (\substr($host, -1) !== '.') {
            return;
        }

        if (!self::isNumericIpv4Host(\rtrim($host, '.'))) {
            return;
        }

        throw new RequestException(\sprintf('The request URI host "%s" must not be written as one to four decimal, octal or hexadecimal parts followed by one or more trailing dots, because a handler can read that spelling as an IPv4 address and connect to that address while the rest of the process reads a name.', self::escape($host)), $request);
    }

    /**
     * Reports whether a value is written the way a transport's inet_aton-style
     * parse spells an IPv4 address rather than a name: one to four
     * dot-separated parts, each written in decimal, in 0-prefixed octal, or in
     * 0x-prefixed hexadecimal.
     *
     * This deliberately omits the per-part range checks and the 32-bit
     * overflow check the transport also applies, so it can only ever classify
     * more values as numeric than the transport does. That is a tradeoff
     * rather than a free win: the trailing-dot form of an out-of-range spelling
     * such as 256.0.0.1. or 0x100000000. is rejected although a transport
     * reads it as a name. It fails closed, and the reverse would leave the
     * split open. It uses no PCRE, so there is no engine that can fail open in
     * it.
     */
    public static function isNumericIpv4Host(string $host): bool
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
     * Renders a rejected host for diagnostics, escaping every byte outside
     * printable ASCII as an uppercase \xNN sequence so an invisible or control
     * byte cannot reach a message. The escaped range is exactly the range the
     * printable ASCII rule rejects, so a value rejected by that rule always
     * shows the offending byte, and a value rejected by one of the other rules
     * shows its delimiter or its dot literally. The result is diagnostic text,
     * not a reversible encoding.
     */
    private static function escape(string $value): string
    {
        $escaped = '';

        for ($offset = 0, $length = \strlen($value); $offset < $length; ++$offset) {
            $byte = \ord($value[$offset]);
            $escaped .= $byte >= 0x21 && $byte <= 0x7E ? $value[$offset] : \sprintf('\\x%02X', $byte);
        }

        return $escaped;
    }
}
