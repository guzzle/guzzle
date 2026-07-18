<?php

declare(strict_types=1);

namespace GuzzleHttp;

use GuzzleHttp\Exception\InvalidArgumentException;
use GuzzleHttp\Psr7\Rfc3986;
use Psr\Http\Message\UriInterface;

final class ProxyOptions
{
    private function __construct()
    {
    }

    /**
     * Resolve Guzzle's documented proxy request option for a request URI.
     *
     * @param mixed $proxy Proxy option as passed via request transfer options.
     *
     * @throws InvalidArgumentException
     */
    public static function resolve(
        UriInterface $uri,
        #[\SensitiveParameter]
        $proxy
    ): ProxySelection {
        if ($proxy === null) {
            return ProxySelection::none();
        }

        if (!\is_array($proxy)) {
            if (!\is_string($proxy)) {
                throw new InvalidArgumentException('proxy must be a string or array');
            }

            return ProxySelection::proxy($proxy);
        }

        $schemeProxy = $proxy[$uri->getScheme()] ?? null;
        if ($schemeProxy !== null && !\is_string($schemeProxy)) {
            throw new InvalidArgumentException('proxy values must be strings');
        }

        // A matching "no" entry is always a final decision, even when the
        // array selects no proxy for the request scheme. Without this, an
        // option-level bypass could fall through to handler-level environment
        // fallback and route through a proxy the user excluded.
        $noProxy = isset($proxy['no']) ? self::normalizeNoProxy($proxy['no']) : [];
        if ($noProxy !== [] && self::isUriInNoProxy($uri, $noProxy)) {
            return ProxySelection::bypassed();
        }

        if ($schemeProxy === null) {
            return ProxySelection::none();
        }

        return ProxySelection::proxy($schemeProxy);
    }

    /**
     * Validate a proxy URL and return its lowercased scheme.
     *
     * A proxy is an authority ([userinfo@]host[:port]) with an optional scheme;
     * a scheme-less value is an HTTP proxy. The scheme is matched anchored at
     * the start, so leading junk before it is rejected as malformed, and the
     * host and port grammar is delegated to Psr7\Rfc3986. The whole string is
     * validated up front so a malformed proxy fails the same way on every
     * handler, but the original value is what callers pin, so no normalization
     * reaches the wire. The error message never includes the proxy, which may
     * carry credentials.
     *
     * @throws InvalidArgumentException on a malformed proxy URL
     */
    public static function proxyScheme(
        #[\SensitiveParameter]
        string $proxy
    ): string {
        $parts = \explode('://', $proxy, 2);
        if (\count($parts) === 1) {
            $scheme = 'http';
            $authority = $proxy;
        } else {
            [$scheme, $authority] = $parts;
            $scheme = Psr7\Utils::asciiToLower($scheme);
            if ($scheme === '' || !Rfc3986::isValidScheme($scheme)) {
                throw new InvalidArgumentException('Invalid proxy URL.');
            }
        }

        if (!self::isValidProxyAuthority($authority)) {
            throw new InvalidArgumentException('Invalid proxy URL.');
        }

        return $scheme;
    }

