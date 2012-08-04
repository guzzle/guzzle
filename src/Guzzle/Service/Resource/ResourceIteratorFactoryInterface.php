<?php

namespace Guzzle\Service\Resource;

/**
 * Factory for creating {@see ResourceIteratorInterface} objects
 */
interface ResourceIteratorFactoryInterface
{
    /**
     * Create a resource iterator
     *
     * @param mixed $data    Data used by the concrete factory to create iterators
     * @param array $options Iterator options
     *
     * @return ResourceIteratorInterface
     */
    public function build($data, array $options = null);
}
