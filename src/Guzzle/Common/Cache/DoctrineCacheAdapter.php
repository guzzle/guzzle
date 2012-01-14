<?php

namespace Guzzle\Common\Cache;

use Doctrine\Common\Cache\Cache;

/**
 * Doctrine 2 cache adapter
 *
 * @link   http://www.doctrine-project.org/
 */
class DoctrineCacheAdapter extends AbstractCacheAdapter
{
    /**
     * {@inheritdoc}
     */
    public function __construct(Cache $cache)
    {
        $this->cache = $cache;
    }

    /**
     * {@inheritdoc}
     */
    public function contains($id)
    {
        return $this->cache->contains($id);
    }

    /**
     * {@inheritdoc}
     */
    public function delete($id)
    {
        return $this->cache->delete($id);
    }

    /**
     * {@inheritdoc}
     */
    public function fetch($id)
    {
        return $this->cache->fetch($id);
    }

    /**
     * {@inheritdoc}
     */
    public function save($id, $data, $lifeTime = false)
    {
        return $this->cache->save($id, $data, $lifeTime);
    }
}