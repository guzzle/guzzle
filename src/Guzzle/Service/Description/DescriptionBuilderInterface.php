<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

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
    public function build();
}