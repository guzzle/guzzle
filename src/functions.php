<?php

namespace GuzzleHttp;

/**
 * Debug function used to describe the provided value type and class.
 *
 * @param mixed $input Any type of variable to describe the type of. This
 *                     parameter misses a typehint because of that.
 *
 * @return string Returns a string containing the type of the variable and
 *                if a class is provided, the class name.
 */
function describe_type($input): string
{
    return Utils::describeType($input);
}

/**
 * Parses an array of header lines into an associative array of headers.
 *
 * @param iterable $lines Header lines array of strings in the following
 *                        format: "Name: Value"
 */
function headers_from_lines(iterable $lines): array
{
    return Utils::headersFromLines($lines);
}

/**
 * Returns a debug stream based on the provided variable.
 *
 * @param mixed $value Optional value
 *
 * @return resource
 */
function debug_resource($value = null)
{
    return Utils::debugResource($value);
}

/**
 * Chooses and creates a default handler to use based on the environment.
 *
 * The returned handler is not wrapped by any default middlewares.
 *
 * @throws \RuntimeException if no viable Handler is available.
 *
 * @return callable(\Psr\Http\Message\RequestInterface, array): \GuzzleHttp\Promise\PromiseInterface Returns the best handler for the given system.
 */
function choose_handler(): callable
{
    return Utils::chooseHandler();
}

/**
 * Get the default User-Agent string to use with Guzzle.
 */
function default_user_agent(): string
{
    return Utils::defaultUserAgent();
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
 */
function default_ca_bundle(): string
{
    return Utils::defaultCaBundle();
}

/**
 * Creates an associative array of lowercase header names to the actual
 * header casing.
 */
function normalize_header_keys(array $headers): array
{
    return Utils::normalizeHeaderKeys($headers);
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
 * @throws Exception\InvalidArgumentException
 */
function is_host_in_noproxy(string $host, array $noProxyArray): bool
{
    return Utils::isHostInNoProxy($host, $noProxyArray);
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
 * @throws Exception\InvalidArgumentException if the JSON cannot be decoded.
 *
 * @link https://www.php.net/manual/en/function.json-decode.php
 */
function json_decode(string $json, bool $assoc = false, int $depth = 512, int $options = 0)
{
    return Utils::jsonDecode($json, $assoc, $depth, $options);
}

/**
 * Wrapper for JSON encoding that throws when an error occurs.
 *
 * @param mixed $value   The value being encoded
 * @param int   $options JSON encode option bitmask
 * @param int   $depth   Set the maximum depth. Must be greater than zero.
 *
 * @throws Exception\InvalidArgumentException if the JSON cannot be encoded.
 *
 * @link https://www.php.net/manual/en/function.json-encode.php
 */
function json_encode($value, int $options = 0, int $depth = 512): string
{
    return Utils::jsonEncode($value, $options, $depth);
}