    /**
     * Whether the string is a valid proxy authority: [userinfo@]host[:port].
     *
     * A single trailing slash is tolerated; anything else after the authority
     * (a path, query, or fragment) is rejected. Userinfo is not policed here
     * (the proxy handles its own credentials); host and port grammar are
     * delegated to Psr7\Rfc3986, where a zero port is accepted and left for the
     * transport to handle as it did before.
     */
    private static function isValidProxyAuthority(string $authority): bool
    {
        // A single trailing slash is tolerated; once removed, a proxy authority
        // ([userinfo@]host[:port]) cannot contain a path, query, or fragment
        // delimiter anywhere — including one before a later '@'.
        if (\str_ends_with($authority, '/')) {
            $authority = \substr($authority, 0, \strlen($authority) - 1);
        }

        if ($authority === '' || \strpbrk($authority, '/?#') !== false) {
            return false;
        }

        // The host cannot contain '@', so userinfo is everything before the
        // last one. It is not validated here. A value that is only userinfo,
        // with nothing after the last '@', has no host and is rejected.
        $segments = \explode('@', $authority);
        $authority = $segments[\count($segments) - 1];
        if ($authority === '') {
            return false;
        }

        $port = null;
        if (\str_starts_with($authority, '[')) {
            $parts = \explode(']', $authority, 2);
            if (\count($parts) !== 2) {
                return false;
            }

            $host = $parts[0].']';
            $remainder = $parts[1];
            if ($remainder !== '') {
                if (!\str_starts_with($remainder, ':')) {
                    return false;
                }

                [, $port] = \explode(':', $remainder, 2);
            }
        } elseif (\strpos($authority, ':') !== false) {
            [$host, $port] = \explode(':', $authority, 2);
        } else {
            $host = $authority;
        }

        // A dangling "host:" carries no port, matching the previous behavior.
        if ($port === '') {
            $port = null;
        }

        if ($host === '' || !Rfc3986::isValidHost($host)) {
            return false;
        }

        return $port === null || Rfc3986::isValidPort($port);
    }

    /**
     * Normalize a no-proxy list from request options or NO_PROXY.
     *
     * @param mixed $noProxy No-proxy value as passed via request transfer options.
     *
     * @return string[]
     *
     * @throws InvalidArgumentException
     */
    public static function normalizeNoProxy($noProxy): array
    {
        if ($noProxy === null) {
            return [];
        }

        if (\is_string($noProxy)) {
            // Entries may be separated by whitespace as well as commas,
            // matching the no_proxy environment variable conventions.
            $noProxy = \preg_split('/[\s,]+/', $noProxy);

            if ($noProxy === false) {
                throw new \RuntimeException('Unable to split the proxy no list: '.\preg_last_error_msg());
            }
        } elseif (!\is_array($noProxy)) {
            throw new InvalidArgumentException('proxy no list must be null, a string, or an array of strings');
        }

        $result = [];
        foreach ($noProxy as $area) {
            if (!\is_string($area)) {
                throw new InvalidArgumentException('proxy no list must be null, a string, or an array of strings');
            }

            $area = \trim($area, " \n\r\t\0\x0B");
            if ($area !== '') {
                $result[] = $area;
            }
        }

        return $result;
    }

