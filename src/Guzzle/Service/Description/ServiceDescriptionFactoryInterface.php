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
     * @param string|array $data File to build or array of command information
     * @param array $options (optional) Factory configuration options.
     *     cache.adapter - CacheAdapterInterface used for caching descriptions
     *     cache.description.ttl - TTL for caching built service descriptions
     *
     * @throws DescriptionBuilderException when the type is not recognized
     */
    function build($data, array $options = null);
}
