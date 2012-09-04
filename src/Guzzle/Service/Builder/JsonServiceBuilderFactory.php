<?php

namespace Guzzle\Service\Builder;

use Guzzle\Service\JsonLoader;

/**
 * Creates a ServiceBuilder using a JSON configuration file
 */
class JsonServiceBuilderFactory extends JsonLoader implements ServiceBuilderFactoryInterface
{
    /**
     * {@inheritdoc}
     */
    public function build($config, array $options = null)
    {
        return ServiceBuilder::factory($this->parseJsonFile($config), $options);
    }

    /**
     * {@inheritdoc}
     * Adds special handling for JSON configuration merging
     */
    protected function mergeJson(array $jsonA, array $jsonB)
    {
        return ServiceBuilderAbstractFactory::combineConfigs($jsonA, $jsonB);
    }
}
