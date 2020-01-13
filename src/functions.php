<?php
namespace GuzzleHttp;

use GuzzleHttp\Exception\InvalidArgumentException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Handler\Proxy;
use GuzzleHttp\Handler\StreamHandler;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;

/**
 * Debug function used to describe the provided value type and class.
 *
 * @return string Returns a string containing the type of the variable and
 *                if a class is provided, the class name.
 */
function describe_type($input): string
{
    switch (\gettype($input)) {
        case 'object':
            return 'object(' . \get_class($input) . ')';
        case 'array':
            return 'array(' . \count($input) . ')';
        default:
            \ob_start();
            \var_dump($input);
            // normalize float vs double
            return \str_replace('double(', 'float(', \rtrim(\ob_get_clean()));
    }
}

/**
 * Parses an array of header lines into an associative array of headers.
 *
 * @param iterable $lines Header lines array of strings in the following
 *                        format: "Name: Value"
 */
function headers_from_lines($lines): array
{
    $headers = [];

    foreach ($lines as $line) {
        $parts = \explode(':', $line, 2);
        $headers[\trim($parts[0])][] = isset($parts[1])
            ? \trim($parts[1])
            : null;
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
function debug_resource($value = null)
{
    if (\is_resource($value)) {
        return $value;
    } elseif (\defined('STDOUT')) {
        return STDOUT;
    }

    return \fopen('php://output', 'w');
}

/**
 * Chooses and creates a default handler to use based on the environment.
 *
 * The returned handler is not wrapped by any default middlewares.
 *
 * @throws \RuntimeException if no viable Handler is available.
 *
 * @return callable Returns the best handler for the given system.
 */
function choose_handler(): callable
{
    $handler = null;
    if (\function_exists('curl_multi_exec') && \function_exists('curl_exec')) {
        $handler = Proxy::wrapSync(new CurlMultiHandler(), new CurlHandler());
    } elseif (\function_exists('curl_exec')) {
        $handler = new CurlHandler();
    } elseif (\function_exists('curl_multi_exec')) {
        $handler = new CurlMultiHandler();
    }

    if (\ini_get('allow_url_fopen')) {
        $handler = $handler
            ? Proxy::wrapStreaming($handler, new StreamHandler())
            : new StreamHandler();
    } elseif (!$handler) {
        throw new \RuntimeException('GuzzleHttp requires cURL, the '
            . 'allow_url_fopen ini setting, or a custom HTTP handler.');
    }

    return $handler;
}

/**
 * Get the default User-Agent string to use with Guzzle
 */
function default_user_agent(): string
{
    static $defaultAgent = '';

    if (!$defaultAgent) {
        $defaultAgent = 'GuzzleHttp/Guzzle';
        if (\extension_loaded('curl') && \function_exists('curl_version')) {
            $defaultAgent .= ' curl/' . \curl_version()['version'];
        }
        $defaultAgent .= ' PHP/' . PHP_VERSION;
    }

    return $defaultAgent;
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
option: http://docs.guzzlephp.org/en/latest/clients.html#verify. If you do not
need a specific certificate bundle, then Mozilla provides a commonly used CA
bundle which can be downloaded here (provided by the maintainer of cURL):
https://raw.githubusercontent.com/bagder/ca-bundle/master/ca-bundle.crt. Once
you have a CA bundle available on disk, you can set the 'openssl.cafile' PHP
ini setting to point to the path to the file, allowing you to omit the 'verify'
request option. See http://curl.haxx.se/docs/sslcerts.html for more
information.
EOT
    );
}

/**
 * Creates an associative array of lowercase header names to the actual
 * header casing.
 */
function normalize_header_keys(array $headers): array
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
 */
function is_host_in_noproxy(string $host, array $noProxyArray): bool
{
    if (\strlen($host) === 0) {
        throw new \InvalidArgumentException('Empty host provided');
    }

    // Strip port if present.
    if (\strpos($host, ':')) {
        $host = \explode($host, ':', 2)[0];
    }

    foreach ($noProxyArray as $area) {
        // Always match on wildcards.
        if ($area === '*') {
            return true;
        } elseif (empty($area)) {
            // Don't match on empty values.
            continue;
        } elseif ($area === $host) {
            // Exact matches.
            return true;
        } else {
            // Special match if the area when prefixed with ".". Remove any
            // existing leading "." and add a new leading ".".
            $area = '.' . \ltrim($area, '.');
            if (\substr($host, -(\strlen($area))) === $area) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Applies the array of request options to a request.
 */
function apply_http_options(RequestInterface $request, array &$options): RequestInterface
{
    $modify = [
        'set_headers' => [],
    ];

    if (isset($options['version'])) {
        $modify['version'] = $options['version'];
    }

    if (isset($options['headers'])) {
        $modify['set_headers'] = $options['headers'];
        unset($options['headers']);
    }

    if (isset($options['form_params'])) {
        if (isset($options['multipart'])) {
            throw new InvalidArgumentException('You cannot use '
                . 'form_params and multipart at the same time. Use the '
                . 'form_params option if you want to send application/'
                . 'x-www-form-urlencoded requests, and the multipart '
                . 'option to send multipart/form-data requests.');
        }
        $options['body'] = \http_build_query($options['form_params'], '', '&');
        unset($options['form_params']);
        // Ensure that we don't have the header in different case and set the new value.
        $options['_conditional'] = Psr7\_caseless_remove(['Content-Type'], $options['_conditional']);
        $options['_conditional']['Content-Type'] = 'application/x-www-form-urlencoded';
    }

    if (isset($options['multipart'])) {
        $options['body'] = new Psr7\MultipartStream($options['multipart']);
        unset($options['multipart']);
    }

    if (isset($options['json'])) {
        $options['body'] = \GuzzleHttp\json_encode($options['json']);
        unset($options['json']);
        // Ensure that we don't have the header in different case and set the new value.
        $options['_conditional'] = Psr7\_caseless_remove(['Content-Type'], $options['_conditional']);
        $options['_conditional']['Content-Type'] = 'application/json';
    }

    if (!empty($options['decode_content'])
        && $options['decode_content'] !== true
    ) {
        // Ensure that we don't have the header in different case and set the new value.
        $options['_conditional'] = Psr7\_caseless_remove(['Accept-Encoding'], $options['_conditional']);
        $modify['set_headers']['Accept-Encoding'] = $options['decode_content'];
    }

    if (isset($options['body'])) {
        if (\is_array($options['body'])) {
            throw new InvalidArgumentException('Passing in the "body" request '
                . 'option as an array to send a POST request has been deprecated. '
                . 'Please use the "form_params" request option to send a '
                . 'application/x-www-form-urlencoded request, or the "multipart" '
                . 'request option to send a multipart/form-data request.');
        }
        $modify['body'] = Psr7\stream_for($options['body']);
        unset($options['body']);
    }

    if (!empty($options['auth']) && \is_array($options['auth'])) {
        $value = $options['auth'];
        $type = isset($value[2]) ? \strtolower($value[2]) : 'basic';
        switch ($type) {
            case 'basic':
                // Ensure that we don't have the header in different case and set the new value.
                $modify['set_headers'] = Psr7\_caseless_remove(['Authorization'], $modify['set_headers']);
                $modify['set_headers']['Authorization'] = 'Basic '
                    . \base64_encode("$value[0]:$value[1]");
                break;
            case 'digest':
                // @todo: Do not rely on curl
                $options['curl'][CURLOPT_HTTPAUTH] = CURLAUTH_DIGEST;
                $options['curl'][CURLOPT_USERPWD] = "$value[0]:$value[1]";
                break;
            case 'ntlm':
                $options['curl'][CURLOPT_HTTPAUTH] = CURLAUTH_NTLM;
                $options['curl'][CURLOPT_USERPWD] = "$value[0]:$value[1]";
                break;
        }
    }

    if (isset($options['query'])) {
        $value = $options['query'];
        if (\is_array($value)) {
            $value = \http_build_query($value, null, '&', PHP_QUERY_RFC3986);
        }
        if (!\is_string($value)) {
            throw new InvalidArgumentException('query must be a string or array');
        }
        $modify['query'] = $value;
        unset($options['query']);
    }

    // Ensure that sink is not an invalid value.
    if (isset($options['sink'])) {
        // TODO: Add more sink validation?
        if (\is_bool($options['sink'])) {
            throw new InvalidArgumentException('sink must not be a boolean');
        }
    }

    $request = Psr7\modify_request($request, $modify);
    if ($request->getBody() instanceof Psr7\MultipartStream) {
        // Use a multipart/form-data POST if a Content-Type is not set.
        // Ensure that we don't have the header in different case and set the new value.
        $options['_conditional'] = Psr7\_caseless_remove(['Content-Type'], $options['_conditional']);
        $options['_conditional']['Content-Type'] = 'multipart/form-data; boundary='
            . $request->getBody()->getBoundary();
    }

    // Merge in conditional headers if they are not present.
    if (isset($options['_conditional'])) {
        // Build up the changes so it's in a single clone of the message.
        $modify = [];
        foreach ($options['_conditional'] as $k => $v) {
            if (!$request->hasHeader($k)) {
                $modify['set_headers'][$k] = $v;
            }
        }
        $request = Psr7\modify_request($request, $modify);
        // Don't pass this internal value along to middleware/handlers.
        unset($options['_conditional']);
    }

    return $request;
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
 * @link http://www.php.net/manual/en/function.json-decode.php
 */
function json_decode(string $json, bool $assoc = false, int $depth = 512, int $options = 0)
{
    $data = \json_decode($json, $assoc, $depth, $options);
    if (JSON_ERROR_NONE !== \json_last_error()) {
        throw new Exception\InvalidArgumentException(
            'json_decode error: ' . \json_last_error_msg()
        );
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
 * @throws Exception\InvalidArgumentException if the JSON cannot be encoded.
 *
 * @link http://www.php.net/manual/en/function.json-encode.php
 */
function json_encode($value, int $options = 0, int $depth = 512): string
{
    $json = \json_encode($value, $options, $depth);
    if (JSON_ERROR_NONE !== \json_last_error()) {
        throw new Exception\InvalidArgumentException(
            'json_encode error: ' . \json_last_error_msg()
        );
    }

    return $json;
}

/**
 * Wrapper for the hrtime() or microtime() functions
 * (depending on the PHP version, one of the two is used)
 *
 * @return float|mixed UNIX timestamp
 *
 * @internal
 */
function _current_time()
{
    return \function_exists('hrtime') ? \hrtime(true) / 1e9 : \microtime(true);
}

/**
 * @internal
 */
function _idn_uri_convert(UriInterface $uri, int $options = 0): UriInterface
{
    if ($uri->getHost()) {
        $idnaVariant = defined('INTL_IDNA_VARIANT_UTS46') ? INTL_IDNA_VARIANT_UTS46 : 0;
        $asciiHost = $idnaVariant === 0
            ? idn_to_ascii($uri->getHost(), $options)
            : idn_to_ascii($uri->getHost(), $options, $idnaVariant, $info);
        if ($asciiHost === false) {
            $errorBitSet = isset($info['errors']) ? $info['errors'] : 0;

            $errorConstants = array_filter(array_keys(get_defined_constants()), function ($name) {
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
                $errorMessage .= ' (errors: ' . implode(', ', $errors) . ')';
            }

            throw new InvalidArgumentException($errorMessage);
        } else {
            if ($uri->getHost() !== $asciiHost) {
                // Replace URI only if the ASCII version is different
                $uri = $uri->withHost($asciiHost);
            }
        }
    }

    return $uri;
}

/**
 * @internal
 */
function _getenv(string $name): ?string
{
    if (isset($_SERVER[$name])) {
        return (string) $_SERVER[$name];
    }

    if (PHP_SAPI === 'cli' && ($value = \getenv($name)) !== false && $value !== null) {
        return (string) $value;
    }

    return null;
}
