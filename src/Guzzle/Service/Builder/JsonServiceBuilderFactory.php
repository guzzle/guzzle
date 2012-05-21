<?php

namespace Guzzle\Service\Builder;

use Guzzle\Service\JsonLoader;

/**
 * Creates a ServiceBuilder using a JSON configuration file
 */
class JsonServiceBuilderFactory implements ServiceBuilderFactoryInterface
{
    /**
     * @var JsonLoader
     */
    protected $loader;

    /**
     * {@inheritdoc}
     */
    public function build($config, array $options = null)
    {
        if (!$this->loader) {
            $this->loader = new JsonLoader();
        }

        return ServiceBuilder::factory($this->loader->parseJsonFile($config), $options);
    }
}
