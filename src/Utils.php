<?php

declare(strict_types=1);

namespace GuzzleHttp;

use GuzzleHttp\Exception\InvalidArgumentException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Handler\CurlShareHandleState;
use GuzzleHttp\Handler\CurlVersion;
use GuzzleHttp\Handler\Proxy;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

final class Utils
{
    private function __construct()
    {
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
            $headers[\trim($parts[0], " \n\r\t\0\x0B")][] = isset($parts[1]) ? \trim($parts[1], " \n\r\t\0\x0B") : null;
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
     * @param array{transport_sharing?: mixed, max_host_connections?: mixed, max_total_connections?: mixed} $handlerOptions Handler constructor options.
     *
     * @return callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed> Returns the best handler for the given system.
     *
     * @throws \RuntimeException if no viable Handler is available.
     */
    public static function chooseHandler(array $handlerOptions = []): callable
    {
        $sharingMode = CurlShareHandleState::normalizeMode($handlerOptions['transport_sharing'] ?? null, 'transport_sharing');
        $sharingRequired = self::isTransportSharingRequired($sharingMode);
        $connectionCapsRequired = self::hasConnectionCapOptions($handlerOptions);

        if ($connectionCapsRequired && $sharingMode === TransportSharing::PERSISTENT_REQUIRE) {
            throw new InvalidArgumentException('The "max_host_connections" and "max_total_connections" options cannot be combined with required persistent transport sharing because libcurl does not apply connection caps to shared connection pools.');
        }

        if ($connectionCapsRequired && $sharingMode === TransportSharing::PERSISTENT_PREFER) {
            // libcurl does not apply cURL multi connection caps to transfers
            // using a shared connection pool, so the best honorable offer for
            // preferred persistent sharing is a handler-lifetime share.
            $sharingMode = TransportSharing::HANDLER_PREFER;
        }

        $handler = self::createCurlHandler($sharingMode, $handlerOptions);

        if ($sharingRequired && $handler === null) {
            throw new \RuntimeException('Required transport sharing requires the PHP cURL extension, curl_exec() or curl_multi_exec(), and a supported libcurl version with SSL support.');
        }

        if (\ini_get('allow_url_fopen')) {
            return self::addStreamHandler($handler, $sharingMode, self::connectionCapOptions($handlerOptions));
        }

        if ($handler !== null) {
            return $handler;
        }

        if ($connectionCapsRequired) {
            throw new \RuntimeException('Connection cap options require a cap-capable cURL multi handler or the allow_url_fopen ini setting for stream fallback.');
        }

        throw new \RuntimeException('GuzzleHttp requires a supported cURL version with SSL support, the allow_url_fopen ini setting, or a custom HTTP handler.');
    }

    private static function isTransportSharingRequired(string $sharingMode): bool
    {
        return \in_array($sharingMode, [TransportSharing::HANDLER_REQUIRE, TransportSharing::PERSISTENT_REQUIRE], true);
    }

    /**
     * @param array{max_host_connections?: mixed, max_total_connections?: mixed} $handlerOptions
     */
    private static function hasConnectionCapOptions(array $handlerOptions): bool
    {
        return self::connectionCapOptions($handlerOptions) !== [];
    }

    /**
     * @param array{max_host_connections?: mixed, max_total_connections?: mixed} $handlerOptions
     *
     * @return (callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed>)|null
     */
    private static function createCurlHandler(string $sharingMode, array $handlerOptions): ?callable
    {
        if (!CurlVersion::supportsCurlHandler()) {
            return null;
        }

        $connectionCapOptions = self::connectionCapOptions($handlerOptions);
        if ($connectionCapOptions !== [] && !\function_exists('curl_multi_exec')) {
            return null;
        }

        $curlHandlerOptions = self::createCurlHandlerOptions($sharingMode);
        $curlMultiHandlerOptions = $curlHandlerOptions + $connectionCapOptions;

        if (\function_exists('curl_multi_exec') && \function_exists('curl_exec')) {
            $multiHandler = new CurlMultiHandler($curlMultiHandlerOptions);

            if ($connectionCapOptions !== []) {
                // Connection caps only govern transfers on the multi handle, so
                // the synchronous CurlHandler fast path would escape them.
                return $multiHandler;
            }

            return Proxy::wrapSync($multiHandler, new CurlHandler($curlHandlerOptions));
        }

        if ($connectionCapOptions === [] && \function_exists('curl_exec')) {
            return new CurlHandler($curlHandlerOptions);
        }

        if (\function_exists('curl_multi_exec')) {
            return new CurlMultiHandler($curlMultiHandlerOptions);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function createCurlHandlerOptions(string $sharingMode): array
    {
        if ($sharingMode === TransportSharing::NONE) {
            return [];
        }

        $shareState = CurlShareHandleState::fromOption($sharingMode);

        return $shareState === null ? [] : ['transport_sharing' => $shareState];
    }

    /**
     * @param array{max_host_connections?: mixed, max_total_connections?: mixed} $handlerOptions
     *
     * @return array{max_host_connections?: int, max_total_connections?: int}
     */
    private static function connectionCapOptions(array $handlerOptions): array
    {
        $options = [];
        foreach (['max_host_connections', 'max_total_connections'] as $capOption) {
            $value = $handlerOptions[$capOption] ?? null;
            if ($value === null) {
                continue;
            }

            if (!\is_int($value) || $value < 1) {
                throw new InvalidArgumentException(\sprintf('%s must be a positive integer.', $capOption));
            }

            $options[$capOption] = $value;
        }

        return $options;
    }

    /**
     * @param (callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed>)|null $handler
     * @param array{max_host_connections?: int, max_total_connections?: int}                                         $connectionCapOptions
     *
     * @return callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed>
     */
    private static function addStreamHandler(?callable $handler, string $sharingMode, array $connectionCapOptions): callable
    {
        $streamHandler = new StreamHandler(['transport_sharing' => $sharingMode] + $connectionCapOptions);

        return $handler
            ? Proxy::wrapStreaming($handler, $streamHandler)
            : $streamHandler;
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
