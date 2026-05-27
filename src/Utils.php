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
     * @return callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed> Returns the best handler for the given system.
     *
     * @throws \RuntimeException if no viable Handler is available.
     */
    public static function chooseHandler(array $handlerOptions = []): callable
    {
        $handler = null;
        $sharingMode = CurlShareHandleState::normalizeMode($handlerOptions['transport_sharing'] ?? null, 'transport_sharing');
        $sharingRequested = $sharingMode !== TransportSharing::NONE;
        $sharingRequired = \in_array($sharingMode, [TransportSharing::HANDLER_REQUIRE, TransportSharing::PERSISTENT_REQUIRE], true);
        $curlHandlerOptions = [];
        $curlSupported = CurlVersion::supportsTls12()
            && (\function_exists('curl_multi_exec') || \function_exists('curl_exec'));

        if ($sharingRequired && !$curlSupported) {
            throw new \RuntimeException('Required transport sharing requires the PHP cURL extension, curl_exec() or curl_multi_exec(), and a supported libcurl version.');
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
            $streamHandler = new StreamHandler();
            if ($sharingRequired) {
                $streamHandler = self::wrapStreamHandlerTransportSharing($streamHandler, $sharingMode);
            }

            $handler = $handler
                ? Proxy::wrapStreaming($handler, $streamHandler)
                : $streamHandler;
        } elseif (!$handler) {
            throw new \RuntimeException('GuzzleHttp requires a supported cURL version, the allow_url_fopen ini setting, or a custom HTTP handler.');
        }

        return $handler;
    }

    /**
     * @param callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed> $handler
     *
     * @return callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed>
     */
    private static function wrapStreamHandlerTransportSharing(callable $handler, string $sharingMode): callable
    {
        return static function (RequestInterface $request, array $options) use ($handler, $sharingMode): PromiseInterface {
            if (\array_key_exists('transport_sharing', $options)) {
                CurlShareHandleState::normalizeMode($options['transport_sharing'], 'transport_sharing');
            }

            $options['transport_sharing'] = $sharingMode;

            return $handler($request, $options);
        };
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
