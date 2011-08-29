<?php

namespace Guzzle\Http;

use Guzzle\Common\Collection;

/**
 * Query string object to handle managing query string parameters and
 * aggregating those parameters together as a string.
 *
 * @author  michael@guzzlephp.org
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
     * URL encode a string and convert any special characters as needed.
     *
     * @param string $string String to URL encode.
     * @param array $doNotEncode Array of characters that should not be encoded.
     *
     * @return string Returns the encoded string.
     */
    public static function rawurlencode($string, array $doNotEncode = null)
    {
        $result = rawurlencode($string);
        if (empty($doNotEncode)) {
            return $result;
        } else {
            $encoded = array();
            foreach ($doNotEncode as $char) {
                $encoded[] = rawurlencode($char);
            }

            return str_replace($encoded, $doNotEncode, $result);
        }
    }

    /**
     * Convert the querystring parameters to a querystring string
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
     * Aggregate multi-valued parameters by joining the values using a comma
     *
     * <code>
     *     $queryString = new \Guzzle\Http\QueryString();
     *     $queryString->setAggregator($queryString->aggregateUsingComma);
     *     $queryString->replace(array(
     *         'value' => array(1, 2, 3)
     *     ));
     *
     *     echo $queryString; // outputs: ?value=1,2,3
     * </code>
     *
     * @param string $key The name of the query string parameter
     * @param array $values The values of the parameter
     * @param bool $encodeFields (optional) Set to TRUE to encode field names
     * @param bool $encodeValues (optional) Set to TRUE to encode values
     *
     * @return array Returns an array of the combined values
     */
    public function aggregateUsingComma($key, $value, $encodeFields = false, $encodeValues = false)
    {
        return array(
            (($encodeFields) ? rawurlencode($key) : $key) => (($encodeValues)
                ? implode(',', array_map(array(__CLASS__, 'rawurlencode'), $value))
                : implode(',', $value))
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
     * @return \Guzzle\Http\QueryString Provides a fluent interface
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
     * @param bool $encode Set to TRUE to encode field names, FALSE to not
     *      encode field names
     *
     * @return \Guzzle\Http\QueryString Provides a fluent interface
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
     * @param bool $encode Set to TRUE to encode field values, FALSE to not
    *       encode field values
     *
     * @return \Guzzle\Http\QueryString Provides a fluent interface
     */
    public function setEncodeValues($encode)
    {
        $this->encodeValues = $encode;
        
        return $this;
    }

    /**
     * Set the query string separator
     *
     * @param string $separator The query string separator that will separate
     *      fields
     *
     * @return \Guzzle\Http\QueryString Provides a fluent interface
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
     * @return \Guzzle\Http\QueryString Provides a fluent interface
     */
    public function setPrefix($prefix)
    {
        $this->prefix = $prefix;
        
        return $this;
    }

    /**
     * Set the query string value separator
     *
     * @param string $separator The query string separator that will separate
     *      values from fields
     *
     * @return \Guzzle\Http\QueryString Provides a fluent interface
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
     * @param array $data The data to encode
     * @param bool $encodeFields (optional) Whether or not fields should be
     *      rawurlencoded
     * @param bool $encodeValues (optional) Whether or not values should be
     *      rawurlencoded
     *
     * @return array Returns an array of encoded values and keys
     */
    protected function encodeData(array $data, $encodeFields = true, $encodeValues = true)
    {
        $temp = array();
        foreach($data as $key => &$value) {

            if (is_array($value)) {

                $encoded = $this->encodeData($value, $encodeFields, $encodeValues);
                if ($this->aggregator !== null) {
                    $temp = array_merge($temp, call_user_func_array($this->aggregator, array($key, $value, $encodeFields, $encodeValues)));
                } else {
                    foreach ($encoded as $i => $v) {
                        $i = (!is_numeric($i)) ? 0 : $i;
                        if ($encodeFields) {
                            $k = self::rawurlencode($key) . "[{$i}]";
                        } else {
                            $k = "{$key}[{$i}]";
                        }
                        $temp[$k] = $v;
                    }
                }

            } else {

                if ($encodeValues && is_string($value) || is_numeric($value)) {
                    $value = self::rawurlencode($value);
                }
                if ($encodeFields) {
                    $temp[self::rawurlencode($key)] = $value;
                } else {
                    $temp[$key] = $value;
                }
            }
        }

        return $temp;
    }
}