<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\DescriptionBuilder;

/**
 * Interface for ServiceDescription builders
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
    public function build();
}