<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Common\Filter;

/**
 * Check if the supplied variable is a timestamp
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class TimestampFilter extends AbstractFilter
{
    /**
     * {@inheritdoc}
     */
    protected function filterCommand($command)
    {
        if (!is_numeric($command) || false === date('Y-m-d', (float) $command)) {
            if (is_scalar($command)) {

                return 'The supplied value is not a valid timestamp: ' 
                    . (string) $command . ' supplied';
            } else {

                return 'The supplied value is not a valid timestamp: '
                    . gettype($command) . ' supplied';
            }
        }

        return true;
    }
}