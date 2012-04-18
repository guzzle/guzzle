<?php

namespace Guzzle\Common\Cache;

/**
 * Generates cache adapters and cache providers objects using an array of
 * configuration data.  This can be useful for creating cache adapters
 * in client configuration files.
 */
class CacheAdapterFactory
{
    /**
     * Create a Guzzle cache adapter based on an array of options
     *
     * @param array $config Array of configuration options
     *
     * @return CacheAdapterInterface
     */
    public static function factory(array $config)
    {
        foreach (array('adapter', 'provider') as $required) {
            // Validate that the required parameters were set
            if (!isset($config[$required])) {
                throw new \InvalidArgumentException("{$required} is a required CacheAdapterFactory option");
            }

            // Ensure that the cache adapter and provider are actual classes
            if (is_string($config[$required]) && !class_exists($config[$required])) {
                throw new \InvalidArgumentException("{$config[$required]} is not a valid class for {$required}");
            }
        }

        // Instantiate the cache provider
        if (is_string($config['provider'])) {
            $args = isset($config['provider.args']) ? $config['provider.args'] : null;
            $config['provider'] = self::createObject($config['provider'], $args);
        }

        // Instantiate the cache adapter using the provider and options
        if (is_string($config['adapter'])) {
            $args = isset($config['adapter.args']) ? $config['adapter.args'] : array();
            array_unshift($args, $config['provider']);
            $config['adapter'] = self::createObject($config['adapter'], $args);
        }

        return $config['adapter'];
    }

    /**
     * Create a class using an array of constructor arguments
     *
     * @param string $className Class name
     * @param array  $args      Arguments for the class constructor
     *
     * @return mixed
     */
    protected static function createObject($className, array $args = null)
    {
        if (!$args) {
            return new $className;
        } else {
            $c = new \ReflectionClass($className);
            return $c->newInstanceArgs($args);
        }
    }
}