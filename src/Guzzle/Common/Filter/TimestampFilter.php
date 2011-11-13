<?php

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
        $timestamp = is_numeric($command) ? date('Y-m-d', (int) $command) : false;
        
        if (false === $timestamp || '1969-12-31' == $timestamp) {
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