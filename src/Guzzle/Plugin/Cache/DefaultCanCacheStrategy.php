<?php

namespace Guzzle\Plugin\Cache;

use Guzzle\Http\Message\RequestInterface;

/**
 * Default strategy used to determine of an HTTP request can be cached
 */
class DefaultCanCacheStrategy implements CanCacheStrategyInterface
{
    /**
     * {@inheritdoc}
     */
    public function canCache(RequestInterface $request)
    {
        return $request->canCache();
    }
}
