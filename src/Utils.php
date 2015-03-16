<?php
namespace GuzzleHttp;

use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Handler\Proxy;
use GuzzleHttp\Handler\StreamHandler;

/**
 * Utility methods used throughout Guzzle.
 */
final class Utils
{
    /**
     * Gets a value from an array using a path syntax to retrieve nested data.
     *
     * This method does not allow for keys that contain "/". You must traverse
     * the array manually or using something more advanced like JMESPath to
     * work with keys that contain "/".
     *
     *     // Get the bar key of a set of nested arrays.
     *     // This is equivalent to $collection['foo']['baz']['bar'] but won't
     *     // throw warnings for missing keys.
     *     GuzzleHttp\get_path($data, 'foo/baz/bar');
     *
     * @param array  $data Data to retrieve values from
     * @param string $path Path to traverse and retrieve a value from
     *
     * @return mixed|null
     */
    public static function getPath($data, $path)
    {
        $path = explode('/', $path);

        while (null !== ($part = array_shift($path))) {
            if (!is_array($data) || !isset($data[$part])) {
                return null;
            }
            $data = $data[$part];
        }

        return $data;
    }

    /**
     * Set a value in a nested array key. Keys will be created as needed to set
     * the value.
     *
     * This function does not support keys that contain "/" or "[]" characters
     * because these are special tokens used when traversing the data structure.
     * A value may be prepended to an existing array by using "[]" as the final
     * key of a path.
     *
     *     GuzzleHttp\get_path($data, 'foo/baz'); // null
     *     GuzzleHttp\set_path($data, 'foo/baz/[]', 'a');
     *     GuzzleHttp\set_path($data, 'foo/baz/[]', 'b');
     *     GuzzleHttp\get_path($data, 'foo/baz');
     *     // Returns ['a', 'b']
     *
     * @param array  $data  Data to modify by reference
     * @param string $path  Path to set
     * @param mixed  $value Value to set at the key
     *
     * @throws \RuntimeException when trying to setPath using a nested path
     *     that travels through a scalar value.
     */
    public static function setPath(&$data, $path, $value)
    {
        $queue = explode('/', $path);
        // Optimization for simple sets.
        if (count($queue) === 1) {
            $data[$path] = $value;
            return;
        }

        $current =& $data;
        while (null !== ($key = array_shift($queue))) {
            if (!is_array($current)) {
                throw new \RuntimeException("Trying to setPath {$path}, but "
                    . "{$key} is set and is not an array");
            } elseif (!$queue) {
                if ($key == '[]') {
                    $current[] = $value;
                } else {
                    $current[$key] = $value;
                }
            } elseif (isset($current[$key])) {
                $current =& $current[$key];
            } else {
                $current[$key] = [];
                $current =& $current[$key];
            }
        }
    }

    /**
     * Expands a URI template
     *
     * @param string $template  URI template
     * @param array  $variables Template variables
     *
     * @return string
     */
    public static function uriTemplate($template, array $variables)
    {
        if (function_exists('\\uri_template')) {
            return \uri_template($template, $variables);
        }

        static $uriTemplate;
        if (!$uriTemplate) {
            $uriTemplate = new UriTemplate();
        }

        return $uriTemplate->expand($template, $variables);
    }

