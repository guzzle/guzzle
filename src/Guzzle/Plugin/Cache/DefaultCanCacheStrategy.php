<?php

namespace Guzzle\Plugin\Cache;

use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\Response;

/**
 * Default strategy used to determine of an HTTP request can be cached
 */
class DefaultCanCacheStrategy implements CanCacheStrategyInterface
{
    /**
     * {@inheritdoc}
     */
    public function canCacheRequest(RequestInterface $request)
    {
        return $request->canCache();
    }

    /**
     * {@inheritdoc}
     */
    public function canCacheResponse(Response $response)
    {
        return $response->isSuccessful() && $response->canCache();
    }
}
