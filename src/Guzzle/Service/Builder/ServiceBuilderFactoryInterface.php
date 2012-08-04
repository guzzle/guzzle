<?php

namespace Guzzle\Service\Builder;

/**
 * Factory used to create {@see ServiceBuilderInterface} objects
 */
interface ServiceBuilderFactoryInterface
{
    /**
     * Builds a new service builder object
     *
     * @param mixed $config  File, array, or data string to build from
     * @param array $options (options) Options used when building
     *
     * @return ServiceBuilderInterface
     */
    public function build($config, array $options = null);
}
