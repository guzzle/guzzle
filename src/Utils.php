<?php

namespace GuzzleHttp;

use GuzzleHttp\Exception\InvalidArgumentException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Handler\CurlVersion;
use GuzzleHttp\Handler\Proxy;
use GuzzleHttp\Handler\StreamHandler;
use Psr\Http\Message\UriInterface;

final class Utils
{
    /**
     * Debug function used to describe the provided value type and class.
     *
     * @param mixed $input
     *
     * @return string Returns a string containing the type of the variable and
     *                if a class is provided, the class name.
     */
    public static function describeType($input): string
    {
        switch (\gettype($input)) {
            case 'object':
                return 'object('.\get_class($input).')';
            case 'array':
                return 'array('.\count($input).')';
            default:
                \ob_start();
                \var_dump($input);
                // normalize float vs double
                /** @var string $varDumpContent */
                $varDumpContent = \ob_get_clean();

                return \str_replace('double(', 'float(', \rtrim($varDumpContent));
        }
    }

    /**
     * Parses an array of header lines into an associative array of headers.
     *
     * @param iterable $lines Header lines array of strings in the following
     *                        format: "Name: Value"
     */
    public static function headersFromLines(iterable $lines): array
    {
        $headers = [];

        foreach ($lines as $line) {
            $parts = \explode(':', $line, 2);
            $headers[\trim($parts[0])][] = isset($parts[1]) ? \trim($parts[1]) : null;
        }

        return $headers;
    }

    /**
     * Returns a debug stream based on the provided variable.
     *
     * @param mixed $value Optional value
     *
     * @return resource
     */
    public static function debugResource($value = null)
    {
        if (\is_resource($value)) {
            return $value;
        }
        if (\defined('STDOUT')) {
            return \STDOUT;
        }

        return Psr7\Utils::tryFopen('php://output', 'w');
    }

    /**
     * Chooses and creates a default handler to use based on the environment.
     *
     * The returned handler is not wrapped by any default middlewares.
     *
     * @return callable(\Psr\Http\Message\RequestInterface, array): Promise\PromiseInterface<\Psr\Http\Message\ResponseInterface, mixed> Returns the best handler for the given system.
     *
     * @throws \RuntimeException if no viable Handler is available.
     */
    public static function chooseHandler(): callable
    {
        $handler = null;

        if (CurlVersion::supportsTls12()) {
            if (\function_exists('curl_multi_exec') && \function_exists('curl_exec')) {
                $handler = Proxy::wrapSync(new CurlMultiHandler(), new CurlHandler());
            } elseif (\function_exists('curl_exec')) {
                $handler = new CurlHandler();
            } elseif (\function_exists('curl_multi_exec')) {
                $handler = new CurlMultiHandler();
            }
        }

        if (\ini_get('allow_url_fopen')) {
            $handler = $handler
                ? Proxy::wrapStreaming($handler, new StreamHandler())
                : new StreamHandler();
        } elseif (!$handler) {
            throw new \RuntimeException('GuzzleHttp requires a supported cURL version, the allow_url_fopen ini setting, or a custom HTTP handler.');
        }

        return $handler;
    }

    /**
     * Get the default User-Agent string to use with Guzzle.
     */
    public static function defaultUserAgent(): string
    {
        return sprintf('GuzzleHttp/%d', ClientInterface::MAJOR_VERSION);
    }

    /**
     * Creates an associative array of lowercase header names to the actual
     * header casing.
     */
    public static function normalizeHeaderKeys(array $headers): array
    {
        $result = [];
        foreach (\array_keys($headers) as $key) {
            $result[\strtolower($key)] = $key;
        }

        return $result;
    }

