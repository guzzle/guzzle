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
     * @var bool Are the field names being urlencoded?
     */
    protected $encodeFields = true;

    /**
     * @var bool Are the field values being urlencoded?
     */
    protected $encodeValues = true;

    /**
     * @var function A callback fuction for combining multi-valued query string values
     */
    protected $aggregator = null;

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

        foreach (explode('&', $query) as $kvp) {
            $parts = explode('=', $kvp);
            $key = rawurldecode($parts[0]);
            if (substr($key, -2) == '[]') {
                $key = substr($key, 0, -2);
            }
            $value = array_key_exists(1, $parts) ? rawurldecode($parts[1]) : null;
            $q->add($key, $value);
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

        foreach ($this->encodeData($this->data, $this->encodeFields, $this->encodeValues) as $name => $value) {
            $value = $value !== null ? (array) $value : array(false);
            foreach ($value as $v) {
                if (!$firstValue) {
                    $queryString .= $this->fieldSeparator;
                }
                $queryString .= $name;
                if (is_string($v)) {
                    $queryString .= $this->valueSeparator . $v;
                }
                $firstValue = false;
            }
        }

        return $queryString;
    }

    /**
     * Aggregate multi-valued parameters using PHP style syntax
     *
     * @param string $key          The name of the query string parameter
     * @param array  $value        The values of the parameter
     * @param bool   $encodeFields Set to TRUE to encode field names
     * @param bool   $encodeValues Set to TRUE to encode values
     *
     * @return array Returns an array of the combined values
     */
    public function aggregateUsingPhp($key, array $value, $encodeFields = false, $encodeValues = false)
    {
        $ret = array();

        foreach ($value as $k => $v) {

            $k = $key . '[' . $k . ']';

            if (is_array($v)) {
                $ret = array_merge($ret, $this->aggregateUsingPhp($k, $v, $encodeFields, $encodeValues));
            } else {
                if ($encodeFields) {
                    $k = rawurlencode($k);
                }
                $v = $encodeValues ? rawurlencode($v) : $v;
                $ret[$k] = $v;
            }
        }

        return $ret;
    }

    /**
     * Aggregate multi-valued parameters by joining the values using a comma
     *
     * <code>
     *     $q = new \Guzzle\Http\QueryString(array(
     *         'value' => array(1, 2, 3)
     *     ));
     *     $q->setAggregateFunction(array($q, 'aggregateUsingComma'));
     *     echo $q; // outputs: ?value=1,2,3
     * </code>
     *
     * @param string $key          The name of the query string parameter
     * @param array  $value        The values of the parameter
     * @param bool   $encodeFields Set to TRUE to encode field names
     * @param bool   $encodeValues Set to TRUE to encode values
     *
     * @return array Returns an array of the combined values
     */
    public function aggregateUsingComma($key, array $value, $encodeFields = false, $encodeValues = false)
    {
        return array(
            $encodeFields ? rawurlencode($key) : $key => $encodeValues
                ? implode(',', array_map('rawurlencode', $value))
                : implode(',', $value)
        );
    }

    /**
     * Aggregate multi-valued parameters using duplicate values in a query string
     *
     * Example: http://test.com?q=1&q=2
     *
     * @param string $key          The name of the query string parameter
     * @param array  $value        The values of the parameter
     * @param bool   $encodeFields Set to TRUE to encode field names
     * @param bool   $encodeValues Set to TRUE to encode values
     *
     * @return array Returns an array of the combined values
     */
    public function aggregateUsingDuplicates($key, array $value, $encodeFields = false, $encodeValues = false)
    {
        return array(
            $encodeFields ? rawurlencode($key) : $key => $encodeValues
                ? array_map('rawurlencode', $value)
                : $value
        );
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
     * Returns whether or not field names are being urlencoded when converting
     * the query string object to a string
     *
     * @return bool
     */
    public function isEncodingFields()
    {
        return $this->encodeFields;
    }

    /**
     * Returns whether or not values are being urlencoded when converting
     * the query string object to a string
     *
     * @return bool
     */
    public function isEncodingValues()
    {
        return $this->encodeValues;
    }

    /**
     * Provide a function for combining multi-valued query string parameters
     * into a single or multiple fields
     *
     * @param callback|null $callback A function or callback array that accepts
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
     * Set whether or not field names should be urlencoded when converting
     * the query string object to a string
     *
     * @param bool $encode Set to TRUE to encode field names, FALSE to not encode field names
     *
     * @return QueryString
     */
    public function setEncodeFields($encode)
    {
        $this->encodeFields = $encode;

        return $this;
    }

   /**
     * Set whether or not field values should be urlencoded when converting
     * the query string object to a string
     *
     * @param bool $encode Set to TRUE to encode field values, FALSE to not encode field values
     *
     * @return QueryString
     */
    public function setEncodeValues($encode)
    {
        $this->encodeValues = $encode;

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
        return $this->encodeData($this->data, $this->encodeFields, $this->encodeValues);
    }

    /**
     * Url encode parameter data.
     *
     * If a parameter value is an array and no aggregator has been set, the
     * values of the array will be converted into a PHP compatible form array.
     * If an aggregator is set, the values will be converted using the
     * aggregator function
     *
     * @param array $data         The data to encode
     * @param bool  $encodeFields Toggle URL encoding of fields
     * @param bool  $encodeValues Toggle URL encoding of values
     *
     * @return array Returns an array of encoded values and keys
     */
    protected function encodeData(array $data, $encodeFields = true, $encodeValues = true)
    {
        if (!$this->aggregator) {
            $this->aggregator = array($this, 'aggregateUsingPhp');
        }

        $temp = array();
        foreach ($data as $key => &$value) {
            if (is_array($value)) {
                $encoded = $this->encodeData($value, $encodeFields, $encodeValues);
                $temp = array_merge($temp, call_user_func_array($this->aggregator, array($key, $value, $encodeFields, $encodeValues)));
            } else {
                if ($encodeValues && is_string($value) || is_numeric($value)) {
                    $value = rawurlencode($value);
                }
                if ($encodeFields) {
                    $temp[rawurlencode($key)] = $value;
                } else {
                    $temp[$key] = $value;
                }
            }
        }

        return $temp;
    }
}
