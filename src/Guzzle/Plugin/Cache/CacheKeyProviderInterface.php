<?php

namespace Guzzle\Plugin\Cache;

use Guzzle\Http\Message\RequestInterface;

/**
 * Used to determine a cache key for a request object
 */
interface CacheKeyProviderInterface
{
    /**
     * Returns a cache key for a request object
     *
     * @param RequestInterface $request Request to generate a cache key for
     *
     * @return string
     */
    public function getCacheKey(RequestInterface $request);
}
