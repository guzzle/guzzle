<?php

namespace Guzzle\Common\Validation;

/**
 * Ensures that a value matches a regexp
 */
class Regex extends AbstractConstraint
{
    protected $default = 'pattern';
    protected $required = 'pattern';

    /**
     * {@inheritdoc}
     */
    public function validateValue($value, array $options = null)
    {
        if (!preg_match($options['pattern'], $value)) {
            return "{$value} does not match the regular expression";
        }

        return true;
    }
}
