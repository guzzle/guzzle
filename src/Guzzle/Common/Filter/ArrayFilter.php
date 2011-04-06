<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Common\Filter;

/**
 * Check if the supplied variable is an array
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class ArrayFilter extends AbstractFilter
{
    /**
     * {@inheritdoc}
     */
    protected function filterCommand($command)
    {
        if (!is_array($command)) {
            return 'The supplied value is not an array: ' . gettype($command)
                . ' supplied';
        }

        return true;
    }
}