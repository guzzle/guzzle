<?php

namespace Guzzle\Common\Cache;

use Doctrine\Common\Cache\Cache;

/**
 * Null cache adapter
 */
class NullCacheAdapter extends AbstractCacheAdapter
{
    /**
     * Empty constructor allows you to pass args to the NullCacheAdapter
     */
    public function __construct()
    {
    }

    /**
     * {@inheritdoc}
     */
    public function contains($id, array $options = null)
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function delete($id, array $options = null)
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function fetch($id, array $options = null)
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function save($id, $data, $lifeTime = false, array $options = null)
    {
        return true;
    }
}
