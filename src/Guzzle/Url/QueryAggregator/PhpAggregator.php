<?php

namespace Guzzle\Url\QueryAggregator;

use Guzzle\Url\QueryString;

/**
 * Aggregates nested query string variables using PHP style []
 */
class PhpAggregator extends AbstractAggregator
{
    private $numericIndices;

    /**
     * @param bool $numericIndices Set to false to disable numeric indices (e.g. foo[] = bar vs foo[0] = bar)
     */
    public function __construct($numericIndices = true)
    {
        $this->numericIndices = $numericIndices;
    }

    protected function createPrefixKey($key, $prefix)
    {
        return !$this->numericIndices && is_int($key) ? "{$prefix}[]" : "{$prefix}[{$key}]";
    }
}
