<?php

namespace Guzzle;

use Guzzle\Common\Collection;

/**
 * Guzzle information and utility class
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class Guzzle
{
    const VERSION = '1.1';

    /**
     * @var string Default Guzzle User-Agent header
     */
    protected static $userAgent;

    /**
     * @var array cURL version information
     */
    protected static $curl;

    /**
     * Get the default User-Agent to add to requests sent through the library
     *
     * @return string
     */
    public static function getDefaultUserAgent()
    {
        if (!self::$userAgent) {
            $version = self::getCurlInfo();
            self::$userAgent = sprintf('Guzzle/%s (Language=PHP/%s; curl=%s; Host=%s)',
                Guzzle::VERSION,
                \PHP_VERSION,
                $version['version'],
                $version['host']
            );
        }
        
        return self::$userAgent;
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
     *     age
     *     features - A bitmask of the CURL_VERSION_XXX constants
     *     protocols - An array of protocols names supported by cURL
     *
     * @return array|string|float false Returns an array if no $type is
     *      provided, a string|float if a $type is provided and found, or false
     *      if a $type is provided and not found.
     */
    public static function getCurlInfo($type = null)
    {
        if (!self::$curl) {
            self::$curl = @curl_version();
            // @codeCoverageIgnoreStart
            if (!self::$curl) {
                throw new \RuntimeException('Guzzle requires curl');
            }
            // @codeCoverageIgnoreEnd
            // Check if CURLOPT_FOLLOWLOCATION is available
            self::$curl['follow_location'] = !ini_get('open_basedir');
        }
        
        if (!$type) {
            return self::$curl;
        } else if (isset(self::$curl[$type])) {
            return self::$curl[$type];
        } else {
            return false;
        }
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
        // Skip expensive regular expressions if it isn't needed
        if (strpos($input, '{{') === false) {
            return $input;
        }

        return preg_replace_callback('/{{\s*([A-Za-z_\-\.0-9]+)\s*}}/',
            function($matches) use ($config) {
                return $config->get(trim($matches[1]));
            }, $input
        );
    }

    /**
     * Reset the cached internal state
     */
    public static function reset()
    {
        self::$userAgent = null;
        self::$curl = null;
    }
}