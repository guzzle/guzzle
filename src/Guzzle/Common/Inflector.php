<?php

namespace Guzzle\Common;

/**
 * Guzzle inflector class to transform snake_case to CamelCase and vice-versa.
 *
 * Previously computed values are cached internally using a capped array.  When
 * the cache is filled, the first 10% of the cached array will be removed.
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class Inflector
{
    /**
     * @var int Cap each internal cache
     */
    const MAX_ENTRIES_PER_CACHE = 1000;

    /**
     * @var array snake_case transformation cache
     */
    protected static $snakeCache = array();

    /**
     * @var array CamelCase transformation cache
     */
    protected static $camelCache = array();

    /**
     * Converts strings from camel case to snake case
     * (e.g. CamelCase camel_case)
     *
     * Borrowed from Magento
     *
     * @param string $word Word to convert to snake case
     *
     * @return string
     */
    public static function snake($word)
    {
        static $cached = 0;

        if (isset(self::$snakeCache[$word])) {
            return self::$snakeCache[$word];
        }

        if (strtolower($word) == $word) {
            $result = $word;
        } else {
            $result = strtolower(preg_replace('/(.)([A-Z])/', "$1_$2", $word));
        }

        if (++$cached > self::MAX_ENTRIES_PER_CACHE) {
            $toRemove = self::MAX_ENTRIES_PER_CACHE * 0.1;
            self::$snakeCache = array_slice(self::$snakeCache, $toRemove);
            $cached -= $toRemove;
        }

        return self::$snakeCache[$word] = $result;
    }

    /**
     * Converts strings from snake_case to upper CamelCase
     *
     * @param string $word Value to convert into upper CamelCase
     *
     * @return string
     */
    public static function camel($word)
    {
        static $cached = 0;

        if (isset(self::$camelCache[$word])) {
            return self::$camelCache[$word];
        }

        $result = str_replace(' ', '', ucwords(strtr($word, '_-', '  ')));

        if (++$cached > self::MAX_ENTRIES_PER_CACHE) {
            $toRemove = self::MAX_ENTRIES_PER_CACHE * 0.1;
            self::$camelCache = array_slice(self::$camelCache, $toRemove);
            $cached -= $toRemove;
        }

        return self::$camelCache[$word] = $result;
    }

    /**
     * Get cache information from the inflector
     *
     * @return array Returns an array containing a snake and camel key, and each
     *      value of each cache in a sub-array
     */
    public static function getCache()
    {
        return array(
            'snake' => self::$snakeCache,
            'camel' => self::$camelCache
        );
    }
}