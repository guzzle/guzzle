<?php

namespace Guzzle\Service\Builder;

use Guzzle\Service\JsonLoader;

/**
 * Creates a ServiceBuilder using a JSON configuration file
 */
class JsonServiceBuilderFactory extends JsonLoader implements ServiceBuilderFactoryInterface
{
    /**
     * @var ArrayServiceBuilderFactory Factory used when building off of the parsed data
     */
    protected $factory;

    /**
     * @param ServiceBuilderAbstractFactory $factory Factory used when building off of the parsed data
     */
    public function __construct(ArrayServiceBuilderFactory $factory)
    {
        $this->factory = $factory;
    }

    /**
     * {@inheritdoc}
     */
    public function build($config, array $options = null)
    {
        return $this->factory->build($this->parseJsonFile($config), $options);
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
