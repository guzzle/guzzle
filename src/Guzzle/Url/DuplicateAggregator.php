<?php

namespace Guzzle\Url;

/**
 * Aggregates nested query string variables using PHP style arrays, but does not
 * combine duplicate values of the same name under array keys.
 */
class DuplicateAggregator extends AbstractAggregator
{
    protected function createPrefixKey($key, $keyPrefix)
    {
        return is_int($key) ? $keyPrefix : "{$keyPrefix}[{$key}]";
    }
}
