<?php

namespace Guzzle\Common\Validation;

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
        if (!is_numeric($value)) {
            return "Value must be numeric";
        }

        return true;
    }
}
