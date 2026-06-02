<?php

declare(strict_types=1);

namespace GuzzleHttp;

use GuzzleHttp\Exception\InvalidArgumentException;
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
    public static function resolve(UriInterface $uri, $proxy): ProxySelection
    {
        if ($proxy === null) {
            return ProxySelection::none();
        }

        if (!\is_array($proxy)) {
            if (!\is_string($proxy)) {
                throw new InvalidArgumentException('proxy must be a string or array');
            }

            return ProxySelection::proxy($proxy);
        }

        $scheme = $uri->getScheme();
        if (!isset($proxy[$scheme])) {
            return ProxySelection::none();
        }

        if (!\is_string($proxy[$scheme])) {
            throw new InvalidArgumentException('proxy values must be strings');
        }

        $noProxy = isset($proxy['no']) ? self::normalizeNoProxy($proxy['no']) : [];
        if ($noProxy !== [] && self::isUriInNoProxy($uri, $noProxy)) {
            return ProxySelection::bypassed();
        }

        return ProxySelection::proxy($proxy[$scheme]);
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
            $noProxy = \explode(',', $noProxy);
        } elseif (!\is_array($noProxy)) {
            throw new InvalidArgumentException('proxy no list must be null, a string, or an array of strings');
        }

        $result = [];
        foreach ($noProxy as $area) {
            if (!\is_string($area)) {
                throw new InvalidArgumentException('proxy no list must be null, a string, or an array of strings');
            }

            $area = \trim($area);
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
            $area = \trim($area);

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
     * 3. A bare domain matches itself and its subdomains. e.g. 'mit.edu' will
     *    match 'mit.edu' and 'foo.mit.edu'.
     * 4. The area starts with "." and the area is the last part of the host. e.g.
     *    '.mit.edu' will match any host that ends with '.mit.edu'.
     * 5. IP CIDR entries match IP literal hosts. e.g. '192.168.0.0/16' will
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
            $area = \trim($area);

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
     * @return array{type: string, value: string, port: int|null, matchesRoot: bool}|null
     */
    private static function parseNoProxyTarget(UriInterface $uri): ?array
    {
        $host = $uri->getHost();
        if ($host === '') {
            return null;
        }

        return self::parseNoProxyHost($host, $uri->getPort() ?? self::getDefaultPort($uri->getScheme()), true);
    }

    /**
     * @return array{type: string, value: string, port: int|null, matchesRoot: bool}|null
     */
    private static function parseNoProxyHostString(string $host): ?array
    {
        $hostAndPort = self::splitNoProxyHostAndPort($host);
        if ($hostAndPort === null) {
            return null;
        }

        [$host] = $hostAndPort;

        return self::parseNoProxyHost($host, null, true);
    }

    /**
     * @return array{type: string, value: string, port: int|null, matchesRoot: bool}|array{type: string, value: string, prefix: int}|null
     */
    private static function parseNoProxyRule(string $area): ?array
    {
        $area = \trim($area);
        if ($area === '' || $area === '*') {
            return null;
        }

        if (\strpos($area, '/') !== false) {
            return self::parseNoProxyCidrRule($area);
        }

        $matchesRoot = true;
        if ($area[0] === '.') {
            $matchesRoot = false;
            $area = \substr($area, 1);
        }

        $hostAndPort = self::splitNoProxyHostAndPort($area);
        if ($hostAndPort === null) {
            return null;
        }

        [$host, $port] = $hostAndPort;

        if ($host === '*') {
            if (!$matchesRoot) {
                return null;
            }

            return [
                'type' => 'wildcard',
                'value' => '*',
                'port' => $port,
                'matchesRoot' => true,
            ];
        }

        $rule = self::parseNoProxyHost($host, $port, $matchesRoot);
        if ($rule !== null && !$matchesRoot && $rule['type'] === 'ip') {
            return null;
        }

        return $rule;
    }

    /**
     * @return array{type: string, value: string, port: int|null, matchesRoot: bool}|null
     */
    private static function parseNoProxyHost(string $host, ?int $port, bool $matchesRoot): ?array
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
                'matchesRoot' => $matchesRoot,
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
            'value' => \strtolower($host),
            'port' => $port,
            'matchesRoot' => $matchesRoot,
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
     * @param array{type: string, value: string, port: int|null, matchesRoot: bool}                      $target
     * @param array{type: string, value: string, port?: int|null, matchesRoot?: bool, prefix?: int|null} $rule
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

        if (($rule['matchesRoot'] ?? false) && $target['value'] === $rule['value']) {
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
