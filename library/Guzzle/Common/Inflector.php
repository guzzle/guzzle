<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Common;

/**
 * Static inflector class
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class Inflector
{
    /**
     * @var array Setter/Getter snake transformation cache
     */
    protected static $snakeCache = array();

    /**
     * @var array Setter/Getter camelCase transformation cache
     */
    protected static $camelCache = array();

    /**
     * Converts strings from camel case to snake case
     * (e.g. CamelCase camel_case)
     *
     * Borrowed from Magento
     *
     * @param string $word Word to convert to snake case
     * @param bool $skipCache (optional) Set to TRUE to not cache the result
     *
     * @return string
     */
    public static function snake($word, $skipCache = false)
    {
        if (!$skipCache && isset(self::$snakeCache[$word])) {
            return self::$snakeCache[$word];
        }

        if (strtolower($word) == $word) {
            $result = $word;
        } else {
            $result = strtolower(preg_replace('/(.)([A-Z])/', "$1_$2", $word));
        }

        if (!$skipCache) {
            self::$snakeCache[$word] = $result;
        }

        return $result;
    }

    /**
     * Converts strings from snake case to camel case (e.g. snake_case
     * CamelCase)
     *
     * @param string $word Value to camelize
     * @param bool $skipCache (optional) Set to TRUE to not cache the result
     *
     * @return string
     */
    public static function camel($word, $skipCache = false)
    {
        if (!$skipCache && isset(self::$camelCache[$word])) {
            return self::$camelCache[$word];
        }

        $result = lcfirst(str_replace(' ', '', ucwords(strtr($word, '_-', '  '))));

        if (!$skipCache) {
            self::$camelCache[$word] = $result;
        }

        return $result;
    }
}