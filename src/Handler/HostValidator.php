<?php

declare(strict_types=1);

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
     * A handler reparses the URI but sends the Host header as given. The URI
     * host must therefore be printable ASCII, free of percent escapes, a valid
     * RFC 3986 host, and not numeric-looking parts followed by trailing dots.
     * The string form of the URI must be free of control characters and name
     * the scheme, host, and port that its components report. The request must
     * have at most one Host header value, and every value must be printable
     * ASCII.
     *
     * @throws RequestException
     */
    public static function assertRequestHost(
        #[\SensitiveParameter]
        RequestInterface $request
    ): void {
        $uri = $request->getUri();

        self::assertUriHost($uri->getHost(), $request);
        self::assertUriString($uri, $request);

        $hostValues = $request->getHeader('Host');
        if (\count($hostValues) > 1) {
            throw new RequestException('The request must contain at most one Host header value.', $request);
        }

        foreach ($hostValues as $value) {
            self::assertPrintableAscii((string) $value, 'The request Host header "%s" must contain only printable ASCII characters, because an intermediary or an origin server can otherwise read it as an authority that differs from the one the request names. An internationalized host name has an A-label form that this rule accepts.', $request);
        }
    }

    /**
     * Only the URI host is reparsed for the connection. A Host header is sent
     * verbatim and may carry a port, brackets, and an RFC 6874 zone identifier.
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

        // GuzzleHttp\Psr7\Uri already enforces this predicate, so it only
        // rejects invalid third-party UriInterface values.
        if (!Psr7\Rfc3986::isValidHost($host)) {
            throw new RequestException(\sprintf('The request URI host "%s" must be a valid RFC 3986 host, because a handler reparses the URI and can then connect to a host that differs from the one the request names.', self::escape($host)), $request);
        }

        // libcurl 8.21.0 drops a trailing dot from inet_aton-style numeric
        // hosts before connecting, while other readers treat the input as a
        // name. Test the shape rather than the range so malformed numeric
        // values fail closed. rtrim() also covers multiple trailing dots if
        // libcurl later relaxes its current guard. Plain shorthand stays
        // accepted. HostIdentity uses a broader grammar for cookie matching.
        //
        // @see \GuzzleHttp\HostIdentity::canonicalHost()
        if (\str_ends_with($host, '.') && self::isNumericIpv4Host(\rtrim($host, '.'))) {
            throw new RequestException(\sprintf('The request URI host "%s" must not be written as one to four decimal, octal or hexadecimal parts followed by one or more trailing dots, because a handler can read that spelling as an IPv4 address and connect to that address while the rest of the process reads a name.', self::escape($host)), $request);
        }
    }

    /**
     * A handler connects to the string form of the URI, while the rest of the
     * process reads its components. GuzzleHttp\Psr7\Uri builds an encoded
     * string form from its components, so only other UriInterface values are
     * checked.
     *
     * @throws RequestException
     */
    private static function assertUriString(
        #[\SensitiveParameter]
        UriInterface $uri,
        #[\SensitiveParameter]
        RequestInterface $request
    ): void {
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
     * Reports whether a host has libcurl's inet_aton-style shape: one to four
     * decimal, 0-prefixed octal, or 0x-prefixed hexadecimal parts.
     *
     * Range and 32-bit overflow checks are deliberately omitted. This may
     * reject a trailing-dot spelling the transport reads as a name, but avoids
     * missing one it resolves as an address.
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
     * @throws RequestException
     */
    private static function assertPrintableAscii(
        string $value,
        string $message,
        #[\SensitiveParameter]
        RequestInterface $request
    ): void {
        // Match positively so a PCRE failure rejects.
        if (\preg_match('/\A[\x21-\x7E]*\z/D', $value) !== 1) {
            throw new RequestException(\sprintf($message, self::escape($value)), $request);
        }
    }

    /**
     * Escapes non-printable bytes as uppercase \xNN for safe diagnostics.
     *
     * Psr7\DiagnosticValue is not used because it preserves valid non-ASCII
     * UTF-8, including invisible characters rejected here.
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
