<?php

namespace Guzzle\Service\Description;

use Guzzle\Service\Exception\DescriptionBuilderException;

/**
 * A ServiceDescription factory interface
 */
interface ServiceDescriptionFactoryInterface
{
    /**
     * Create a {@see ServiceDescriptionInterface} object
     *
     * @param string|array $config  File to build or array of command information
     * @param array        $options Factory configuration options.
     *     - cache.adapter:         CacheAdapterInterface used for caching descriptions
     *     - cache.description.ttl: TTL for caching built service descriptions
     *
     * @throws DescriptionBuilderException when the type is not recognized
     */
    public function build($config, array $options = null);
}
