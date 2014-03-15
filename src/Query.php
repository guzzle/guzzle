<?php

namespace GuzzleHttp;

/**
 * Manages query string variables and can aggregate them into a string
 */
class Query extends Collection
{
    const RFC3986 = 'RFC3986';
    const RFC1738 = 'RFC1738';

    /** @var bool URL encode fields and values */
    private $encoding = self::RFC3986;

    /** @var callable */
    private $aggregator;

    /**
     * Parse a query string into a Query object
     *
     * @param string $query Query string to parse
     *
     * @return self
     */
    public static function fromString($query)
    {
        $q = new static();
        if ($query === '') {
            return $q;
        }

        $foundDuplicates = $foundPhpStyle = false;

        foreach (explode('&', $query) as $kvp) {
            $parts = explode('=', $kvp, 2);
            $key = rawurldecode($parts[0]);
            if ($paramIsPhpStyleArray = substr($key, -2) == '[]') {
                $foundPhpStyle = true;
                $key = substr($key, 0, -2);
            }
            if (isset($parts[1])) {
                $value = rawurldecode(str_replace('+', '%20', $parts[1]));
                if (isset($q[$key])) {
                    $q->add($key, $value);
                    $foundDuplicates = true;
                } elseif ($paramIsPhpStyleArray) {
                    $q[$key] = array($value);
                } else {
                    $q[$key] = $value;
                }
            } else {
                $q->add($key, null);
            }
        }

        // Use the duplicate aggregator if duplicates were found and not using
        // PHP style arrays.
        if ($foundDuplicates && !$foundPhpStyle) {
            $q->setAggregator(self::duplicateAggregator());
        }

        return $q;
    }

    /**
     * Convert the query string parameters to a query string string
     *
     * @return string
     */
    public function __toString()
    {
        if (!$this->data) {
            return '';
        }

        // The default aggregator is statically cached
        static $defaultAggregator;

        if (!$this->aggregator) {
            if (!$defaultAggregator) {
                $defaultAggregator = self::phpAggregator();
            }
            $this->aggregator = $defaultAggregator;
        }

        $result = '';
        $aggregator = $this->aggregator;

        foreach ($aggregator($this->data) as $key => $values) {
            foreach ($values as $value) {
                if ($result) {
                    $result .= '&';
                }
                if ($this->encoding == self::RFC1738) {
                    $result .= urlencode($key);
                    if ($value !== null) {
                        $result .= '=' . urlencode($value);
                    }
                } elseif ($this->encoding == self::RFC3986) {
                    $result .= rawurlencode($key);
                    if ($value !== null) {
                        $result .= '=' . rawurlencode($value);
                    }
                } else {
                    $result .= $key;
                    if ($value !== null) {
                        $result .= '=' . $value;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Controls how multi-valued query string parameters are aggregated into a
     * string.
     *
     *     $query->setAggregator($query::duplicateAggregator());
     *
     * @param callable $aggregator Callable used to convert a deeply nested
     *     array of query string variables into a flattened array of key value
     *     pairs. The callable accepts an array of query data and returns a
     *     flattened array of key value pairs where each value is an array of
     *     strings.
     *
     * @return self
     */
    public function setAggregator(callable $aggregator)
    {
        $this->aggregator = $aggregator;

        return $this;
    }

    /**
     * Specify how values are URL encoded
     *
     * @param string|bool $type One of 'RFC1738', 'RFC3986', or false to disable encoding
     *
     * @return self
     * @throws \InvalidArgumentException
     */
    public function setEncodingType($type)
    {
        if ($type === false || $type === self::RFC1738 || $type === self::RFC3986) {
            $this->encoding = $type;
        } else {
            throw new \InvalidArgumentException('Invalid URL encoding type');
        }

        return $this;
    }

    /**
     * Query string aggregator that does not aggregate nested query string
     * values and allows duplicates in the resulting array.
     *
     * Example: http://test.com?q=1&q=2
     *
     * @return callable
     */
    public static function duplicateAggregator()
    {
        return function (array $data) {
            return self::walkQuery($data, '', function ($key, $prefix) {
                return is_int($key) ? $prefix : "{$prefix}[{$key}]";
            });
        };
    }

    /**
     * Aggregates nested query string variables using the same technique as
     * ``http_build_query()``.
     *
     * @param bool $numericIndices Pass false to not include numeric indices
     *     when multi-values query string parameters are present.
     *
     * @return callable
     */
    public static function phpAggregator($numericIndices = true)
    {
        return function (array $data) use ($numericIndices) {
            return self::walkQuery(
                $data,
                '',
                function ($key, $prefix) use ($numericIndices) {
                    return !$numericIndices && is_int($key)
                        ? "{$prefix}[]"
                        : "{$prefix}[{$key}]";
                }
            );
        };
    }

    /**
     * Easily create query aggregation functions by providing a key prefix
     * function to this query string array walker.
     *
     * @param array    $query     Query string to walk
     * @param string   $keyPrefix Key prefix (start with '')
     * @param callable $prefixer  Function used to create a key prefix
     *
     * @return array
     */
    public static function walkQuery(array $query, $keyPrefix, callable $prefixer)
    {
        $result = [];
        foreach ($query as $key => $value) {
            if ($keyPrefix) {
                $key = $prefixer($key, $keyPrefix);
            }
            if (is_array($value)) {
                $result += self::walkQuery($value, $key, $prefixer);
            } elseif (isset($result[$key])) {
                $result[$key][] = $value;
            } else {
                $result[$key] = array($value);
            }
        }

        return $result;
    }
}
