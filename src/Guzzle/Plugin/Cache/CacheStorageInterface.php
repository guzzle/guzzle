<?php

namespace Guzzle\Plugin\Cache;

use Guzzle\Http\Message\Response;

/**
 * Interface used to cache HTTP requests
 */
interface CacheStorageInterface
{
    /**
     * Cache an HTTP request
     *
     * @param string   $key      Cache key
     * @param Response $response Response to cache
     * @param int      $ttl      Amount of time to cache the response
     */
    public function cache($key, Response $response, $ttl = null);

    /**
     * Delete an item from the cache
     *
     * @param string $key Cache key
     */
    public function delete($key);

    /**
     * Get a Response from the cache
     *
     * @param string $key Cache key
     *
     * @return null|Response
     */
    public function fetch($key);
}
