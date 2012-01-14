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
     * @param string $filename File to build
     *
     * @return ServiceDescription
     */
    static function build($filename);
}