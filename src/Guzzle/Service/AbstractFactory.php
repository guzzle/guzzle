<?php

namespace Guzzle\Service;

/**
 * Abstract factory used to delegate class instantiation and manages caching
 */
abstract class AbstractFactory
{
    /**
     * {@inheritdoc}
     */
    public function build($config, array $options = null)
    {
        $adapter = null;
        $cacheTtlKey = $this->getCacheTtlKey($config);

        // Check if a cache was provided
        if (isset($options['cache.adapter']) && is_string($config)) {
            $adapter = $options['cache.adapter'];
            $ttl = isset($options[$cacheTtlKey]) ? $options[$cacheTtlKey] : 3600;
            $cacheKey = 'guzzle' . crc32($config);
            // Check if the instantiated data is in the cache
            if ($cached = $adapter->fetch($cacheKey)) {
                return $cached;
            }
        }

        // Get the name of the class to instantiate for the type of data
        $factory = $this->getFactory($config);
        if (!$factory || is_string($factory)) {
            return $this->throwException($factory);
        }

        $result = $factory->build($config, $options);
        if ($adapter) {
            $adapter->save($cacheKey, $result, $ttl);
        }

        return $result;
    }

    /**
     * Get the string used to hold cache TTL specific information for the factory
     *
     * @param mixed $config Config data being loaded
     *
     * @return string
     */
    abstract protected function getCacheTtlKey($config);

    /**
     * Throw an exception when the abstract factory cannot instantiate anything
     *
     * @param string $message Message for the exception
     *
     * @return string
     * @throws \Exception
     */
    abstract protected function throwException($message = '');

    /**
     * Get a concrete factory based on the data provided
     *
     * @param mixed $config Data to use to determine the concrete factory
     *
     * @return mixed|string Returning a string will throw an exception with a specific message
     */
    abstract protected function getFactory($config);
}
