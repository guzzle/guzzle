<?php

namespace Guzzle\Http;

use Guzzle\Common\Version;
use Guzzle\Http\Curl\CurlVersion;

/**
 * HTTP utility class
 */
class Utils
{
    /**
     * @var string
     */
    protected static $userAgent;

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
     * Get the default User-Agent to add to requests sent through the library
     *
     * @return string
     */
    public static function getDefaultUserAgent()
    {
        if (!self::$userAgent) {
            self::$userAgent = sprintf(
                'Guzzle/%s curl/%s PHP/%s',
                Version::VERSION,
                CurlVersion::getInstance()->get('version'),
                PHP_VERSION
            );
        }

        return self::$userAgent;
    }
}
