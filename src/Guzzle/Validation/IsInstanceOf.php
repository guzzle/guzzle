<?php

namespace Guzzle\Validation;

/**
 * Ensures that a value is an instance of a class
 */
class IsInstanceOf extends AbstractConstraint
{
    protected static $defaultOption = 'class';
    protected $required = 'class';

    /**
     * {@inheritdoc}
     */
    protected function validateValue($value, array $options = array())
    {
        // Replace dot notation with namespace separator
        $class = str_replace('.', '\\', $options[self::$defaultOption]);

        return $value instanceof $class ? true : "Value must be an instance of {$class}";
    }
}
