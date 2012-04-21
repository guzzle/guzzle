<?php

namespace Guzzle\Service;

/**
 * Guzzle inflector class to transform snake_case to CamelCase and vice-versa.
 *
 * Previously computed values are cached internally using a capped array.  When
 * the cache is filled, the first 10% of the cached array will be removed.
 */
class Inflector
{
    /**
     * @var int Cap each internal cache
     */
    const MAX_ENTRIES_PER_CACHE = 1000;

    /**
     * @var array
     */
    protected static $cache = array(
        'snake' => array(),
        'camel' => array()
    );

    /**
     * Converts strings from camel case to snake case
     * (e.g. CamelCase camel_case).
     *
     * @param string $word Word to convert to snake case
     *
     * @return string
     */
    public static function snake($word)
    {
        if (isset(self::$cache['snake'][$word])) {
            return self::$cache['snake'][$word];
        }

        self::pruneCache('snake');

        return self::$cache['snake'][$word] = strtolower($word) == $word
            ? $word : strtolower(preg_replace('/(.)([A-Z])/', "$1_$2", $word));
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
        if (isset(self::$cache['camel'][$word])) {
            return self::$cache['camel'][$word];
        }

        self::pruneCache('camel');

        return self::$cache['camel'][$word] = str_replace(' ', '', ucwords(strtr($word, '_-', '  ')));
    }

    /**
     * Prune one of the caches
     *
     * @param string $cache Name of the cache to prune
     */
    private static function pruneCache($cache)
    {
        if (count(self::$cache[$cache]) == self::MAX_ENTRIES_PER_CACHE) {
            self::$cache[$cache] = array_slice(self::$cache[$cache], self::MAX_ENTRIES_PER_CACHE * 0.1);
        }
    }
}
