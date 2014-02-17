<?php

namespace GuzzleHttp\Subscriber\Cache;

/**
 * Default cache storage implementation
 */
class DefaultCacheStorage extends AbstractCacheStorage
{
    protected $cache;

    /**
     * @param mixed  $cache      Cache used to store cache data
     * @param string $keyPrefix  Provide an optional key prefix to prefix on all cache keys
     * @param int    $defaultTtl Default cache TTL
     */
    public function __construct($cache, $keyPrefix = '', $defaultTtl = 3600)
    {
        $this->cache = $cache;
        $this->defaultTtl = $defaultTtl;
        $this->keyPrefix = $keyPrefix;
    }

    protected function getCache($key)
    {
        return $this->cache->fetch($key);
    }

    protected function saveCache($key, $value, $ttl = null)
    {
        return $this->cache->save($key, $value, $ttl);
    }

    protected function deleteCache($key)
    {
        return $this->cache->delete($key);
    }
}
