<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Common\CacheAdapter;

/**
 * Doctrine 2 cache adapter
 *
 * @link    http://www.doctrine-project.org/ 
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class DoctrineCacheAdapter extends AbstractCacheAdapter
{
    protected $className = 'Doctrine\Common\Cache\Cache';

    /**
     * Test if an entry exists in the cache.
     *
     * @param string $id cache id The cache id of the entry to check for.
     *
     * @return bool TRUE if a cache entry exists for the given cache id,
     *      FALSE otherwise.
     */
    public function contains($id)
    {
        return $this->cache->contains($id);
    }

    /**
     * Deletes a cache entry.
     *
     * @param string $id cache id
     *
     * @return bool TRUE on success, FALSE on failure
     */
    public function delete($id)
    {
        return $this->cache->delete($id);
    }

    /**
     * Fetches an entry from the cache.
     *
     * @param string $id cache id The id of the cache entry to fetch.
     *
     * @return string The cached data or FALSE, if no cache entry exists for
     *      the given id.
     */
    public function fetch($id)
    {
        return $this->cache->fetch($id);
    }

    /**
     * Puts data into the cache.
     *
     * @param string $id The cache id.
     * @param string $data The cache entry/data.
     * @param int $lifeTime The lifetime. If != false, sets a specific lifetime
     *      for this cache entry (null => infinite lifeTime).
     *
     * @return bool TRUE on success, FALSE on failure
     */
    public function save($id, $data, $lifeTime = false)
    {
        return $this->cache->save($id, $data, $lifeTime);
    }
}