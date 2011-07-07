<?php

namespace Guzzle\Common\Filter;

/**
 * Check if the supplied variable matches a regular expression
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class RegexFilter extends AbstractFilter
{
    /**
     * {@inheritdoc}
     */
    protected function filterCommand($command)
    {
        if (!is_string($command)) {
            
            return 'The supplied argument must be a string to match the '
                . 'RegexFilter: ' . gettype($command) . ' supplied';
        }

        $regex = $this->get(0);
        if (!$regex || !is_string($regex)) {
            return true;
        }

        if (!preg_match($regex, $command)) {

            return 'The supplied argument did not match the regular expression '
                . $regex . ': ' . $command . ' supplied';
        }

        return true;
    }
}