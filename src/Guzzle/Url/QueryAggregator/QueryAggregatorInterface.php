<?php

namespace Guzzle\Url\QueryAggregator;

use Guzzle\Url\QueryString;

/**
 * Interface used for aggregating a query string into a string
 *
 * Null values must not be represented in the query string. An empty string must
 * still be present when a query string is cast to a string (e.g. "foo=").
 */
interface QueryAggregatorInterface
{
    /**
     * Aggregate a query string array into a string
     *
     * @param array  $query   Query string parameters
     * @param string $encType How parameters are url encoded
     *
     * @return string
     */
    public function aggregate(array $query, $encType);
}
