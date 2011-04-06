<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Common\Filter;

/**
 * Check if the supplied variable is an Integer
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class IntegerFilter extends AbstractFilter
{
    /**
     * {@inheritdoc}
     */
    protected function filterCommand($command)
    {
        if (!is_numeric($command) || strpos($command, '.') !== false) {

            return 'The supplied value is not a valid integer: '
                . (string) $command . ' supplied';
        }

        return true;
    }
}