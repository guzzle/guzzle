<?php

namespace GuzzleHttp\Subscriber\Cache;

use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Message\ResponseInterface;

/**
 * Default strategy used to determine of an HTTP request can be cached
 */
class DefaultCanCacheStrategy implements CanCacheStrategyInterface
{
    public function canCacheRequest(RequestInterface $request)
    {
        // Only GET and HEAD requests can be cached
        if ($request->getMethod() != RequestInterface::GET && $request->getMethod() != RequestInterface::HEAD) {
            return false;
        }

        // Never cache requests when using no-store
        if ($request->hasHeader('Cache-Control') && $request->getHeader('Cache-Control')->hasDirective('no-store')) {
            return false;
        }

        return true;
    }

    public function canCacheResponse(ResponseInterface $response)
    {
        return $response->isSuccessful() && $response->canCache();
    }
}
