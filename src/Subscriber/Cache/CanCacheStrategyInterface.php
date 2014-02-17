<?php

namespace GuzzleHttp\Subscriber\Cache;

use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Message\ResponseInterface;

/**
 * Strategy used to determine if a request can be cached
 */
interface CanCacheStrategyInterface
{
    /**
     * Determine if a request can be cached
     *
     * @param RequestInterface $request Request to determine
     *
     * @return bool
     */
    public function canCacheRequest(RequestInterface $request);

    /**
     * Determine if a response can be cached
     *
     * @param ResponseInterface $response Response to determine
     *
     * @return bool
     */
    public function canCacheResponse(ResponseInterface $response);
}
