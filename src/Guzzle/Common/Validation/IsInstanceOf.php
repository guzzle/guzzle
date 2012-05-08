<?php

namespace Guzzle\Common\Validation;

/**
 * Ensures that a value is an instance of a class
 */
class IsInstanceOf extends AbstractConstraint
{
    protected $default = 'class';
    protected $required = 'class';

    /**
     * {@inheritdoc}
     */
    protected function validateValue($value, array $options = array())
    {
        if (!($value instanceof $options['class'])) {
            return "Value must be an instance of {$options['class']}";
        }

        return true;
    }
}
