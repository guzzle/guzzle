<?php

namespace Guzzle\Common\Filter;

/**
 * Check if the supplied variable is in the supplied enumerable list
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class EnumFilter extends AbstractFilter
{
    /**
     * {@inheritdoc}
     */
    protected function filterCommand($command)
    {
        $filter = true;

        if ($this->get(0)) {
            $list = array_map('trim', explode(',', $this->get(0)));

            if (!in_array($command, $list)) {

                $error = 'The supplied argument was not found in the list of '
                    . 'acceptable values (' . implode(', ', $list)
                    . '): %s supplied';

                if (is_scalar($command)) {
                    $filter = sprintf($error, '<' . gettype($command) . ':'
                        . $command . '>');
                } else {
                    $filter = sprintf($error, gettype($command));
                }
            }
        }

        return $filter;
    }
}