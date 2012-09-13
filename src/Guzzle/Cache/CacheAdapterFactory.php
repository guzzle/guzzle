<?php

namespace Guzzle\Cache;

use Guzzle\Common\FromConfigInterface;
use Guzzle\Common\Exception\InvalidArgumentException;
use Guzzle\Common\Exception\RuntimeException;

/**
 * Generates cache adapters and cache providers objects using an array of configuration data.  This can be useful for
 * creating cache adapters in client builder configuration files.
 */
class CacheAdapterFactory implements FromConfigInterface
{
    /**
     * Create a Guzzle cache adapter based on an array of options
     *
     * @param array $config Array of configuration options
     *
     * @return CacheAdapterInterface
     * @throws InvalidArgumentException
     */
    public static function factory($config = array())
    {
        if (!is_array($config)) {
            throw new InvalidArgumentException('$config must be an array');
        }

        if (!isset($config['cache.adapter']) && !isset($config['cache.provider'])) {
            $config['cache.adapter'] = 'Guzzle\Cache\NullCacheAdapter';
            $config['cache.provider'] = null;
        } else {
            // Validate that the options are valid
            foreach (array('cache.adapter', 'cache.provider') as $required) {
                if (!isset($config[$required])) {
                    throw new InvalidArgumentException("{$required} is a required CacheAdapterFactory option");
                }
                if (is_string($config[$required])) {
                    // Convert dot notation to namespaces
                    $config[$required] = str_replace('.', '\\', $config[$required]);
                    if (!class_exists($config[$required])) {
                        throw new InvalidArgumentException("{$config[$required]} is not a valid class for {$required}");
                    }
                }
            }
            // Instantiate the cache provider
            if (is_string($config['cache.provider'])) {
                $args = isset($config['cache.provider.args']) ? $config['cache.provider.args'] : null;
                $config['cache.provider'] = self::createObject($config['cache.provider'], $args);
            }
        }

        // Instantiate the cache adapter using the provider and options
        if (is_string($config['cache.adapter'])) {
            $args = isset($config['cache.adapter.args']) ? $config['cache.adapter.args'] : array();
            array_unshift($args, $config['cache.provider']);
            $config['cache.adapter'] = self::createObject($config['cache.adapter'], $args);
        }

        return $config['cache.adapter'];
    }

    /**
     * Create a class using an array of constructor arguments
     *
     * @param string $className Class name
     * @param array  $args      Arguments for the class constructor
     *
     * @return mixed
     * @throws RuntimeException
     */
    protected static function createObject($className, array $args = null)
    {
        try {
            if (!$args) {
                return new $className;
            } else {
                $c = new \ReflectionClass($className);
                return $c->newInstanceArgs($args);
            }
        } catch (\Exception $e) {
            throw new RuntimeException($e->getMessage(), $e->getCode(), $e);
        }
    }
}
