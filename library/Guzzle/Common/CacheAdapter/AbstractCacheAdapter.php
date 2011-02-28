<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Common\CacheAdapter;

/**
 * Abstract cache adapter
 *
 * @link http://www.doctrine-project.org/
 * @author Michael Dowling <michael@guzzle-project.org>
 */
abstract class AbstractCacheAdapter implements CacheAdapterInterface
{
    /**
     * @var mixed Cache object that is wrapped by the adapter
     */
    protected $cache;

    /**
     * @var string Name of the class that the cache adapter must implement
     */
    protected $className;

    /**
     * Create a new cache adapter
     *
     * @param object $cacheObject (optional) Concrete cache implementation that
     *      will be wrapped by the adapter.
     *
     * @throws CacheAdapterException if the supplied
     *      object does not implement the correct interface.
     */
    public function __construct($cacheObject)
    {
        if (!($cacheObject instanceof $this->className)) {
            throw new CacheAdapterException(
                'The concrete cache object must implement ' . $this->className
            );
        }
        $this->cache = $cacheObject;
    }

    /**
     * Proxy calls to the concrete cache object
     *
     * @param string $method Name of the method to proxy
     * @param array $args (optional) Arguments to pass to the method
     *
     * @return mixed Returns the result of the proxied method call
     *
     * @throws \BadMethodCallException if the method is not found on the
     *      concrete cache object.
     */
    public function __call($method, array $args = null)
    {
        if (method_exists($this->cache, $method)) {
            return call_user_func_array(array($this->cache, $method), $args);
        } else {
            throw new \BadMethodCallException(
                'Call to undefined method ' . $method
            );
        }
    }

    /**
     * Get the cache object
     *
     * @return mixed
     */
    public function getCacheObject()
    {
        return $this->cache;
    }
}