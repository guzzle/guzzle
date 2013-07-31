<?php

namespace Guzzle\Url\QueryAggregator;

use Guzzle\Url\QueryString;

/**
 * Aggregates nested query string variables using PHP style arrays, but does not
 * combine duplicate values of the same name under array keys.
 *
 * Example: http://test.com?q=1&q=2&abc[123]=foo&abc[123]=bar
 */
class DuplicateAggregator implements QueryAggregatorInterface
{
    public function aggregate(array $query, $encType)
    {
        return $this->walkQuery($query, $encType, '');
    }

    protected function walkQuery(array $query, $encType, $keyPrefix)
    {
        $q = '';
        foreach ($query as $key => $value) {
            if ($keyPrefix) {
                $key = is_int($key) ? $keyPrefix : $keyPrefix . '[' . $key . ']';
            }
            if ($q) {
                $q .= '&';
            }
            if (is_array($value)) {
                $q .= $this->walkQuery($value, $encType, $key);
            } elseif ($encType == QueryString::RFC3986) {
                $q .= rawurlencode($key) . '=' . rawurlencode($value);
            } else {
                $q .= urlencode($key) . '=' . urlencode($value);
            }
        }

        return $q;
    }
}
