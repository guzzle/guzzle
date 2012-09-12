<?php

namespace Guzzle\Validation;

/**
 * Ensures that a value is one of an array of choices
 */
class Choice extends AbstractConstraint
{
    protected static $defaultOption = 'options';

    /**
     * {@inheritdoc}
     */
    public function validateValue($value, array $options = array())
    {
        return in_array($value, $options[self::$defaultOption])
            ? true
            : "Value must be one of: " . implode(', ', $options[self::$defaultOption]);
    }
}
