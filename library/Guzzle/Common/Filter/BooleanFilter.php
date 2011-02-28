<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Common\Filter;

/**
 * Check if the supplied variable is Boolean
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class BooleanFilter extends AbstractFilter
{
    /**
     * {@inheritdoc}
     */
    protected function filterCommand($command)
    {
        if (!($command === 1 || $command === 0 || $command === true 
            || $command === false || $command === 'true'
            || $command === 'false')) {
            
            if (is_scalar($command)) {

                return 'The supplied value is not a Boolean: ' 
                    . (string) $command . ' supplied';
            }

            return 'The supplied value is not a Boolean: ' . gettype($command)
                . ' supplied';
        }

        return true;
    }
}