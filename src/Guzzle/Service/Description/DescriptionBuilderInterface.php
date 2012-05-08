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
     * @param string $data File or data string to build from
     * @param array  $options (options) Options used when building
     *
     * @return ServiceDescription
     */
    function build($data, array $options = null);
}
