<?php

namespace Guzzle\Service;

/**
 * Abstract factory used to delegate class instantiation and manages caching
 */
abstract class AbstractFactory
{
    /**
     * @var array Cache of instantiated factories
     */
    protected $factories = array();

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
            $cached = $adapter->fetch($cacheKey);
            if ($cached) {
                return $cached;
            }
        }

        // Get the name of the class to instantiate for the type of data
        $class = $this->getClassName($config);
        if ($class) {
            $result = $this->getFactory($class)->build($config, $options);
            if ($adapter) {
                $adapter->save($cacheKey, $result, $ttl);
            }
            return $result;
        }

        $this->throwException();
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
     * Get the name of a class to instantiate for the type of data provided
     *
     * @param mixed $config Data to use to determine a class name
     *
     * @return mixed
     */
    abstract protected function getClassName($config);

    /**
     * Get a factory by object name, or retrieve previously a created factory
     *
     * @param string $class Name of the factory class to retrieve
     *
     * @return mixed
     */
    protected function getFactory($class)
    {
        if (!isset($this->factories[$class])) {
            $this->factories[$class] = new $class();
        }

        return $this->factories[$class];
    }
}
