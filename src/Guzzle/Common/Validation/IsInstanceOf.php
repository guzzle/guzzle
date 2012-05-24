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
        // Replace dot notation with namespace separator
        $class = str_replace('.', '\\', $options['class']);

        if (!($value instanceof $class)) {
            return "Value must be an instance of {$class}";
        }

        return true;
    }
}
