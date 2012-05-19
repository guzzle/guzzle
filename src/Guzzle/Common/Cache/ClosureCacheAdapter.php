<?php

namespace Guzzle\Common\Cache;

use Guzzle\Common\Exception\InvalidArgumentException;

/**
 * Cache adapter that defers to closures for implementation
 */
class ClosureCacheAdapter implements CacheAdapterInterface
{
    /**
     * @array Associative array of method names mapping to callable functions
     */
    protected $callables;

    /**
     * @param array $callables Callables to use with each cache adapter method.
     *                         The required array keys are 'contains', 'delete',
     *                         'fetch', and 'save' where each key maps to a
     *                         closure or callable function.
     *
     *     - contains: Callable that accepts an $id and $options argument
     *     - delete:   Callable that accepts an $id and $options argument
     *     - fetch:    Callable that accepts an $id and $options argument
     *     - save:     Callable that accepts an $id, $data, $lifeTime, and
     *                 $options argument
     */
    public function __construct(array $callables)
    {
        // Validate each key to ensure it exists and is callable
        foreach (array('contains', 'delete', 'fetch', 'save') as $key) {
            if (!array_key_exists($key, $callables) || !is_callable($callables[$key])) {
                throw new InvalidArgumentException(
                    "callables must contain a callable $key key");
            }
        }

        $this->callables = $callables;
    }

    /**
     * {@inheritdoc}
     */
    public function contains($id, array $options = null)
    {
        return call_user_func($this->callables['contains'], $id, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function delete($id, array $options = null)
    {
        return call_user_func($this->callables['delete'], $id, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function fetch($id, array $options = null)
    {
        return call_user_func($this->callables['fetch'], $id, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function save($id, $data, $lifeTime = false, array $options = null)
    {
        return call_user_func($this->callables['save'], $id, $data, $lifeTime, $options);
    }
}
