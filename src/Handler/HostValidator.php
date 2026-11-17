<?php

namespace GuzzleHttp\Handler;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;

/**
 * Rejects request hosts that a handler could resolve differently from the host
 * the request names.
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
     * The URI host and every Host header value must use printable ASCII without
     * percent escapes. The URI host must also exclude authority delimiters and
     * numeric-looking parts followed by trailing dots. The string form of the
     * URI must be free of control characters and name the scheme, host, and
     * port that its components report. Handlers reparse the URI but send the
     * Host header as given, so ambiguous spellings can name different
     * connection and request hosts.
     *
     * @throws RequestException
     */
    public static function assertRequestHost(RequestInterface $request): void
    {
        $uri = $request->getUri();
        $host = $uri->getHost();

        self::assertUriHostValue($host, $request);
        self::assertNoAuthorityDelimiter($host, $request);
        self::assertNotADottedAddress($host, $request);
        self::assertUriString($uri, $request);

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
     * The Host header is sent rather than reparsed for the connection, so its
     * diagnostics describe a request authority the caller did not write.
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
     * Matches the accepted shape positively so a PCRE failure rejects.
     */
    private static function isPrintableAscii(string $value): bool
    {
        return \preg_match('/\A[\x21-\x7E]*\z/D', $value) === 1;
    }

    /**
     * Rejects a delimiter the transport could treat as the end of the URI host.
     *
     * This mirrors GuzzleHttp\Psr7\Uri::assertValidHost(), but brackets must
     * enclose an IP literal. Host headers may carry a port and are sent
     * verbatim.
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
            if (!self::isIpLiteral($host)) {
                throw new RequestException(\sprintf($message, self::escape($host)), $request);
            }

            return;
        }

        if (\strpos($host, ':') !== false) {
            throw new RequestException(\sprintf($message, self::escape($host)), $request);
        }
    }

    /**
     * Reports whether a host is a bracketed IPv6 or IPvFuture literal.
     */
    private static function isIpLiteral(string $host): bool
    {
        if (\strpos($host, '[') !== 0 || \substr($host, -1) !== ']') {
            return false;
        }

        $address = (string) \substr($host, 1, -1);

        if (\filter_var($address, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6) !== false) {
            return true;
        }

        return \preg_match('/\Av[0-9a-f]+\.[A-Za-z0-9\-._~!$&\'()*+,;=:]+\z/iD', $address) === 1;
    }

    /**
     * Rejects one to four numeric-looking parts followed by trailing dots.
     *
     * libcurl 8.21.0 drops a trailing dot from inet_aton-style numeric hosts
     * before connecting, while other validators treat the input as a name.
     * Testing the shape also rejects some out-of-range values that transports
     * keep as names; isNumericIpv4Host() explains that fail-closed tradeoff.
     * Plain numeric shorthand stays accepted.
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
     * A handler connects to the string form of the URI, while the rest of the
     * process reads its components. GuzzleHttp\Psr7\Uri builds an encoded
     * string form from its components, so only other UriInterface values are
     * checked.
     *
     * @throws RequestException
     */
    private static function assertUriString(UriInterface $uri, RequestInterface $request): void
    {
        $host = $uri->getHost();

        if ($host === '' || \get_class($uri) === Psr7\Uri::class) {
            return;
        }

        $string = (string) $uri;

        if (\preg_match('/\A[^\x00-\x1F\x7F]*\z/D', $string) !== 1) {
            throw new RequestException('The request URI must have a string form free of control characters, because a handler writes the string form into the request line and can otherwise send a request line that differs from the one the request names.', $request);
        }

        if (!self::namesOrigin($string, $uri->getScheme(), $host, $uri->getPort())) {
            throw new RequestException('The request URI must have a string form with the scheme, host and port that its components report, because a handler connects to the string form and can otherwise connect to an origin that differs from the one the request names.', $request);
        }
    }

    /**
     * Reports whether a URI string starts with the given scheme, host, and
     * port, written as given apart from host letter case and the default port.
     */
    private static function namesOrigin(string $string, string $scheme, string $host, ?int $port): bool
    {
        $prefix = ($scheme === '' ? '' : $scheme.':').'//';
        $start = \strlen($prefix);

        if (\strncmp($string, $prefix, $start) !== 0) {
            return false;
        }

        // PHP reads the host after the last "@", older libcurl after the first.
        $authority = \substr($string, $start, \strcspn($string, '/?#', $start));
        $at = \strpos($authority, '@');
        if ($at !== false) {
            $authority = \substr($authority, $at + 1);
        }

        if (!Psr7\Utils::caselessEquals(\substr($authority, 0, \strlen($host)), $host)) {
            return false;
        }

        $written = \substr($authority, \strlen($host));
        $default = ['http' => 80, 'https' => 443][$scheme] ?? null;

        if ($written === '' || $written === ':') {
            return $port === null || $port === $default;
        }

        return $written[0] === ':'
            && \strspn($written, '0123456789', 1) === \strlen($written) - 1
            && (int) \substr($written, 1) === ($port ?? $default);
    }

    /**
     * Reports whether a value has the transport's inet_aton-style shape: one
     * to four decimal, 0-prefixed octal, or 0x-prefixed hexadecimal parts.
     *
     * Range and 32-bit overflow checks are deliberately omitted. This may
     * reject a trailing-dot spelling the transport reads as a name, but avoids
     * missing one it resolves as an address. No PCRE is used.
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
            return \strlen($part) > 2 && \strspn($part, '0123456789abcdefABCDEF', 2) === \strlen($part) - 2;
        }

        $digits = $part[0] === '0' ? '01234567' : '0123456789';

        return \strspn($part, $digits) === \strlen($part);
    }

    /**
     * Escapes non-printable bytes as uppercase \xNN for safe diagnostics.
     * Printable delimiters and dots stay visible. The result is not a
     * reversible encoding.
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
