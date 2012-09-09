<?php

namespace Guzzle\Plugin\Cache;

use Guzzle\Http\Message\RequestInterface;

/**
 * Determines a request's cache key using a callback
 */
class CallbackCacheProviderStrategy extends AbstractCallbackStrategy implements CacheKeyProviderInterface
{
    /**
     * {@inheritdoc}
     */
    public function getCacheKey(RequestInterface $request)
    {
        $callback = $this->callback;

        return $callback($request);
    }
}
