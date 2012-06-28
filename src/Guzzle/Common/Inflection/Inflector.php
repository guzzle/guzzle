<?php

namespace Guzzle\Common\Inflection;

/**
 * Default inflection implementation
 */
class Inflector implements InflectorInterface
{
    /**
     * @var InflectorInterface
     */
    protected static $default;

    /**
     * Get the default inflector object that has support for caching
     *
     * @return MemoizingInflector
     */
    public static function getDefault()
    {
        if (!self::$default) {
            self::$default = new MemoizingInflector(new self());
        }

        return self::$default;
    }

    /**
     * {@inheritdoc}
     */
    public function snake($word)
    {
        return ctype_lower($word) ? $word : strtolower(preg_replace('/(.)([A-Z])/', "$1_$2", $word));
    }

    /**
     * {@inheritdoc}
     */
    public function camel($word)
    {
        return str_replace(' ', '', ucwords(strtr($word, '_-', '  ')));
    }
}
