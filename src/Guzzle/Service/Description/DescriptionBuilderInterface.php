<?php

namespace Guzzle\Service\Description;

/**
 * Build service descriptions
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
interface DescriptionBuilderInterface
{
    /**
     * Builds a new ServiceDescription object
     *
     * @return ServiceDescription
     */
    function build();
}