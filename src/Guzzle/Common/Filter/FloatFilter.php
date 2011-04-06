<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Common\Filter;

/**
 * Check if the supplied variable is a Float
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class FloatFilter extends AbstractFilter
{
    /**
     * {@inheritdoc}
     */
    protected function filterCommand($command)
    {
        if (!is_numeric($command)) {

            return 'The supplied value is not a valid float: '
                . gettype($command) . ' supplied';
        }

        return true;
    }
}