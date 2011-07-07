<?php

namespace Guzzle\Common\Filter;

/**
 * Check if the supplied variable is an instance of the supplied class
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class ClassFilter extends AbstractFilter
{
    /**
     * {@inheritdoc}
     */
    protected function filterCommand($command)
    {
        if ($this->get(0) && is_string($this->get(0))) {
            $class = $this->get(0);
            $valid = ($command instanceof $class);
        } else {
            $valid = is_object($command);
            $class = 'stdClass';
        }

        if (!$valid) {
            $error = 'The supplied value is not an instance of ' . $class 
                . ': %s supplied';
            
            if (!is_object($command)) {
                $error = sprintf($error, '<' . gettype($command) . ':' . (string) $command . '>');
            } else {
                $error = sprintf($error, get_class($command));
            }

            return $error;
        }

        return true;
    }
}