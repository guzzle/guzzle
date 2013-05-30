<?php

namespace Guzzle\Cache;

/**
 * Zend Framework 1 cache adapter
 *
 * @link http://framework.zend.com/manual/en/zend.cache.html
 * @deprecated
 * @codeCoverageIgnore
 */
class Zf1CacheAdapter extends AbstractCacheAdapter
{
    /**
     * @param \Zend_Cache_Backend $cache Cache object to wrap
     */
    public function __construct(\Zend_Cache_Backend $cache)
    {
        $this->cache = $cache;
    }

    public function contains($id, array $options = null)
    {
        return $this->cache->test($id);
    }

    public function delete($id, array $options = null)
    {
        return $this->cache->remove($id);
    }

    public function fetch($id, array $options = null)
    {
        return $this->cache->load($id);
    }

    public function save($id, $data, $lifeTime = false, array $options = null)
    {
        return $this->cache->save($data, $id, array(), $lifeTime);
    }
}
