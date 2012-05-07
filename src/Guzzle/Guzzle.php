<?php

namespace Guzzle;

use Guzzle\Common\Collection;

/**
 * Guzzle information and utility class
 */
class Guzzle
{
    const VERSION = '2.4.0';

    /**
     * @var array Guzzle cache
     */
    protected static $cache;

    /**
     * Get the default User-Agent to add to requests sent through the library
     *
     * @return string
     */
    public static function getDefaultUserAgent()
    {
        if (!isset(self::$cache['user_agent'])) {
            self::$cache['user_agent'] = sprintf('Guzzle/%s (PHP=%s; curl=%s; openssl=%s)',
                self::VERSION,
                \PHP_VERSION,
                self::getCurlInfo('version'),
                self::getCurlInfo('ssl_version')
            );
        }

        return self::$cache['user_agent'];
    }

    /**
     * Get curl version information and caches a local copy for fast re-use
     *
     * @param $type (optional) Version information to retrieve
     *     version_number - cURL 24 bit version number
     *     version - cURL version number, as a string
     *     ssl_version_number - OpenSSL 24 bit version number
     *     ssl_version - OpenSSL version number, as a string
     *     libz_version - zlib version number, as a string
     *     host - Information about the host where cURL was built
     *     features - A bitmask of the CURL_VERSION_XXX constants
     *     protocols - An array of protocols names supported by cURL
     *
     * @return array|string|float false Returns an array if no $type is
     *      provided, a string|float if a $type is provided and found, or false
     *      if a $type is provided and not found.
     */
    public static function getCurlInfo($type = null)
    {
        if (!isset(self::$cache['curl'])) {
            self::$cache['curl'] = curl_version();
            // Check if CURLOPT_FOLLOWLOCATION is available
            self::$cache['curl']['follow_location'] = !ini_get('open_basedir');
        }

        return !$type
            ? self::$cache['curl']
            : (isset(self::$cache['curl'][$type]) ? self::$cache['curl'][$type] : false);
    }

    /**
     * Create an RFC 1123 HTTP-Date from various date values
     *
     * @param string|int $date Date to convert
     *
     * @return string
     */
    public static function getHttpDate($date)
    {
        if (!is_numeric($date)) {
            $date = strtotime($date);
        }

        return gmdate('D, d M Y H:i:s', $date) . ' GMT';
    }

    /**
     * Inject configuration settings into an input string
     *
     * @param string $input Input to inject
     * @param Collection $config Configuration data to inject into the input
     *
     * @return string
     */
    public static function inject($input, Collection $config)
    {
        // Only perform the preg callback if needed
        if (strpos($input, '{') === false) {
            return $input;
        }

        return preg_replace_callback('/{\s*([A-Za-z_\-\.0-9]+)\s*}/', array($config, 'getPregMatchValue'), $input);
    }

    /**
     * Reset the cached internal state
     */
    public static function reset()
    {
        self::$cache = array();
    }
}
