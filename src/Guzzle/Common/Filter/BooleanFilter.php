<?php

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