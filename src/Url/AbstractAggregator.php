<?php

namespace GuzzleHttp\Url;

abstract class AbstractAggregator implements QueryAggregatorInterface
{
    public function aggregate(array $query)
    {
        return $this->walkQuery($query, '');
    }

    protected function walkQuery(array $query, $keyPrefix)
    {
        $result = [];
        foreach ($query as $key => $value) {
            if ($keyPrefix) {
                $key = $this->createPrefixKey($key, $keyPrefix);
            }
            if (is_array($value)) {
                $result += $this->walkQuery($value, $key);
            } elseif (isset($result[$key])) {
                $result[$key][] = $value;
            } else {
                $result[$key] = array($value);
            }
        }

        return $result;
    }

    /**
     * Computes a key for a key and prefix
     *
     * @param string $key
     * @param string $keyPrefix
     *
     * @return string
     */
    abstract protected function createPrefixKey($key, $keyPrefix);
}
