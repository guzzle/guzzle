<?php

namespace Guzzle\Common\Cache;

/**
 * Abstract cache adapter
 *
 * @link http://www.doctrine-project.org/
 * @author Michael Dowling <michael@guzzlephp.org>
 */
abstract class AbstractCacheAdapter implements CacheAdapterInterface
{
    /**
     * @var mixed Cache object that is wrapped by the adapter
     */
    protected $cache;

    /**
     * Get the cache object
     *
     * @return mixed
     */
    public function getCacheObject()
    {
        return $this->cache;
    }
}