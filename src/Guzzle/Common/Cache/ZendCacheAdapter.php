<?php

namespace Guzzle\Common\Cache;

use Zend\Cache\Backend;

/**
 * ZF1 / ZF2 cache adapter
 */
class ZendCacheAdapter extends AbstractCacheAdapter
{
    /**
     * {@inheritdoc}
     */
    public function __construct($cache)
    {
        if (!($cache instanceof Backend) && !($cache instanceof \Zend_Cache_Backend)) {
            throw new \InvalidArgumentException('$cache must be an instance of '
                . 'Zend\\Log\\Backend or Zend_Cache_Backend');
        }
        $this->cache = $cache;
    }

    /**
     * {@inheritdoc}
     */
    public function contains($id)
    {
        return $this->cache->test($id);
    }

    /**
     * {@inheritdoc}
     */
    public function delete($id)
    {
        return $this->cache->remove($id);
    }

    /**
     * {@inheritdoc}
     */
    public function fetch($id)
    {
        return $this->cache->load($id);
    }

    /**
     * {@inheritdoc}
     */
    public function save($id, $data, $lifeTime = false)
    {
        return $this->cache->save($data, $id, array(), $lifeTime);
    }
}