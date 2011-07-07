<?php

namespace Guzzle\Common\Filter;

/**
 * Check if the supplied variable is a Date
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class DateFilter extends AbstractFilter
{
    /**
     * {@inheritdoc}
     */
    protected function filterCommand($command)
    {
        if (!is_scalar($command)) {

            return 'The supplied value is not a valid date: '
                . gettype($command) . ' supplied';

        } else {

            $s = strtotime($command);

            if (false === $s
                || !checkdate(date('m', $s), date('d', $s), date('Y', $s))) {

                return 'The supplied value is not a valid date: '
                    . (string) $command . ' supplied';
            }
        }

        return true;
    }
}