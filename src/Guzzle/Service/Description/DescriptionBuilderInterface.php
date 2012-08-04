<?php

namespace Guzzle\Service\Description;

/**
 * Build service descriptions
 */
interface DescriptionBuilderInterface
{
    /**
     * Builds a new ServiceDescription object
     *
     * @param string $config  File or data string to build from
     * @param array  $options Options used when building
     *
     * @return ServiceDescriptionInterface
     */
    public function build($config, array $options = null);
}