    /**
     * Returns true if the provided URI matches any of the no-proxy areas.
     *
     * @param string[] $noProxy An array of host, host-and-port, or CIDR patterns.
     *
     * @throws InvalidArgumentException
     */
    public static function isUriInNoProxy(UriInterface $uri, array $noProxy): bool
    {
        self::assertNoProxyList($noProxy);

        $target = self::parseNoProxyTarget($uri);
        if ($target === null) {
            return false;
        }

        foreach ($noProxy as $area) {
            $area = \trim($area, " \n\r\t\0\x0B");

            if ($area === '*') {
                return true;
            }

            $rule = self::parseNoProxyRule($area);
            if ($rule !== null && self::noProxyRuleMatches($target, $rule)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns true if the provided host matches any of the no-proxy areas.
     *
     * This method will strip a port from the host if it is present. Domain
     * patterns are matched case-insensitively. Exact IP literal patterns are
     * matched by their normalized binary address.
     *
     * Areas are matched in the following cases:
     * 1. "*" (without quotes) always matches any hosts.
     * 2. An exact domain or IP literal match.
     * 3. A bare domain or a leading-dot domain matches itself and its
     *    subdomains. e.g. 'mit.edu' and '.mit.edu' both match 'mit.edu'
     *    and 'foo.mit.edu'.
     * 4. IP CIDR entries match IP literal hosts. e.g. '192.168.0.0/16' will
     *    match '192.168.1.10' and 'fd00::/8' will match '[fd00::1]'.
     *
     * @param string   $host    Host to check against the patterns.
     * @param string[] $noProxy An array of host or CIDR patterns.
     *
     * @throws InvalidArgumentException
     */
    public static function isHostInNoProxy(string $host, array $noProxy): bool
    {
        if ($host === '') {
            throw new InvalidArgumentException('Empty host provided');
        }

        self::assertNoProxyList($noProxy);

        $target = self::parseNoProxyHostString($host);
        if ($target === null) {
            return false;
        }

        foreach ($noProxy as $area) {
            $area = \trim($area, " \n\r\t\0\x0B");

            if ($area === '*') {
                return true;
            }

            $rule = self::parseNoProxyRule($area);
            if ($rule !== null && self::noProxyRuleMatches($target, $rule)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<array-key, mixed> $noProxy
     *
     * @throws InvalidArgumentException
     */
    private static function assertNoProxyList(array $noProxy): void
    {
        foreach ($noProxy as $area) {
            if (!\is_string($area)) {
                throw new InvalidArgumentException('proxy no list must be null, a string, or an array of strings');
            }
        }
    }

    /**
     * @return array{type: string, value: string, port: int|null}|null
     */
    private static function parseNoProxyTarget(UriInterface $uri): ?array
    {
        $host = $uri->getHost();
        if ($host === '') {
            return null;
        }

        return self::parseNoProxyHost($host, $uri->getPort() ?? self::getDefaultPort($uri->getScheme()));
    }

    /**
     * @return array{type: string, value: string, port: int|null}|null
     */
    private static function parseNoProxyHostString(string $host): ?array
    {
        $hostAndPort = self::splitNoProxyHostAndPort($host);
        if ($hostAndPort === null) {
            return null;
        }

        [$host] = $hostAndPort;

        return self::parseNoProxyHost($host, null);
    }

    /**
     * @return array{type: string, value: string, port: int|null}|array{type: string, value: string, prefix: int}|null
     */
    private static function parseNoProxyRule(string $area): ?array
    {
        $area = \trim($area, " \n\r\t\0\x0B");
        if ($area === '' || $area === '*') {
            return null;
        }

        // A single leading dot is ignored: ".example.com" matches
        // example.com and its subdomains exactly like a bare domain,
        // consistent with every libcurl era. The strip runs before the
        // CIDR check so ".10.0.0.0/8" is a live rule on every path.
        if ($area[0] === '.') {
            $area = \substr($area, 1);
            if ($area === '') {
                return null;
            }
        }

        if (\strpos($area, '/') !== false) {
            return self::parseNoProxyCidrRule($area);
        }

        $hostAndPort = self::splitNoProxyHostAndPort($area);
        if ($hostAndPort === null) {
            return null;
        }

        [$host, $port] = $hostAndPort;

        if ($host === '*') {
            return [
                'type' => 'wildcard',
                'value' => '*',
                'port' => $port,
            ];
        }

        return self::parseNoProxyHost($host, $port);
    }

    /**
     * @return array{type: string, value: string, port: int|null}|null
     */
    private static function parseNoProxyHost(string $host, ?int $port): ?array
    {
        if ($host !== '' && $host[0] === '[') {
            if (\substr($host, -1) !== ']') {
                return null;
            }

            $address = \substr($host, 1, -1);
            if (!\filter_var($address, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6)) {
                return null;
            }

            $host = $address;
        }

        $packedIp = self::packIpAddress($host);
        if ($packedIp !== false) {
            return [
                'type' => 'ip',
                'value' => $packedIp,
                'port' => $port,
            ];
        }

        if ($host === '' || \strpos($host, ':') !== false) {
            return null;
        }

        // Normalize a single DNS root dot for no-proxy domain matching.
        if (\substr($host, -1) === '.') {
            $host = \substr($host, 0, -1);
            if ($host === '') {
                return null;
            }
        }

        return [
            'type' => 'domain',
            'value' => Psr7\Utils::asciiToLower($host),
            'port' => $port,
        ];
    }

    /**
     * @return array{0: string, 1: int|null}|null
     */
    private static function splitNoProxyHostAndPort(string $area): ?array
    {
        if ($area !== '' && $area[0] === '[') {
            $closingBracket = \strpos($area, ']');
            if ($closingBracket === false) {
                return null;
            }

            $host = \substr($area, 0, $closingBracket + 1);
            $tail = \substr($area, $closingBracket + 1);
            if ($tail === '') {
                return [$host, null];
            }

            if ($tail[0] !== ':') {
                return null;
            }

            $port = self::parseNoProxyPort(\substr($tail, 1));

            return $port === null ? null : [$host, $port];
        }

        if (self::packIpAddress($area) !== false) {
            return [$area, null];
        }

        $colon = \strrpos($area, ':');
        if ($colon === false) {
            return [$area, null];
        }

        $port = self::parseNoProxyPort(\substr($area, $colon + 1));
        if ($port === null) {
            return null;
        }

        return [\substr($area, 0, $colon), $port];
    }

    private static function parseNoProxyPort(string $port): ?int
    {
        return self::parseBoundedUnsignedInteger($port, 65535);
    }

    private static function getDefaultPort(string $scheme): ?int
    {
        if ($scheme === 'http') {
            return 80;
        }

        if ($scheme === 'https') {
            return 443;
        }

        return null;
    }

    /**
     * @return array{type: string, value: string, prefix: int}|null
     */
    private static function parseNoProxyCidrRule(string $area): ?array
    {
        $slash = \strpos($area, '/');
        if ($slash === false) {
            return null;
        }

        $prefix = \substr($area, $slash + 1);

        $network = \substr($area, 0, $slash);
        if ($network !== '' && $network[0] === '[' && \substr($network, -1) === ']') {
            $network = \substr($network, 1, -1);
        }

        $network = self::packIpAddress($network);
        if ($network === false) {
            return null;
        }

        $prefix = self::parseBoundedUnsignedInteger($prefix, \strlen($network) * 8);
        if ($prefix === null) {
            return null;
        }

        return [
            'type' => 'cidr',
            'value' => $network,
            'prefix' => $prefix,
        ];
    }

    private static function parseBoundedUnsignedInteger(string $value, int $max): ?int
    {
        if ($value === '' || !\ctype_digit($value)) {
            return null;
        }

        $normalized = \ltrim($value, '0');
        $normalized = $normalized === '' ? '0' : $normalized;
        $limit = (string) $max;

        if (\strlen($normalized) > \strlen($limit) || (\strlen($normalized) === \strlen($limit) && \strcmp($normalized, $limit) > 0)) {
            return null;
        }

        return (int) $normalized;
    }

    /**
     * @param array{type: string, value: string, port: int|null}                     $target
     * @param array{type: string, value: string, port?: int|null, prefix?: int|null} $rule
     */
    private static function noProxyRuleMatches(array $target, array $rule): bool
    {
        if ($rule['type'] === 'wildcard') {
            return ($rule['port'] ?? null) === null || $rule['port'] === $target['port'];
        }

        if ($rule['type'] === 'cidr') {
            if ($target['type'] !== 'ip' || !isset($rule['prefix'])) {
                return false;
            }

            if (\strlen($target['value']) !== \strlen($rule['value'])) {
                return false;
            }

            return self::ipMatchesPrefix($target['value'], $rule['value'], $rule['prefix']);
        }

        if (($rule['port'] ?? null) !== null && $rule['port'] !== $target['port']) {
            return false;
        }

        if ($rule['type'] !== $target['type']) {
            return false;
        }

        if ($rule['type'] === 'ip') {
            return $rule['value'] === $target['value'];
        }

        if ($target['value'] === $rule['value']) {
            return true;
        }

        $suffix = '.'.$rule['value'];

        return \substr($target['value'], -\strlen($suffix)) === $suffix;
    }

    /**
     * @return string|false
     */
    private static function packIpAddress(string $ip)
    {
        if (!\filter_var($ip, \FILTER_VALIDATE_IP)) {
            return false;
        }

        return \inet_pton($ip);
    }

    private static function ipMatchesPrefix(string $address, string $network, int $prefix): bool
    {
        $fullBytes = \intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        if ($fullBytes > 0 && \substr($address, 0, $fullBytes) !== \substr($network, 0, $fullBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (\ord($address[$fullBytes]) & $mask) === (\ord($network[$fullBytes]) & $mask);
    }
}
