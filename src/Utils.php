<?php

namespace GuzzleHttp;

use GuzzleHttp\Exception\InvalidArgumentException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Handler\CurlShareHandleState;
use GuzzleHttp\Handler\Proxy;
use GuzzleHttp\Handler\StreamHandler;
use Psr\Http\Message\RequestInterface;
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
     * @param array{transport_sharing?: mixed} $handlerOptions Handler constructor options.
     *
     * @return callable(RequestInterface, array): Promise\PromiseInterface Returns the best handler for the given system.
     *
     * @throws \RuntimeException if no viable Handler is available.
     */
    public static function chooseHandler(array $handlerOptions = []): callable
    {
        $handler = null;
        $sharingMode = CurlShareHandleState::normalizeMode($handlerOptions['transport_sharing'] ?? null, 'transport_sharing');
        $sharingRequested = $sharingMode !== TransportSharing::NONE;
        $sharingRequired = $sharingMode === TransportSharing::HANDLER_REQUIRE;
        $curlHandlerOptions = [];
        $curlSupported = \defined('CURLOPT_CUSTOMREQUEST')
            && \function_exists('curl_version')
            && version_compare(curl_version()['version'], '7.21.2') >= 0
            && (\function_exists('curl_multi_exec') || \function_exists('curl_exec'));

        if ($sharingRequired && !$curlSupported) {
            throw new \RuntimeException('Required transport sharing requires the PHP cURL extension, curl_exec() or curl_multi_exec(), and libcurl 7.21.2 or higher.');
        }

        if ($curlSupported) {
            if ($sharingRequested) {
                $shareState = CurlShareHandleState::fromOption($sharingMode);
                if ($shareState !== null) {
                    $curlHandlerOptions['transport_sharing'] = $shareState;
                }
            }

            if (\function_exists('curl_multi_exec') && \function_exists('curl_exec')) {
                $handler = Proxy::wrapSync(new CurlMultiHandler($curlHandlerOptions), new CurlHandler($curlHandlerOptions));
            } elseif (\function_exists('curl_exec')) {
                $handler = new CurlHandler($curlHandlerOptions);
            } elseif (\function_exists('curl_multi_exec')) {
                $handler = new CurlMultiHandler($curlHandlerOptions);
            }
        }

        if (\ini_get('allow_url_fopen')) {
            $streamHandler = new StreamHandler(['transport_sharing' => $sharingMode]);

            $handler = $handler
                ? Proxy::wrapStreaming($handler, $streamHandler)
                : $streamHandler;
        } elseif (!$handler) {
            throw new \RuntimeException('GuzzleHttp requires cURL, the allow_url_fopen ini setting, or a custom HTTP handler.');
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
     * Returns the default cacert bundle for the current system.
     *
     * First, the openssl.cafile and curl.cainfo php.ini settings are checked.
     * If those settings are not configured, then the common locations for
     * bundles found on Red Hat, CentOS, Fedora, Ubuntu, Debian, FreeBSD, OS X
     * and Windows are checked. If any of these file locations are found on
     * disk, they will be utilized.
     *
     * Note: the result of this function is cached for subsequent calls.
     *
     * @throws \RuntimeException if no bundle can be found.
     *
     * @deprecated Utils::defaultCaBundle will be removed in guzzlehttp/guzzle:8.0. This method is not needed in PHP 5.6+.
     */
    public static function defaultCaBundle(): string
    {
        static $cached = null;
        static $cafiles = [
            // Red Hat, CentOS, Fedora (provided by the ca-certificates package)
            '/etc/pki/tls/certs/ca-bundle.crt',
            // Ubuntu, Debian (provided by the ca-certificates package)
            '/etc/ssl/certs/ca-certificates.crt',
            // FreeBSD (provided by the ca_root_nss package)
            '/usr/local/share/certs/ca-root-nss.crt',
            // SLES 12 (provided by the ca-certificates package)
            '/var/lib/ca-certificates/ca-bundle.pem',
            // OS X provided by homebrew (using the default path)
            '/usr/local/etc/openssl/cert.pem',
            // Google app engine
            '/etc/ca-certificates.crt',
            // Windows?
            'C:\\windows\\system32\\curl-ca-bundle.crt',
            'C:\\windows\\curl-ca-bundle.crt',
        ];

        if ($cached) {
            return $cached;
        }

        if ($ca = \ini_get('openssl.cafile')) {
            return $cached = $ca;
        }

        if ($ca = \ini_get('curl.cainfo')) {
            return $cached = $ca;
        }

        foreach ($cafiles as $filename) {
            if (\file_exists($filename)) {
                return $cached = $filename;
            }
        }

        throw new \RuntimeException(
            <<< EOT
No system CA bundle could be found in any of the the common system locations.
PHP versions earlier than 5.6 are not properly configured to use the system's
CA bundle by default. In order to verify peer certificates, you will need to
supply the path on disk to a certificate bundle to the 'verify' request
option: https://github.com/guzzle/guzzle/blob/7.11/docs/request-options.md#verify. If
you do not need a specific certificate bundle, then Mozilla provides a commonly
used CA bundle which can be downloaded here (provided by the maintainer of
cURL): https://curl.haxx.se/ca/cacert.pem. Once you have a CA bundle available
on disk, you can set the 'openssl.cafile' PHP ini setting to point to the path
to the file, allowing you to omit the 'verify' request option. See
https://curl.haxx.se/docs/sslcerts.html for more information.
EOT
        );
    }

    /**
     * Creates an associative array of lowercase header names to the actual
     * header casing.
     */
    public static function normalizeHeaderKeys(array $headers): array
    {
        $result = [];
        foreach (\array_keys($headers) as $key) {
            $result[\strtolower((string) $key)] = $key;
        }

        return $result;
    }

    /**
     * @param mixed $protocols
     *
     * @return string[]
     *
     * @throws InvalidArgumentException
     */
    public static function normalizeProtocols($protocols): array
    {
        if (!\is_array($protocols) || $protocols === []) {
            throw new InvalidArgumentException('protocols must be a non-empty array of "http" and/or "https"');
        }

        $normalized = [];

        foreach ($protocols as $protocol) {
            if (!\is_string($protocol)) {
                throw new InvalidArgumentException('protocols must contain only strings');
            }

            if ($protocol !== 'http' && $protocol !== 'https') {
                throw new InvalidArgumentException('protocols may only contain "http" and "https"');
            }

            $normalized[$protocol] = true;
        }

        return \array_keys($normalized);
    }

    /**
     * Returns true if the provided host matches any of the no proxy areas.
     *
     * This method will strip a port from the host if it is present. Each pattern
     * can be matched with an exact match (e.g., "foo.com" == "foo.com") or a
     * partial match: (e.g., "foo.com" == "baz.foo.com" and ".foo.com" ==
     * "baz.foo.com", but ".foo.com" != "foo.com").
     *
     * Areas are matched in the following cases:
     * 1. "*" (without quotes) always matches any hosts.
     * 2. An exact match.
     * 3. The area starts with "." and the area is the last part of the host. e.g.
     *    '.mit.edu' will match any host that ends with '.mit.edu'.
     *
     * @param string   $host         Host to check against the patterns.
     * @param string[] $noProxyArray An array of host patterns.
     *
     * @throws InvalidArgumentException
     */
    public static function isHostInNoProxy(string $host, array $noProxyArray): bool
    {
        if (\strlen($host) === 0) {
            throw new InvalidArgumentException('Empty host provided');
        }

        $host = self::normalizeNoProxyHost($host, true);

        foreach ($noProxyArray as $area) {
            // Always match on wildcards.
            if ($area === '*') {
                return true;
            }

            if ($area === '') {
                continue;
            }

            $area = self::normalizeNoProxyHost($area, false);

            if ($area === $host) {
                // Exact matches.
                return true;
            }
            // Special match if the area when prefixed with ".". Remove any
            // existing leading "." and add a new leading ".".
            $area = '.'.\ltrim($area, '.');
            if (
                \strpos($host, ':') === false
                && \strpos($area, ':') === false
                && \substr($host, -\strlen($area)) === $area
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns true if the provided URI matches any of the no proxy areas.
     *
     * @param mixed $noProxy No-proxy host patterns.
     *
     * @internal
     */
    public static function isUriInNoProxy(UriInterface $uri, $noProxy): bool
    {
        if (\is_string($noProxy)) {
            $noProxy = \explode(',', $noProxy);
        }

        if (!\is_array($noProxy)) {
            return false;
        }

        $host = $uri->getHost();
        if ($host === '') {
            return false;
        }

        $port = $uri->getPort();
        if ($port === null) {
            $port = self::getDefaultPort($uri->getScheme());
        }

        foreach ($noProxy as $area) {
            if (!\is_string($area)) {
                continue;
            }

            $area = \trim($area);

            // Always match on wildcards.
            if ($area === '*') {
                return true;
            }

            if ($area === '') {
                continue;
            }

            [$area, $areaPort] = self::splitNoProxyHostAndPort($area);
            if ($areaPort !== null && $areaPort !== $port) {
                continue;
            }

            if (self::isHostInNoProxy($host, [$area])) {
                return true;
            }
        }

        return false;
    }

    private static function normalizeNoProxyHost(string $host, bool $stripPort): string
    {
        if ($host !== '' && $host[0] === '[') {
            $closingBracket = \strpos($host, ']');

            if ($closingBracket !== false) {
                $address = \substr($host, 1, $closingBracket - 1);
                $tail = \substr($host, $closingBracket + 1);

                if (
                    ($tail === '' || ($stripPort && \preg_match('/^:\d+$/', $tail)))
                    && \filter_var($address, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6)
                ) {
                    return \strtolower($address);
                }
            }
        }

        if (\filter_var($host, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6)) {
            return \strtolower($host);
        }

        if ($stripPort) {
            [$host] = \explode(':', $host, 2);
        }

        return $host;
    }

    /**
     * @return array{0: string, 1: int|null}
     */
    private static function splitNoProxyHostAndPort(string $area): array
    {
        if ($area !== '' && $area[0] === '[') {
            $closingBracket = \strpos($area, ']');

            if ($closingBracket !== false) {
                $tail = \substr($area, $closingBracket + 1);
                if ($tail !== '' && $tail[0] === ':') {
                    $port = self::parseNoProxyPort(\substr($tail, 1));

                    if ($port !== null) {
                        return [\substr($area, 0, $closingBracket + 1), $port];
                    }
                }
            }

            return [$area, null];
        }

        if (\filter_var($area, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6)) {
            return [$area, null];
        }

        $colon = \strrpos($area, ':');
        if ($colon === false) {
            return [$area, null];
        }

        $port = self::parseNoProxyPort(\substr($area, $colon + 1));
        if ($port === null) {
            return [$area, null];
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

        $data = \json_decode($json, $assoc, $depth, $options);
        if (\JSON_ERROR_NONE !== \json_last_error()) {
            throw new InvalidArgumentException('json_decode error: '.\json_last_error_msg());
        }

        return $data;
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
        $json = \json_encode($value, $options, $depth);
        if (\JSON_ERROR_NONE !== \json_last_error()) {
            throw new InvalidArgumentException('json_encode error: '.\json_last_error_msg());
        }

        /** @var string */
        return $json;
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
     * @param mixed $value
     *
     * @internal
     */
    public static function normalizeIdnConversionOption($value): ?int
    {
        if ($value === null || $value === false) {
            return null;
        }

        if ($value === true) {
            return \IDNA_DEFAULT;
        }

        if (\is_int($value)) {
            return $value;
        }

        if ((\is_string($value) && \is_numeric($value)) || (\is_float($value) && \is_finite($value))) {
            \trigger_deprecation(
                'guzzlehttp/guzzle',
                '7.11',
                'Passing %s as the "idn_conversion" request option is deprecated; guzzlehttp/guzzle 8.0 will reject values that are not true, false, null, or an integer IDNA_* bitmask.',
                self::describeType($value)
            );

            return (int) $value;
        }

        throw new InvalidArgumentException('idn_conversion must be true, false, null, or an integer IDNA_* bitmask');
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
