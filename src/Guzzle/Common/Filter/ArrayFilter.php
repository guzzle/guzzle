<?php

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