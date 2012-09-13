<?php

namespace Guzzle\Plugin\Cache;

use Guzzle\Common\Exception\InvalidArgumentException;

/**
 * Cache strategy that requires a callable method in the constructor
 */
abstract class AbstractCallbackStrategy
{
    /**
     * @var \Closure|array|mixed Callable method
     */
    protected $callback;

    /**
     * @param \Closure|array|mixed $callback Callable method to invoke
     *
     * @throws InvalidArgumentException
     */
    public function __construct($callback)
    {
        if (!is_callable($callback)) {
            throw new InvalidArgumentException('Method must be callable');
        }
        $this->callback = $callback;
    }
}
