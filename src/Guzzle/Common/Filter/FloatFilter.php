<?php

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