    /**
     * Returns true if the provided host matches any of the no proxy areas.
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
     * @param string   $host         Host to check against the patterns.
     * @param string[] $noProxyArray An array of host or CIDR patterns.
     *
     * @throws InvalidArgumentException
     */
    public static function isHostInNoProxy(string $host, array $noProxyArray): bool
    {
        if (\strlen($host) === 0) {
            throw new InvalidArgumentException('Empty host provided');
        }

        $target = self::parseNoProxyHostString($host);
        if ($target === null) {
            return false;
        }

        foreach ($noProxyArray as $area) {
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
     * Returns true if the provided URI matches any of the no proxy areas.
     *
     * @param string[] $noProxyArray An array of host, host-and-port, or CIDR patterns.
     *
     * @internal
     */
    public static function isUriInNoProxy(UriInterface $uri, array $noProxyArray): bool
    {
        $target = self::parseNoProxyTarget($uri);
        if ($target === null) {
            return false;
        }

        foreach ($noProxyArray as $area) {
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
        if ($port === '' || !\ctype_digit($port)) {
            return null;
        }

        $port = (int) $port;

        return $port <= 65535 ? $port : null;
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
        if ($prefix === '' || !\ctype_digit($prefix)) {
            return null;
        }

        $network = \substr($area, 0, $slash);
        if ($network !== '' && $network[0] === '[' && \substr($network, -1) === ']') {
            $network = \substr($network, 1, -1);
        }

        $network = self::packIpAddress($network);
        if ($network === false) {
            return null;
        }

        $prefix = (int) $prefix;
        if ($prefix > \strlen($network) * 8) {
            return null;
        }

        return [
            'type' => 'cidr',
            'value' => $network,
            'prefix' => $prefix,
        ];
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

    /**
     * Normalize a no-proxy list from request options.
     *
     * @param mixed $noProxy No-proxy value as passed via request transfer options.
     *
     * @return string[]
     *
     * @internal
     */
    public static function normalizeNoProxy($noProxy): array
    {
        if (\is_string($noProxy)) {
            $noProxy = \explode(',', $noProxy);
        } elseif (!\is_array($noProxy)) {
            throw new InvalidArgumentException('proxy no list must be a string or array of strings');
        }

        $result = [];
        foreach ($noProxy as $area) {
            if (!\is_string($area)) {
                throw new InvalidArgumentException('proxy no list must be a string or array of strings');
            }

            $area = \trim($area);
            if ($area !== '') {
                $result[] = $area;
            }
        }

        return $result;
    }

    /**
     * Wrapper for json_decode that throws when an error occurs.
     *
     * @param string $json    JSON data to parse
     * @param bool   $assoc   When true, returned objects will be converted
     *                        into associative arrays.
     * @param int    $depth   User specified recursion depth.
     * @param int    $options Bitmask of JSON decode options.
     *
     * @return object|array|string|int|float|bool|null
     *
     * @throws InvalidArgumentException if the JSON cannot be decoded.
     *
     * @see https://www.php.net/manual/en/function.json-decode.php
     */
    public static function jsonDecode(string $json, bool $assoc = false, int $depth = 512, int $options = 0)
    {
        if ($depth < 1) {
            throw new InvalidArgumentException('json_decode error: Maximum stack depth exceeded');
        }

        try {
            return \json_decode($json, $assoc, $depth, $options | \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidArgumentException('json_decode error: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Wrapper for JSON encoding that throws when an error occurs.
     *
     * @param mixed $value   The value being encoded
     * @param int   $options JSON encode option bitmask
     * @param int   $depth   Set the maximum depth. Must be greater than zero.
     *
     * @throws InvalidArgumentException if the JSON cannot be encoded.
     *
     * @see https://www.php.net/manual/en/function.json-encode.php
     */
    public static function jsonEncode($value, int $options = 0, int $depth = 512): string
    {
        try {
            return \json_encode($value, $options | \JSON_THROW_ON_ERROR, $depth);
        } catch (\JsonException $e) {
            throw new InvalidArgumentException('json_encode error: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Wrapper for the hrtime() or microtime() functions
     * (depending on the PHP version, one of the two is used)
     *
     * @return float UNIX timestamp
     *
     * @internal
     */
    public static function currentTime(): float
    {
        return (float) \function_exists('hrtime') ? \hrtime(true) / 1e9 : \microtime(true);
    }

    /**
     * Converts a request timeout option to integer milliseconds.
     *
     * @param mixed $value
     *
     * @internal
     */
    public static function timeoutToMilliseconds($value, string $option): int
    {
        if (!\is_int($value) && !\is_float($value) && (!\is_string($value) || !\is_numeric($value))) {
            throw new InvalidArgumentException($option.' must be a number of seconds');
        }

        $seconds = (float) $value;
        if (!\is_finite($seconds) || $seconds < 0) {
            throw new InvalidArgumentException($option.' must be 0 or greater than or equal to 0.001 seconds');
        }

        $milliseconds = (int) ($seconds * 1000);
        if ($seconds > 0 && $milliseconds === 0) {
            throw new InvalidArgumentException($option.' must be 0 or greater than or equal to 0.001 seconds');
        }

        return $milliseconds;
    }

    /**
     * @throws InvalidArgumentException
     *
     * @internal
     */
    public static function idnUriConvert(UriInterface $uri, int $options = 0): UriInterface
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
                $uri = $uri->withHost($asciiHost);
            }
        }

        return $uri;
    }

    /**
     * @internal
     */
    public static function getenv(string $name): ?string
    {
        if (isset($_SERVER[$name])) {
            return (string) $_SERVER[$name];
        }

        if (\PHP_SAPI === 'cli' && ($value = \getenv($name)) !== false && $value !== null) {
            return (string) $value;
        }

        return null;
    }

    /**
     * @return string|false
     */
    private static function idnToAsci(string $domain, int $options, ?array &$info = [])
    {
        if (\function_exists('idn_to_ascii') && \defined('INTL_IDNA_VARIANT_UTS46')) {
            return \idn_to_ascii($domain, $options, \INTL_IDNA_VARIANT_UTS46, $info);
        }

        throw new \Error('ext-idn or symfony/polyfill-intl-idn not loaded or too old');
    }
}
