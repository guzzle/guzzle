<?php

namespace Guzzle\Plugin\Cache;

use Guzzle\Common\Exception\InvalidArgumentException;
use Guzzle\Http\Message\RequestInterface;

/**
 * Determines a request's cache key using a callback
 */
class CallbackCacheKeyProvider implements CacheKeyProviderInterface
{
    /**
     * @var \Closure|array|mixed Callable method
     */
    protected $callback;

    /**
     * @param \Closure|array|mixed $callback Callable method to invoke
     * @throws InvalidArgumentException
     */
    public function __construct($callback)
    {
        if (!is_callable($callback)) {
            throw new InvalidArgumentException('Method must be callable');
        }
        $this->callback = $callback;
    }

    /**
     * {@inheritdoc}
     */
    public function getCacheKey(RequestInterface $request)
    {
        return call_user_func($this->callback, $request);
    }
}