    /**
     * Wrapper for JSON decode that implements error detection with helpful
     * error messages.
     *
     * @param string $json    JSON data to parse
     * @param bool $assoc     When true, returned objects will be converted
     *                        into associative arrays.
     * @param int    $depth   User specified recursion depth.
     * @param int    $options Bitmask of JSON decode options.
     *
     * @return mixed
     * @throws \InvalidArgumentException if the JSON cannot be parsed.
     * @link http://www.php.net/manual/en/function.json-decode.php
     */
    public static function jsonDecode($json, $assoc = false, $depth = 512, $options = 0)
    {
        static $jsonErrors = [
            JSON_ERROR_DEPTH => 'JSON_ERROR_DEPTH - Maximum stack depth exceeded',
            JSON_ERROR_STATE_MISMATCH => 'JSON_ERROR_STATE_MISMATCH - Underflow or the modes mismatch',
            JSON_ERROR_CTRL_CHAR => 'JSON_ERROR_CTRL_CHAR - Unexpected control character found',
            JSON_ERROR_SYNTAX => 'JSON_ERROR_SYNTAX - Syntax error, malformed JSON',
            JSON_ERROR_UTF8 => 'JSON_ERROR_UTF8 - Malformed UTF-8 characters, possibly incorrectly encoded'
        ];

        $data = \json_decode($json, $assoc, $depth, $options);

        if (JSON_ERROR_NONE !== json_last_error()) {
            $last = json_last_error();
            throw new \InvalidArgumentException(
                'Unable to parse JSON data: '
                . (isset($jsonErrors[$last])
                    ? $jsonErrors[$last]
                    : 'Unknown error')
            );
        }

        return $data;
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
     * @return string
     * @throws \RuntimeException if no bundle can be found.
     */
    public static function defaultCaBundle()
    {
        static $cached = null;
        static $cafiles = [
            // Red Hat, CentOS, Fedora (provided by the ca-certificates package)
            '/etc/pki/tls/certs/ca-bundle.crt',
            // Ubuntu, Debian (provided by the ca-certificates package)
            '/etc/ssl/certs/ca-certificates.crt',
            // FreeBSD (provided by the ca_root_nss package)
            '/usr/local/share/certs/ca-root-nss.crt',
            // OS X provided by homebrew (using the default path)
            '/usr/local/etc/openssl/cert.pem',
            // Windows?
            'C:\\windows\\system32\\curl-ca-bundle.crt',
            'C:\\windows\\curl-ca-bundle.crt',
        ];

        if ($cached) {
            return $cached;
        }

        if ($ca = ini_get('openssl.cafile')) {
            return $cached = $ca;
        }

        if ($ca = ini_get('curl.cainfo')) {
            return $cached = $ca;
        }

        foreach ($cafiles as $filename) {
            if (file_exists($filename)) {
                return $cached = $filename;
            }
        }

        throw new \RuntimeException(<<< EOT
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
     * Debug function used to describe the provided value type and class.
     *
     * @param mixed $input
     *
     * @return string Returns a string containing the type of the variable and
     *                if a class is provided, the class name.
     */
    public static function describeType($input)
    {
        switch (gettype($input)) {
            case 'object':
                return 'object(' . get_class($input) . ')';
            case 'array':
                return 'array(' . count($input) . ')';
            default:
                ob_start();
                var_dump($input);
                // normalize float vs double
                return str_replace('double(', 'float(', rtrim(ob_get_clean()));
        }
    }

    /**
     * Parses an array of header lines into an associative array of headers.
     *
     * @param array $lines Header lines array of strings in the following
     *                     format: "Name: Value"
     * @return array
     */
    public static function headersFromLines($lines)
    {
        $headers = [];

        foreach ($lines as $line) {
            $parts = explode(':', $line, 2);
            $headers[trim($parts[0])][] = isset($parts[1])
                ? trim($parts[1])
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
    public static function getDebugResource($value = null)
    {
        if (is_resource($value)) {
            return $value;
        } elseif (defined('STDOUT')) {
            return STDOUT;
        }

        return fopen('php://output', 'w');
    }

    /**
     * Create a default handler to use based on the environment
     *
     * @throws \RuntimeException if no viable Handler is available.
     * @return callable Returns the best handler for the given system.
     */
    public static function defaultHandler()
    {
        $handler = null;
        if (extension_loaded('curl')) {
            $config = [];
            if ($maxHandles = getenv('MUZZLE_CURL_MAX_HANDLES')) {
                $config['max_handles'] = $maxHandles;
            }
            $handler = new CurlMultiHandler($config);
            if (function_exists('curl_reset')) {
                $handler = Proxy::wrapSync($handler, new CurlHandler());
            }
        }

        if (ini_get('allow_url_fopen')) {
            if ($handler) {
                $handler = Proxy::wrapStreaming($handler, new StreamHandler());
            } else {
                $handler = new StreamHandler();
            }
        } elseif (!$handler) {
            throw new \RuntimeException('GuzzleHttp requires cURL, the '
                . 'allow_url_fopen ini setting, or a custom HTTP handler.');
        }

        return $handler;
    }

    /**
     * Get the default User-Agent string to use with Guzzle
     *
     * @return string
     */
    public static function defaultUserAgent()
    {
        static $defaultAgent = '';

        if (!$defaultAgent) {
            $defaultAgent = 'GuzzleHttp/' . Client::VERSION;
            if (extension_loaded('curl')) {
                $defaultAgent .= ' curl/' . \curl_version()['version'];
            }
            $defaultAgent .= ' PHP/' . PHP_VERSION;
        }

        return $defaultAgent;
    }
}
