<?php

namespace Guzzle\Service\Description;

use Guzzle\Service\Exception\DescriptionBuilderException;

/**
 * A ServiceDescription factory
 */
class ServiceDescriptionFactory implements ServiceDescriptionFactoryInterface
{
    /**
     * @var array Cache of instantiated description builders
     */
    protected $builders = array();

    /**
     * {@inheritdoc}
     */
    public function build($filename, array $options = null)
    {
        $adapter = null;

        // Check if a cache was provided
        if (isset($options['cache.adapter'])) {
            $adapter = $options['cache.adapter'];
            $ttl = isset($options['cache.description.ttl']) ? $options['cache.description.ttl'] : 3600;
            $cacheKey = 'd' . crc32($filename);

            // Check if the description is in the cache
            $description = $adapter->fetch($cacheKey);
            if ($description) {
                return $description;
            }
        }

        $builder = null;
        if (is_array($filename)) {
            $builder = 'ArrayDescriptionBuilder';
        } else {
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            if ($ext == 'js' || $ext == 'json') {
                $builder = 'JsonDescriptionBuilder';
            } else if ($ext == 'xml') {
                $builder = 'XmlDescriptionBuilder';
            }
        }

        if ($builder) {
            $description = $this->getDescriptionBuilder($builder)->build($filename);
            if ($adapter) {
                $adapter->save($cacheKey, $description, $ttl);
            }
            return $description;
        }

        throw new DescriptionBuilderException('Unable to load service description due to unknown file extension: ' . $ext);
    }

    /**
     * Get a description builder by name
     *
     * @param string $class Description builder name
     *
     * @return DescriptionBuilderInterface
     */
    protected function getDescriptionBuilder($builder)
    {
        if (!isset($this->builders[$builder])) {
            $class = __NAMESPACE__ . '\\' . $builder;
            $this->builders[$builder] = new $class();
        }

        return $this->builders[$builder];
    }
}
