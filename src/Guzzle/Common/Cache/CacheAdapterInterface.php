<?php

namespace Guzzle\Common\Cache;

/**
 * Interface for cache adapters.
 *
 * Cache adapters allow Guzzle to utilze various frameworks for caching HTTP
 * responses.
 *
 * The CacheAdapter interface was inspired by the Doctrine 2 ORM:
 * @link http://www.doctrine-project.org/
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
interface CacheAdapterInterface
{
    /**
     * Test if an entry exists in the cache.
     *
     * @param string $id cache id The cache id of the entry to check for.
     *
     * @return bool TRUE if a cache entry exists for the given cache id,
     *      FALSE otherwise.
     */
    function contains($id);

    /**
     * Deletes a cache entry.
     *
     * @param string $id cache id
     *
     * @return bool TRUE on success, FALSE on failure
     */
    function delete($id);

    /**
     * Fetches an entry from the cache.
     *
     * @param string $id cache id The id of the cache entry to fetch.
     *
     * @return string The cached data or FALSE, if no cache entry exists
     *     for the given id.
     */
    function fetch($id);

    /**
     * Get the wrapped cache object
     *
     * @return mixed
     */
    function getCacheObject();

    /**
     * Puts data into the cache.
     *
     * @param string $id The cache id.
     * @param string $data The cache entry/data.
     * @param int $lifeTime The lifetime. If != false, sets a specific lifetime
     *      for this cache entry (null => infinite lifeTime).
     *
     * @return bool TRUE if the entry was successfully stored in the cache,
     *      FALSE otherwise.
     */
    function save($id, $data, $lifeTime = false);
}