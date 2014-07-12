<?php
namespace GuzzleHttp;

/**
 * Parses query strings into a Query object.
 *
 * While parsing, the parser will attempt to determine the most appropriate
 * query string aggregator to use when serializing the parsed query string
 * object back into a string. The hope is that parsing then serializing a
 * query string should be a lossless operation.
 *
 * @internal Use Query::fromString()
 */
class QueryParser
{
    private $duplicates;
    private $numericIndices;

    /**
     * Parse a query string into a Query object.
     *
     * @param Query       $query       Query object to populate
     * @param string      $str         Query string to parse
     * @param bool|string $urlEncoding How the query string is encoded
     */
    public function parseInto(Query $query, $str, $urlEncoding = true)
    {
        if ($str === '') {
            return;
        }

        $result = [];
        $this->duplicates = false;
        $this->numericIndices = true;
        $decoder = self::getDecoder($urlEncoding);

        foreach (explode('&', $str) as $kvp) {

            $parts = explode('=', $kvp, 2);
            $key = $decoder($parts[0]);
            $value = isset($parts[1]) ? $decoder($parts[1]) : null;

            // Special handling needs to be taken for PHP nested array syntax
            if (strpos($key, '[') !== false) {
                $this->parsePhpValue($key, $value, $result);
                continue;
            }

            if (!isset($result[$key])) {
                $result[$key] = $value;
            } else {
                $this->duplicates = true;
                if (!is_array($result[$key])) {
                    $result[$key] = [$result[$key], $value];
                }
            }
        }

        $query->replace($result);

        if (!$this->numericIndices) {
            $query->setAggregator(Query::phpAggregator(false));
        } elseif ($this->duplicates) {
            $query->setAggregator(Query::duplicateAggregator());
        }
    }

    private static function getDecoder($type)
    {
        if ($type === true) {
            return function ($value) {
                return rawurldecode(str_replace('+', ' ', $value));
            };
        } elseif ($type == Query::RFC3986) {
            return 'rawurldecode';
        } elseif ($type == Query::RFC1738) {
            return 'urldecode';
        } else {
            return function ($str) { return $str; };
        }
    }

    private function parsePhpValue($key, $value, &$result)
    {
        $node =& $result;
        $keyBuffer = '';

        for ($i = 0, $t = strlen($key); $i < $t; $i++) {
            switch ($key[$i]) {
                case '[':
                    if ($keyBuffer) {
                        $this->descend($node, $keyBuffer);
                        $node =& $node[$keyBuffer];
                        $keyBuffer = '';
                    }
                    break;
                case ']':
                    $k = $this->cleanKey($node, $keyBuffer);
                    $this->descend($node, $k);
                    $node =& $node[$k];
                    $keyBuffer = '';
                    break;
                default:
                    $keyBuffer .= $key[$i];
                    break;
            }
        }

        if (isset($node)) {
            $this->duplicates = true;
            $node[] = $value;
        } else {
            $node = $value;
        }
    }

    private function descend(&$node, $key)
    {
        if (!isset($node[$key])) {
            $node[$key] = null;
        } elseif (!is_array($node[$key])) {
            $node[$key] = [$node[$key]];
        }
    }

    private function cleanKey($node, $key)
    {
        if ($key === '') {
            $key = $node ? count($node) : 0;
            // Found a [] key, so track this to ensure that we disable numeric
            // indexing of keys in the resolved query aggregator.
            $this->numericIndices = false;
        }

        return $key;
    }
}
