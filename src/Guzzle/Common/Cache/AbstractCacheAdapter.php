<?php

namespace Guzzle\Common\Cache;

/**
 * Abstract cache adapter
 */
abstract class AbstractCacheAdapter implements CacheAdapterInterface
{
    protected $cache;

    /**
     * {@inheritdoc}
     */
    public function getCacheObject()
    {
        return $this->cache;
    }
}
