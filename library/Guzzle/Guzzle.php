<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle;

use Guzzle\Common\Collection;

/**
 * Guzzle information and utility class
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class Guzzle
{
    const VERSION = '0.9';

    /**
     * @var string Default Guzzle User-Agent header
     */
    protected static $userAgent;

    /**
     * Get the default User-Agent to add to HTTP headers sent through the
     * library
     *
     * @return string
     */
    public static function getDefaultUserAgent()
    {
        // @codeCoverageIgnoreStart
        if (!self::$userAgent) {
            $version = curl_version();
            self::$userAgent = sprintf('Guzzle/%s (Language=PHP/%s; curl=%s; Host=%s)', Guzzle::VERSION, \PHP_VERSION, $version['version'], $version['host']);
        }
        // @codeCoverageIgnoreEnd

        return self::$userAgent;
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
        return gmdate('D, d M Y H:i:s', (!is_numeric($date)) ? strtotime($date) : $date) . ' GMT';
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
}