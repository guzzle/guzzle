<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Common\Filter;

/**
 * Check if the supplied variable is a string
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class StringFilter extends AbstractFilter
{
    /**
     * {@inheritdoc}
     */
    protected function filterCommand($command)
    {
        if (!is_string($command)) {

            return 'The supplied value is not a string: ' . gettype($command)
                . ' supplied';
        }

        return true;
    }
}