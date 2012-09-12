<?php

namespace Guzzle\Validation;

/**
 * Ensures that a value is numeric
 */
class Numeric implements ConstraintInterface
{
    /**
     * {@inheritdoc}
     */
    public function validate($value, array $options = null)
    {
        return is_numeric($value) ? true: 'Value must be numeric';
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
