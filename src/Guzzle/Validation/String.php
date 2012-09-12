<?php

namespace Guzzle\Validation;

/**
 * Ensures that a value is a string
 */
class String implements ConstraintInterface
{
    /**
     * {@inheritdoc}
     */
    public function validate($value, array $options = null)
    {
        return is_string($value) ? true : 'Value must be a string';
    }

    /**
     * {@inheritdoc}
     * @codeCoverageIgnore
     */
    public static function getDefaultOption()
    {
        return null;
    }
}
