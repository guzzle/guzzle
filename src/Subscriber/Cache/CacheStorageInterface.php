<?php

namespace GuzzleHttp\Subscriber\Cache;

use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Message\ResponseInterface;

/**
 * Interface used to cache HTTP requests
 */
interface CacheStorageInterface
{
    /**
     * Get a Response from the cache for a request
     *
     * @param RequestInterface $request
     *
     * @return null|ResponseInterface
     */
    public function fetch(RequestInterface $request);

    /**
     * Cache an HTTP request
     *
     * @param RequestInterface  $request  Request being cached
     * @param ResponseInterface $response Response to cache
     */
    public function cache(RequestInterface $request, ResponseInterface $response);

    /**
     * Deletes cache entries that match a request
     *
     * @param RequestInterface $request Request to delete from cache
     */
    public function delete(RequestInterface $request);

    /**
     * Purge all cache entries for a given URL
     *
     * @param string $url
     */
    public function purge($url);
}
