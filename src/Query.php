<?php
namespace GuzzleHttp;

/**
 * Manages query string variables and can aggregate them into a string
 */
class Query extends Collection
{
    const RFC3986 = 'RFC3986';
    const RFC1738 = 'RFC1738';

    /** @var callable Encoding function */
    private $encoding = 'rawurlencode';
    /** @var callable */
    private $aggregator;

    /**
     * Parse a query string into a Query object
     *
     * $urlEncoding is used to control how the query string is parsed and how
     * it is ultimately serialized. The value can be set to one of the
     * following:
     *
     * - true: (default) Parse query strings using RFC 3986 while still
     *   converting "+" to " ".
     * - false: Disables URL decoding of the input string and URL encoding when
     *   the query string is serialized.
     * - 'RFC3986': Use RFC 3986 URL encoding/decoding
     * - 'RFC1738': Use RFC 1738 URL encoding/decoding
     *
     * @param string      $query       Query string to parse
     * @param bool|string $urlEncoding Controls how the input string is decoded
     *                                 and encoded.
     * @return self
     */
    public static function fromString($query, $urlEncoding = true)
    {
        static $qp;
        if (!$qp) {
            $qp = new QueryParser();
        }

        $q = new static();

        if ($urlEncoding !== true) {
            $q->setEncodingType($urlEncoding);
        }

        $qp->parseInto($q, $query, $urlEncoding);

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
        $encoder = $this->encoding;

        foreach ($aggregator($this->data) as $key => $values) {
            foreach ($values as $value) {
                if ($result) {
                    $result .= '&';
                }
                $result .= $encoder($key);
                if ($value !== null) {
                    $result .= '=' . $encoder($value);
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
     */
    public function setAggregator(callable $aggregator)
    {
        $this->aggregator = $aggregator;
    }

    /**
     * Specify how values are URL encoded
     *
     * @param string|bool $type One of 'RFC1738', 'RFC3986', or false to disable encoding
     *
     * @throws \InvalidArgumentException
     */
    public function setEncodingType($type)
    {
        switch ($type) {
            case self::RFC3986:
                $this->encoding = 'rawurlencode';
                break;
            case self::RFC1738:
                $this->encoding = 'urlencode';
                break;
            case false:
                $this->encoding = function ($v) { return $v; };
                break;
            default:
                throw new \InvalidArgumentException('Invalid URL encoding type');
        }
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
