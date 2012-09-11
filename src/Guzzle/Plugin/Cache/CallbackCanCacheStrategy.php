<?php

namespace Guzzle\Plugin\Cache;

use Guzzle\Http\Message\RequestInterface;

/**
 * Determines if a request can be cached using a callback
 */
class CallbackCanCacheStrategy extends AbstractCallbackStrategy implements CanCacheStrategyInterface
{
    /**
     * {@inheritdoc}
     */
    public function canCache(RequestInterface $request)
    {
        return call_user_func($this->callback, $request);
    }
}
