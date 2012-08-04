<?php

namespace Guzzle\Http;

use Guzzle\Common\Collection;

/**
 * Query string object to handle managing query string parameters and
 * aggregating those parameters together as a string.
 */
class QueryString extends Collection
{
    /**
     * @var string Constant used to create blank query string values (e.g. ?foo)
     */
    const BLANK = "_guzzle_blank_";

    /**
     * @var string The query string field separator (e.g. '&')
     */
    protected $fieldSeparator = '&';

    /**
     * @var string The query string value separator (e.g. '=')
     */
    protected $valueSeparator = '=';

    /**
     * @var string The query string prefix
     */
    protected $prefix = '?';

    /**
     * @var bool URL encode fields and values?
     */
    protected $urlEncode = true;

    /**
     * @var callable A callback function for combining multi-valued query string values
     */
    protected $aggregator = array(__CLASS__, 'aggregateUsingPhp');

    /**
     * Parse a query string into a QueryString object
     *
     * @param string $query Query string to parse
     *
     * @return QueryString
     */
    public static function fromString($query)
    {
        $q = new static();

        if (!empty($query)) {
            if ($query[0] == '?') {
                $query = substr($query, 1);
            }
            foreach (explode('&', $query) as $kvp) {
                $parts = explode('=', $kvp);
                $key = rawurldecode($parts[0]);

                if (substr($key, -2) == '[]') {
                    $key = substr($key, 0, -2);
                }

                if (array_key_exists(1, $parts)) {
                    $q->add($key, rawurldecode(str_replace('+', '%20', $parts[1])));
                } else {
                    $q->add($key, '');
                }
            }
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
        if (empty($this->data)) {
            return '';
        }

        $queryString = $this->prefix;
        $firstValue = true;

        foreach ($this->encodeData($this->data) as $name => $value) {
            $value = $value === null ? array('') : (array) $value;
            foreach ($value as $v) {
                if ($firstValue) {
                    $firstValue = false;
                } else {
                    $queryString .= $this->fieldSeparator;
                }
                $queryString .= $name;
                if ($v !== self::BLANK) {
                    $queryString .= $this->valueSeparator . $v;
                }
            }
        }

        return $queryString;
    }

    /**
     * Aggregate multi-valued parameters using PHP style syntax
     *
     * @param string $key    The name of the query string parameter
     * @param array  $value  The values of the parameter
     * @param bool   $encode Set to TRUE to encode field names and values
     *
     * @return array Returns an array of the combined values
     */
    public static function aggregateUsingPhp($key, array $value, $encode = false)
    {
        $ret = array();

        foreach ($value as $k => $v) {
            $k = "{$key}[{$k}]";
            if (is_array($v)) {
                $ret = array_merge($ret, self::aggregateUsingPhp($k, $v, $encode));
            } else {
                if ($encode) {
                    $ret[rawurlencode($k)] = rawurlencode($v);
                } else {
                    $ret[$k] = $v;
                }
            }
        }

        return $ret;
    }

    /**
     * Aggregate multi-valued parameters by joining the values using a comma
     *
     * @param string $key    The name of the query string parameter
     * @param array  $value  The values of the parameter
     * @param bool   $encode Set to TRUE to encode field names and values
     *
     * @return array Returns an array of the combined values
     */
    public static function aggregateUsingComma($key, array $value, $encode = false)
    {
        return $encode
            ? array(rawurlencode($key) => implode(',', array_map('rawurlencode', $value)))
            : array($key => implode(',', $value));
    }

    /**
     * Aggregate multi-valued parameters using duplicate values in a query string
     *
     * Example: http://test.com?q=1&q=2
     *
     * @param string $key    The name of the query string parameter
     * @param array  $value  The values of the parameter
     * @param bool   $encode Set to TRUE to encode field names and values
     *
     * @return array Returns an array of the combined values
     */
    public static function aggregateUsingDuplicates($key, array $value, $encode = false)
    {
        return $encode
            ? array(rawurlencode($key) => array_map('rawurlencode', $value))
            : array($key => $value);
    }

    /**
     * Get the query string field separator
     *
     * @return string
     */
    public function getFieldSeparator()
    {
        return $this->fieldSeparator;
    }

    /**
     * Get the query string prefix
     *
     * @return string
     */
    public function getPrefix()
    {
        return $this->prefix;
    }

    /**
     * Get the query string value separator
     *
     * @return string
     */
    public function getValueSeparator()
    {
        return $this->valueSeparator;
    }

    /**
     * Returns whether or not field names and values will be urlencoded
     *
     * @return bool
     */
    public function isUrlEncoding()
    {
        return $this->urlEncode;
    }

    /**
     * Provide a function for combining multi-valued query string parameters
     * into a single or multiple fields
     *
     * @param callable|null $callback A function or callback array that accepts
     *      a $key, $value, $encodeFields, and $encodeValues as arguments and
     *      returns an associative array containing the combined values.  Set
     *      to null to remove any custom aggregator.
     *
     * @return QueryString
     *
     * @see \Guzzle\Http\QueryString::aggregateUsingComma()
     */
    public function setAggregateFunction($callback)
    {
        $this->aggregator = $callback;
    }

    /**
     * Set whether or not field names and values should be rawurlencoded
     *
     * @param bool $encode Set whether or not to encode
     *
     * @return QueryString
     */
    public function useUrlEncoding($encode)
    {
        $this->urlEncode = $encode;

        return $this;
    }

    /**
     * Set the query string separator
     *
     * @param string $separator The query string separator that will separate fields
     *
     * @return QueryString
     */
    public function setFieldSeparator($separator)
    {
        $this->fieldSeparator = $separator;

        return $this;
    }

    /**
     * Set the query string prefix
     *
     * @param string $prefix Prefix to use with the query string (e.g. '?')
     *
     * @return QueryString
     */
    public function setPrefix($prefix)
    {
        $this->prefix = $prefix;

        return $this;
    }

    /**
     * Set the query string value separator
     *
     * @param string $separator The query string separator that will separate values from fields
     *
     * @return QueryString
     */
    public function setValueSeparator($separator)
    {
        $this->valueSeparator = $separator;

        return $this;
    }

    /**
     * Returns an array of url encoded field names and values
     *
     * @return array
     */
    public function urlEncode()
    {
        return $this->encodeData($this->data);
    }

    /**
     * Url encode parameter data.
     *
     * If a parameter value is an array and no aggregator has been set, the
     * values of the array will be converted into a PHP compatible form array.
     * If an aggregator is set, the values will be converted using the
     * aggregator function
     *
     * @param array $data The data to encode
     *
     * @return array Returns an array of encoded values and keys
     */
    protected function encodeData(array $data)
    {
        $temp = array();
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $temp = array_merge($temp, call_user_func_array($this->aggregator, array($key, $value, $this->urlEncode)));
            } else {
                if ($this->urlEncode) {
                    $temp[rawurlencode($key)] = rawurlencode($value);
                } else {
                    $temp[$key] = (string) $value;
                }
            }
        }

        return $temp;
    }
}
