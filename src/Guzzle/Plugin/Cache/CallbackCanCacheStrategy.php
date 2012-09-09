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
        $callback = $this->callback;

        return $callback($request);
    }
}
